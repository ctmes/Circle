<?php

namespace App\Models;

use App\Enums\CommitmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commitment extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'title', 'description', 'acceptance_condition', 'status',
        'owner_user_id', 'created_by_user_id', 'created_by_type',
        'agent_run_id', 'due_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => CommitmentStatus::class,
            'due_at'       => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CommitmentUpdate::class);
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! in_array($this->status, [CommitmentStatus::Done, CommitmentStatus::Cancelled], true);
    }
}
