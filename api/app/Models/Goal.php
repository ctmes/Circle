<?php

namespace App\Models;

use App\Enums\GoalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A goal or sub-goal — the node everything else hangs off.
 *
 * The difference between the two is depth, not type: a sub-goal is a goal with
 * a parent. One table means a sub-goal carries its own acceptance condition and
 * its own responsible party, which is what actually happens when a piece of
 * work is subcontracted a level down.
 */
class Goal extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'parent_goal_id', 'title', 'description', 'status',
        'owner_user_id', 'responsible_party_id', 'acceptance_condition',
        'accepted_by_user_id', 'accepted_at', 'accepted_via_decision_id',
        'starts_at', 'due_at', 'progress', 'progress_set_by_user_id',
        'progress_set_at', 'position', 'created_by_type', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status'          => GoalStatus::class,
            'progress'        => 'integer',
            'position'        => 'integer',
            'accepted_at'     => 'datetime',
            'progress_set_at' => 'datetime',
            'starts_at'       => 'datetime',
            'due_at'          => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'parent_goal_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Goal::class, 'parent_goal_id')->orderBy('position');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function responsibleParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'responsible_party_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * The files filed against this piece of work.
     *
     * Distinct from the evidence a claim on this goal cites. A citation says
     * "this sentence rests on that document"; this says "this document belongs
     * with this job", which is the ordinary filing everybody does and which
     * previously had nowhere to go but the Circle-wide vault.
     *
     * Visibility still belongs to the item, never to the link: a party-scoped
     * document attached to a goal stays invisible to the parties it was scoped
     * away from, so every read of this relation is filtered the same way the
     * register is.
     */
    public function evidence(): BelongsToMany
    {
        return $this->belongsToMany(EvidenceItem::class, 'evidence_item_goal')
            ->withPivot(['id', 'attached_by_user_id', 'attached_at'])
            ->orderByPivot('attached_at', 'desc');
    }

    public function scheduleChanges(): HasMany
    {
        return $this->hasMany(GoalScheduleChange::class)->latest();
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && $this->status->isOpen();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Progress as reported, not as measured.
     *
     * A leaf carries whatever its owner set. A parent ignores its own number
     * and averages its children, because a parent claiming 80% while its
     * sub-goals sit at 20% is the exact failure a status field should prevent.
     */
    public function effectiveProgress(): int
    {
        $children = $this->relationLoaded('children') ? $this->children : $this->children()->get();

        if ($children->isEmpty()) {
            return $this->status === GoalStatus::Met ? 100 : (int) $this->progress;
        }

        return (int) round($children->avg(fn (Goal $g) => $g->effectiveProgress()));
    }
}
