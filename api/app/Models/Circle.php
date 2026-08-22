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
        'organisation_id', 'name', 'purpose', 'status',
        'owner_user_id', 'starts_at', 'expires_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'     => CircleStatus::class,
            'starts_at'  => 'datetime',
            'expires_at' => 'datetime',
            'closed_at'  => 'datetime',
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
