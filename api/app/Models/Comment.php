<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing someone said.
 *
 * A comment may carry an action — the reviewer's note, the approver's reason,
 * the reason a commitment blocked — or carry nothing at all. Before this table
 * existed only the first kind was possible, which is why the real conversation
 * happened somewhere else.
 */
class Comment extends Model
{
    use HasUlids;

    /** Actions a comment can carry, matching the levers that used to own them. */
    public const ACTIONS = [
        'claim_review', 'decision_resolution', 'commitment_update',
        'goal_acceptance', 'schedule_change', 'agent_action_review',
    ];

    protected $fillable = [
        'comment_thread_id', 'circle_id', 'author_type', 'author_id',
        'author_party_id', 'body', 'for_the_record', 'action_type', 'action_id',
        'edited_at', 'deleted_by_user_id', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'for_the_record' => 'boolean',
            'edited_at'      => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommentThread::class, 'comment_thread_id');
    }

    public function authorParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'author_party_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class);
    }

    public function carriesAction(): bool
    {
        return $this->action_type !== null;
    }

    /**
     * Whether this comment belongs in the export packet.
     *
     * A comment that carried a state change is part of the decision record and
     * is always included. Plain discussion is excluded unless someone marked it
     * for the record — if every aside were discoverable in a dispute, people
     * would stop speaking candidly and the feature would be worth nothing.
     */
    public function isOnRecord(): bool
    {
        return $this->carriesAction() || $this->for_the_record;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
