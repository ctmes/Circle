<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A proposed revision of the plan (spec §20.7).
 *
 * Holds no goals. It holds the *difference* somebody wants to make, and stays
 * that way until every party it affects has agreed — at which point the changes
 * are applied to the real tree and the branch becomes a record of who signed.
 */
class GoalBranch extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'name', 'intent', 'circle_party_id', 'created_by_user_id',
        'status', 'proposed_at', 'decision_id', 'merged_by_user_id',
        'merged_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_at'  => 'datetime',
            'merged_at'    => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'circle_party_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by_user_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(GoalChange::class)->orderBy('position');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Open for review: proposed, not yet merged or withdrawn. */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isMerged(): bool
    {
        return $this->status === 'merged';
    }

    /** Whether the change-set can still be edited. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'open'], true);
    }
}
