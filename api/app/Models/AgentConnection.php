<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A party bringing its own agent into a Circle.
 *
 * The contractor's agent runs under the contractor's party and the contractor's
 * credentials. Circle never holds the raw secret: `credential_ref` points at the
 * secret store, and `key_fingerprint` is the thing shown to the counterparty
 * when they are asked to admit this agent.
 *
 * Admission is a decision rather than a setting, because letting another
 * company's software read your Circle is exactly the kind of act the decision
 * record exists to capture.
 */
class AgentConnection extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'circle_party_id', 'agent_blueprint_id', 'name',
        'provider_label', 'auth_mode', 'endpoint_url', 'credential_ref',
        'key_fingerprint', 'status', 'admitted_via_decision_id',
        'admitted_by_user_id', 'admitted_at', 'revoked_at',
    ];

    /** Never serialise the pointer to the secret, even internally. */
    protected $hidden = ['credential_ref'];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by_user_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'circle_party_id');
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AgentBlueprint::class, 'agent_blueprint_id');
    }

    public function admittedViaDecision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'admitted_via_decision_id');
    }

    public function isAdmitted(): bool
    {
        return $this->admitted_at !== null && $this->revoked_at === null;
    }

    /**
     * The short fingerprint to show a counterparty being asked to admit this
     * agent. They are approving a specific key, not a name anyone can type.
     */
    public function shortFingerprint(): ?string
    {
        return $this->key_fingerprint === null
            ? null
            : substr($this->key_fingerprint, 0, 16);
    }
}
