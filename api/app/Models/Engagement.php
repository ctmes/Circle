<?php

namespace App\Models;

use App\Enums\EngagementStatus;
use App\Enums\FeeBasis;
use App\Enums\PrincipalType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The temp contract (spec §21.2).
 *
 * More than a record because `circle_memberships.engagement_id` points back at
 * it and AccessGate reads it as check (7). An engagement that has not started,
 * has ended, or has been suspended denies every write by the seat it issued —
 * and where it names a scope, writes are confined to that subtree.
 *
 * It can only ever narrow. Nothing here grants a permission the role did not
 * already carry, which is why the check runs last.
 */
class Engagement extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'engaging_party_id', 'contractor_party_id',
        'principal_type', 'principal_id', 'work_opening_id',
        'agent_blueprint_version_id', 'title', 'terms', 'scope_goal_id',
        'status', 'fee_basis', 'fee_amount_minor', 'currency', 'unit_cap',
        'starts_at', 'ends_at', 'agreed_via_decision_id', 'agreed_at',
        'activated_at', 'suspended_at', 'ended_at', 'end_reason',
        'ended_by_party_id', 'ended_by_user_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'principal_type'   => PrincipalType::class,
            'status'           => EngagementStatus::class,
            'fee_basis'        => FeeBasis::class,
            'fee_amount_minor' => 'integer',
            'unit_cap'         => 'integer',
            'starts_at'        => 'datetime',
            'ends_at'          => 'datetime',
            'agreed_at'        => 'datetime',
            'activated_at'     => 'datetime',
            'suspended_at'     => 'datetime',
            'ended_at'         => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function engagingParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'engaging_party_id');
    }

    public function contractorParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'contractor_party_id');
    }

    public function scopeGoal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'scope_goal_id');
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(WorkOpening::class, 'work_opening_id');
    }

    public function blueprintVersion(): BelongsTo
    {
        return $this->belongsTo(AgentBlueprintVersion::class, 'agent_blueprint_version_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'agreed_via_decision_id');
    }

    /**
     * Which side ended it, where it was ended rather than run out.
     *
     * "The client cut it short" and "the contractor walked" are the two facts
     * a record most needs to tell apart, and neither is recoverable from a
     * timestamp.
     */
    public function endedByParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'ended_by_party_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CircleMembership::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    public function meterEntries(): HasMany
    {
        return $this->hasMany(EngagementMeterEntry::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(WorkRecord::class);
    }

    /**
     * The person or agent instance engaged.
     *
     * Resolved by hand rather than through a morphTo, because PrincipalType is
     * the shared vocabulary with `work_records` and an Eloquent morph map would
     * make the two drift.
     */
    public function principal(): User|AgentInstance|null
    {
        return match ($this->principal_type) {
            PrincipalType::User          => User::find($this->principal_id),
            PrincipalType::AgentInstance => AgentInstance::find($this->principal_id),
            default                      => null,
        };
    }

    /**
     * The blueprint a record should be kept against.
     *
     * An engagement names an instance, because that is the identity the gate
     * authorises. A record names the blueprint behind it, because an instance
     * dies with its Circle and the record must not. This is the walk between
     * the two, and the only place it happens.
     */
    public function recordPrincipal(): array
    {
        if ($this->principal_type === PrincipalType::User) {
            return [PrincipalType::User, $this->principal_id];
        }

        $instance = AgentInstance::find($this->principal_id);

        return [PrincipalType::AgentBlueprint, $instance?->agent_blueprint_id];
    }

    public function principalName(): string
    {
        $principal = $this->principal();

        return match (true) {
            $principal instanceof User          => $principal->name,
            $principal instanceof AgentInstance => $this->blueprintVersion?->name
                ?? $principal->blueprint?->name
                ?? 'an agent',
            default                             => 'unknown',
        };
    }

    /**
     * Whether the contract itself permits work right now.
     *
     * Status *and* term. A row left at `active` past its end date is the
     * common case rather than an edge one — nothing sweeps the moment a clock
     * ticks over — so the gate has to answer from the dates rather than trust
     * a status somebody was supposed to change.
     */
    public function permitsWork(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if (! $this->status->permitsWork()) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isAfter($at)) {
            return false;
        }

        return $this->ends_at === null || ! $this->ends_at->isBefore($at);
    }

    /** Why work is refused, in the words the gate will use. */
    public function refusalReason(): ?string
    {
        if ($this->status === EngagementStatus::Proposed) {
            return 'This engagement has not been agreed yet.';
        }

        if ($this->status === EngagementStatus::Suspended) {
            return 'This engagement is suspended.';
        }

        if ($this->status->isEnded()) {
            return sprintf('This engagement %s on %s.',
                $this->status->label(),
                $this->ended_at?->toFormattedDateString() ?? $this->ends_at?->toFormattedDateString() ?? 'an earlier date',
            );
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return sprintf('This engagement starts on %s.', $this->starts_at->toFormattedDateString());
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return sprintf('This engagement ran to %s.', $this->ends_at->toFormattedDateString());
        }

        return null;
    }

    /**
     * Whether a goal falls inside this engagement's scope.
     *
     * Walks up rather than down: a scope names one node and means it and
     * everything beneath it, so the question "is this goal in scope" is
     * answered by looking for the scope among the goal's ancestors. Walking
     * down would mean loading a subtree on every authorisation check.
     *
     * A null scope covers the whole Circle, which is the honest default for a
     * contractor engaged broadly. A scope that was set and whose goal has since
     * been deleted covers nothing — see scope_goal_id's nullOnDelete in the
     * migration: widening on deletion would silently hand a confined
     * contractor the run of the Circle.
     */
    public function coversGoal(?Goal $goal): bool
    {
        if ($this->scope_goal_id === null) {
            return true;
        }

        if ($goal === null) {
            return false;
        }

        $current = $goal;
        $guard   = 0;

        while ($current !== null && $guard < 16) {
            if ($current->id === $this->scope_goal_id) {
                return true;
            }

            $current = $current->parent_goal_id === null ? null : Goal::find($current->parent_goal_id);
            $guard++;
        }

        return false;
    }

    /** Whether a scope was named and the goal it named is gone. */
    public function hasDanglingScope(): bool
    {
        return $this->scope_goal_id !== null && Goal::find($this->scope_goal_id) === null;
    }

    /** Metered units used so far, in whole units. */
    public function unitsUsed(): float
    {
        return round($this->meterEntries()->sum('quantity_milli') / 1000, 3);
    }

    public function isOverCap(): bool
    {
        return $this->unit_cap !== null && $this->unitsUsed() >= $this->unit_cap;
    }
}
