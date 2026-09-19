<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a due date.
 *
 * Its own table rather than an audit-log entry because in inter-company work a
 * slipped date is the most common thing anyone ends up arguing about, and the
 * answer needs to be a query rather than a grep.
 */
class GoalScheduleChange extends Model
{
    use HasUlids;

    protected $fillable = [
        'goal_id', 'circle_id', 'from_due_at', 'to_due_at', 'reason',
        'changed_by_user_id', 'requires_party_id', 'agreed_by_user_id', 'agreed_at',
        'changed_by_agent_instance_id',
    ];

    protected function casts(): array
    {
        return [
            'from_due_at' => 'datetime',
            'to_due_at'   => 'datetime',
            'agreed_at'   => 'datetime',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function requiresParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'requires_party_id');
    }

    /** The person who assented on the counterparty's behalf, where one did. */
    public function agreedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agreed_by_user_id');
    }

    /** A move that needed the counterparty and has not got it yet. */
    public function isAwaitingAgreement(): bool
    {
        return $this->requires_party_id !== null && $this->agreed_at === null;
    }

    public function daysMoved(): ?int
    {
        if ($this->from_due_at === null || $this->to_due_at === null) {
            return null;
        }

        return (int) $this->from_due_at->diffInDays($this->to_due_at, false);
    }
}
