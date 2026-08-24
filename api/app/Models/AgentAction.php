<?php

namespace App\Models;

use App\Enums\AgentActionStatus;
use App\Enums\SideEffect;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted agent action.
 *
 * The row is written at `proposed`, before anything is tried, so a refused or
 * failed action leaves exactly the same trail as a successful one — the same
 * reason `agent_runs` records its retrieval manifest before the model call.
 *
 * `tool_key` and `side_effect` are stored flat rather than read through the
 * tool relation, so the ledger still reads correctly years later after the
 * blueprint has been edited or the tool removed.
 */
class AgentAction extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'agent_instance_id', 'agent_run_id', 'agent_tool_id',
        'tool_key', 'side_effect', 'status', 'intent', 'arguments_json',
        'result_json', 'error', 'on_behalf_of_party_id', 'decision_id',
        'approved_by_user_id', 'approved_at', 'rejected_by_user_id',
        'rejected_at', 'rejection_reason', 'expires_at', 'executed_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status'         => AgentActionStatus::class,
            'side_effect'    => SideEffect::class,
            'arguments_json' => 'array',
            'result_json'    => 'array',
            'approved_at'    => 'datetime',
            'rejected_at'    => 'datetime',
            'expires_at'     => 'datetime',
            'executed_at'    => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function agentInstance(): BelongsTo
    {
        return $this->belongsTo(AgentInstance::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AgentTool::class, 'agent_tool_id');
    }

    public function onBehalfOfParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'on_behalf_of_party_id');
    }

    /** The decision that carried the sign-off, when one was required. */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast()
            && ! $this->status->isTerminal();
    }

    /**
     * Whether this action may run right now.
     *
     * Approval alone is not enough: an approval that has sat past its expiry is
     * no longer consent, in the same way an approval bound to a superseded
     * version stops applying to the new one.
     */
    public function isExecutable(): bool
    {
        return $this->status->canExecute() && ! $this->isExpired();
    }

    /** A one-line account of what this was, for the ledger and the packet. */
    public function describe(): string
    {
        return sprintf(
            '%s (%s) on behalf of %s',
            $this->tool_key,
            $this->side_effect->value,
            $this->onBehalfOfParty?->label() ?? 'no party',
        );
    }
}
