<?php

namespace App\Models;

use App\Enums\CommentVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation about one object, or about the mission itself.
 *
 * Threads hang off goals, claims, decisions, commitments and evidence items.
 * They may also hang off the Circle, which §20.3 refused on the grounds that
 * once a general room exists the substance migrates into it and the structured
 * record decays into something somebody updates out of duty.
 *
 * That reasoning is right about what a general room does and wrong about what
 * refusing one achieves. Work starts before there is anything to attach a
 * question to, and a product with nowhere to ask it does not stop the asking —
 * it moves the asking to email, where the answer follows, and the record §20.3
 * was protecting is lost to somewhere with no visibility rules at all.
 *
 * The room therefore exists and the objection is answered instead of overruled:
 * a circle-scoped thread must carry a `title`, so the general room is a list of
 * named topics rather than one undifferentiated log; and `attachTo()` moves a
 * thread onto an object once it turns out to be about one, recording who moved
 * it. Drift is not prevented here. It is made recoverable in one action, which
 * is the most an interface can honestly promise about where people talk.
 */
class CommentThread extends Model
{
    use HasUlids;

    /** The Circle itself, for a conversation not yet about any one object. */
    public const SUBJECT_CIRCLE = 'circle';

    /** Objects a thread may attach to. */
    public const SUBJECTS = [
        self::SUBJECT_CIRCLE, 'goal', 'claim', 'decision', 'commitment', 'evidence_item',
    ];

    /** The subjects a general thread may be moved onto. Never back to a Circle. */
    public const ATTACHABLE = ['goal', 'claim', 'decision', 'commitment', 'evidence_item'];

    protected $fillable = [
        'circle_id', 'subject_type', 'subject_id', 'title', 'visibility',
        'visible_to_party_id', 'status', 'resolved_by_user_id', 'resolved_at',
        'created_by_type', 'created_by_id', 'last_activity_at',
        'attached_at', 'attached_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'visibility'       => CommentVisibility::class,
            'resolved_at'      => 'datetime',
            'last_activity_at' => 'datetime',
            'attached_at'      => 'datetime',
        ];
    }

    /** A conversation about the mission rather than about one of its objects. */
    public function isGeneral(): bool
    {
        return $this->subject_type === self::SUBJECT_CIRCLE;
    }

    /** Whether this thread was moved here from the general room. */
    public function wasAttached(): bool
    {
        return $this->attached_at !== null;
    }

    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
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
