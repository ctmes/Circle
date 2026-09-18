<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One edit somebody wants to make.
 *
 * Carries what it wants (`attributes_json`) and what it was looking at
 * (`base_json`). Comparing the second against the goal's current state is how a
 * merge tells "nobody has touched this" from "somebody moved it while you were
 * drafting" — the difference between applying an agreement and quietly
 * overwriting a colleague.
 */
class GoalChange extends Model
{
    use HasUlids;

    /** Fields a branch may propose changing. */
    public const EDITABLE = [
        'title',
        'description',
        'acceptance_condition',
        'owner_user_id',
        'responsible_party_id',
        'due_at',
        'starts_at',
        'status',
        'parent_goal_id',
    ];

    protected $fillable = [
        'goal_branch_id', 'change_type', 'goal_id', 'temp_key', 'parent_temp_key',
        'parent_goal_id', 'attributes_json', 'base_json', 'reason', 'position',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'attributes_json' => 'array',
            'base_json'       => 'array',
            'position'        => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(GoalBranch::class, 'goal_branch_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isAdd(): bool
    {
        return $this->change_type === 'add';
    }

    public function isUpdate(): bool
    {
        return $this->change_type === 'update';
    }

    public function isRemove(): bool
    {
        return $this->change_type === 'remove';
    }

    /** Whether this change moves a date, and so owes a reason. */
    public function movesTheDate(): bool
    {
        return $this->isUpdate() && array_key_exists('due_at', $this->attributes_json ?? []);
    }
}
