<?php

namespace App\Models;

use App\Enums\CircleRole;
use App\Enums\SideEffect;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One command an agent may run.
 *
 * The blueprint declares these; the service layer may tighten a tool's approval
 * requirements but never loosen them below what the side effect demands. That
 * asymmetry is the whole point — an agent author cannot mark their own
 * external-write tool as needing nobody.
 */
class AgentTool extends Model
{
    use HasUlids;

    /**
     * Matches the column defaults, so a tool built in memory behaves the same
     * as one read back from the database. Without this, `enabled` is null on a
     * freshly created instance and the tool reads as disabled.
     */
    protected $attributes = [
        'side_effect'           => 'none',
        'requires_approval'     => true,
        'requires_owning_party' => true,
        'enabled'               => true,
    ];

    protected $fillable = [
        'agent_blueprint_id', 'key', 'name', 'description', 'side_effect',
        'requires_approval', 'approval_role', 'requires_owning_party',
        'input_schema_json', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'side_effect'           => SideEffect::class,
            'requires_approval'     => 'boolean',
            'requires_owning_party' => 'boolean',
            'input_schema_json'     => 'array',
            'enabled'               => 'boolean',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AgentBlueprint::class, 'agent_blueprint_id');
    }

    /**
     * Whether this call needs a human, taking the stricter of what the tool
     * declares and what its side effect requires.
     */
    public function needsApproval(): bool
    {
        return $this->requires_approval || $this->side_effect->requiresApprovalByDefault();
    }

    /** The role that must sign off — never weaker than the side effect's floor. */
    public function effectiveApprovalRole(): ?CircleRole
    {
        $floor = $this->side_effect->minimumApprovalRole();

        if ($this->approval_role === null) {
            return $floor;
        }

        $declared = CircleRole::tryFrom($this->approval_role);

        if ($declared === null || $floor === null) {
            return $floor ?? $declared;
        }

        // Owner outranks everything; otherwise the floor wins over a weaker
        // declaration. A blueprint may raise the bar, never lower it.
        return $declared === CircleRole::Owner ? $declared : $floor;
    }

    /** Whether the approver must sit inside the party bearing the consequence. */
    public function needsOwningPartyApprover(): bool
    {
        return $this->requires_owning_party || $this->side_effect->requiresOwningParty();
    }
}
