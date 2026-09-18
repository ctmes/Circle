<?php

namespace App\Models;

use App\Enums\DecisionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Decision extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'goal_id', 'title', 'description', 'status',
        'created_by_user_id', 'approver_user_id',
        'subject_type', 'subject_id', 'subject_version',
        'agent_run_id', 'expires_at', 'resolved_at', 'resolution_comment',
    ];

    protected function casts(): array
    {
        return [
            'status'      => DecisionStatus::class,
            'expires_at'  => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /** The node of the plan this is about. Null for a decision about the mission at large. */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DecisionApproval::class)->orderBy('occurred_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
