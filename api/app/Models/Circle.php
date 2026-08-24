<?php

namespace App\Models;

use App\Enums\CircleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Circle extends Model
{
    use HasUlids;

    protected $fillable = [
        'organisation_id', 'name', 'purpose', 'status', 'progress',
        'owner_user_id', 'starts_at', 'expires_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'          => CircleStatus::class,
            'progress'        => 'integer',
            'progress_set_at' => 'datetime',
            'starts_at'       => 'datetime',
            'expires_at'      => 'datetime',
            'closed_at'       => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CircleMembership::class);
    }

    /**
     * The organisations this Circle spans. `organisation_id` above is the
     * convener — the party that opened it and holds the closure right — not
     * the owner of everyone in it.
     */
    public function parties(): HasMany
    {
        return $this->hasMany(CircleParty::class);
    }

    /** Top-level goals only; sub-goals come through Goal::children(). */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class)->whereNull('parent_goal_id')->orderBy('position');
    }

    public function allGoals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function commentThreads(): HasMany
    {
        return $this->hasMany(CommentThread::class);
    }

    public function agentActions(): HasMany
    {
        return $this->hasMany(AgentAction::class);
    }

    public function agentConnections(): HasMany
    {
        return $this->hasMany(AgentConnection::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(CircleResource::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    public function isClosed(): bool
    {
        return $this->status->isClosed() || $this->closed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Contributions and agent runs require an active, unexpired Circle. */
    public function acceptsContributions(): bool
    {
        return $this->status->acceptsContributions() && ! $this->isExpired() && ! $this->isClosed();
    }
}
