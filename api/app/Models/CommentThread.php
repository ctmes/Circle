<?php

namespace App\Models;

use App\Enums\CommentVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation about one object.
 *
 * Threads hang off goals, claims, decisions, commitments and evidence items.
 * There is no Circle-wide channel by design: once a general room exists the
 * substance migrates into it, and the structured record degrades into something
 * someone updates afterwards out of duty.
 */
class CommentThread extends Model
{
    use HasUlids;

    /** Objects a thread may attach to. */
    public const SUBJECTS = ['goal', 'claim', 'decision', 'commitment', 'evidence_item'];

    protected $fillable = [
        'circle_id', 'subject_type', 'subject_id', 'visibility',
        'visible_to_party_id', 'status', 'resolved_by_user_id', 'resolved_at',
        'created_by_type', 'created_by_id', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'visibility'       => CommentVisibility::class,
            'resolved_at'      => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at');
    }

    public function visibleToParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'visible_to_party_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Whether a member may read this thread.
     *
     * Deliberately narrow: a party-scoped thread is invisible to everyone
     * outside that party, including the convener. A Circle where the convener
     * can read the contractor's working notes is one the contractor will not
     * use for working notes.
     */
    public function isReadableBy(CircleMembership $membership): bool
    {
        if ($this->visibility === CommentVisibility::Circle) {
            return true;
        }

        return $this->visible_to_party_id !== null
            && $this->visible_to_party_id === $membership->effectivePartyId();
    }
}
