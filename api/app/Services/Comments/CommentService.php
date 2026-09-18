<?php

namespace App\Services\Comments;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CommentVisibility;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Comment;
use App\Models\CommentMention;
use App\Models\CommentThread;
use App\Models\User;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Conversation attached to objects (spec §20.3).
 *
 * The reason this class is careful about visibility rather than treating it as
 * a display flag: a party-scoped thread that leaks once is a thread nobody uses
 * again. Readability is resolved here, in one place, and the controller asks
 * this class rather than filtering in the query.
 */
class CommentService
{
    public function __construct(
        private readonly AuditChain $audit,
        private readonly \App\Services\Notifications\Notifier $notifier,
    ) {}

    /**
     * Threads on one object that this member may actually read.
     *
     * Filtered in PHP through CommentThread::isReadableBy rather than in SQL so
     * that the rule has exactly one definition. The volume per object is small.
     */
    public function threadsFor(Circle $circle, CircleMembership $membership, string $subjectType, string $subjectId)
    {
        return CommentThread::where('circle_id', $circle->id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with(['comments.mentions.user', 'visibleToParty'])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (CommentThread $t) => $t->isReadableBy($membership))
            ->values();
    }

    /** Every thread in the Circle this member may read, newest activity first. */
    public function inbox(Circle $circle, CircleMembership $membership, int $limit = 50)
    {
        return CommentThread::where('circle_id', $circle->id)
            ->with(['comments' => fn ($q) => $q->latest()->limit(1), 'visibleToParty'])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (CommentThread $t) => $t->isReadableBy($membership))
            ->take($limit)
            ->values();
    }

    public function openThread(
        Circle $circle,
        User $author,
        string $subjectType,
        string $subjectId,
        string $body,
        CommentVisibility $visibility,
        ?CircleParty $party,
        bool $forTheRecord = false,
        ?string $title = null,
    ): CommentThread {
        // A party-scoped thread with no party would be readable by nobody, so
        // it is a validation error rather than a silently empty thread.
        abort_if(
            $visibility === CommentVisibility::Party && $party === null,
            422,
            'A party-scoped thread needs the party it belongs to.',
        );

        // A general thread has no subject to take a name from, and a general
        // room full of untitled threads is the undifferentiated log §20.3 was
        // right to refuse. The title is what makes it a table of contents.
        $general = $subjectType === CommentThread::SUBJECT_CIRCLE;

        abort_if(
            $general && trim((string) $title) === '',
            422,
            'A discussion about the Circle needs a subject line.',
        );

        // The Circle is its own subject, so subject_id is never null and every
        // query that already reads these columns keeps working untouched.
        if ($general) {
            $subjectId = $circle->id;
        }

        return DB::transaction(function () use (
            $circle, $author, $subjectType, $subjectId, $body, $visibility, $party, $forTheRecord, $title, $general
        ) {
            $thread = CommentThread::create([
                'circle_id'           => $circle->id,
                'subject_type'        => $subjectType,
                'subject_id'          => $subjectId,
                'title'               => $general ? trim((string) $title) : null,
                'visibility'          => $visibility,
                'visible_to_party_id' => $party?->id,
                'created_by_type'     => 'user',
                'created_by_id'       => $author->id,
                'last_activity_at'    => now(),
            ]);

            $this->audit->record(
                AuditEventType::CommentThreadOpened,
                $circle,
                ActorType::User,
                $author->id,
                $subjectType,
                $subjectId,
                metadata: array_filter([
                    'thread_id'  => $thread->id,
                    'visibility' => $visibility->value,
                    'party'      => $party?->label(),
                    'title'      => $thread->title,
                ]),
            );

            $this->post($thread, $author, $body, $party, forTheRecord: $forTheRecord);

            return $thread->fresh(['comments.mentions.user', 'visibleToParty']);
        });
    }

    public function post(
        CommentThread $thread,
        User $author,
        string $body,
        ?CircleParty $party = null,
        ?string $actionType = null,
        ?string $actionId = null,
        bool $forTheRecord = false,
    ): Comment {
        $comment = DB::transaction(function () use (
            $thread, $author, $body, $party, $actionType, $actionId, $forTheRecord
        ) {
            $comment = Comment::create([
                'comment_thread_id' => $thread->id,
                'circle_id'         => $thread->circle_id,
                'author_type'       => 'user',
                'author_id'         => $author->id,
                'author_party_id'   => $party?->id,
                'body'              => $body,
                'for_the_record'    => $forTheRecord,
                'action_type'       => $actionType,
                'action_id'         => $actionId,
            ]);

            $thread->update(['last_activity_at' => now()]);

            $mentioned = $this->recordMentions($comment, $thread);

            $this->audit->record(
                AuditEventType::CommentPosted,
                $thread->circle,
                ActorType::User,
                $author->id,
                $thread->subject_type,
                $thread->subject_id,
                metadata: [
                    'thread_id'      => $thread->id,
                    'comment_id'     => $comment->id,
                    'for_the_record' => $forTheRecord,
                    'action_type'    => $actionType,
                    'mentioned'      => $mentioned,
                ],
            );

            return $comment->fresh('mentions.user');
        });

        // The mention was already recorded and surfaced in-app; this is the
        // half that was missing. Notifier stamps comment_mentions.notified_at
        // only for the people it actually reached, so the column means "was
        // told" rather than "was named".
        $this->notifier->mentioned($comment->setRelation('thread', $thread));

        return $comment;
    }

    /**
     * Parse @mentions and record them.
     *
     * Matched against the thread's actual readership, not the whole Circle:
     * mentioning someone into a party thread they cannot open would send them a
     * notification pointing at a 403.
     */
    private function recordMentions(Comment $comment, CommentThread $thread): array
    {
        if (! preg_match_all('/@([\w.\-]+)/', $comment->body, $matches)) {
            return [];
        }

        $handles = array_unique(array_map('strtolower', $matches[1]));

        $candidates = User::query()
            ->whereIn('id', $this->readerIds($thread))
            ->get()
            ->filter(function (User $u) use ($handles) {
                $handle = strtolower(explode('@', (string) $u->email)[0]);
                $first  = strtolower(explode(' ', (string) $u->name)[0]);

                return in_array($handle, $handles, true) || in_array($first, $handles, true);
            });

        foreach ($candidates as $user) {
            CommentMention::firstOrCreate([
                'comment_id'        => $comment->id,
                'mentioned_user_id' => $user->id,
            ]);
        }

        return $candidates->pluck('name')->all();
    }

    /**
     * People a mention would actually reach, for the composer's picker.
     *
     * Resolved from the same readership the parser matches against, so the
     * picker can never offer a name that would then be dropped silently. The
     * handle is what gets typed: first names collide, email local parts do not.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function mentionable(Circle $circle, CommentVisibility $visibility, ?CircleParty $party)
    {
        $ids = $this->readerIdsFor($circle->id, $visibility, $party?->id);

        return CircleMembership::where('circle_id', $circle->id)
            ->whereIn('user_id', $ids)
            ->with(['user', 'party'])
            ->get()
            ->filter(fn (CircleMembership $m) => $m->user !== null)
            ->unique('user_id')
            ->sortBy(fn (CircleMembership $m) => strtolower((string) $m->user->name))
            ->map(fn (CircleMembership $m) => [
                'id'     => $m->user_id,
                'name'   => $m->user->name,
                'handle' => strtolower(explode('@', (string) $m->user->email)[0]),
                'party'  => $m->party?->label(),
            ])
            ->values();
    }

    /** @return array<int, string> user ids who can read this thread */
    private function readerIds(CommentThread $thread): array
    {
        return $this->readerIdsFor($thread->circle_id, $thread->visibility, $thread->visible_to_party_id);
    }

    /**
     * Readership of a thread that may not exist yet.
     *
     * Split out from readerIds so the composer can ask who a thread it is
     * about to open would reach, without the picker growing its own idea of
     * who can read what.
     *
     * @return array<int, string>
     */
    private function readerIdsFor(string $circleId, CommentVisibility $visibility, ?string $partyId): array
    {
        $query = CircleMembership::where('circle_id', $circleId)
            ->whereNull('revoked_at')
            ->where('invite_status', 'active');

        if ($visibility === CommentVisibility::Party) {
            // Members with no party row sit with the convener, the same
            // fallback CircleMembership::effectivePartyId applies. Without this
            // branch a party thread in a Circle whose memberships predate
            // parties has no readers at all, and mentions in it vanish
            // silently — which is worse than refusing them.
            $conveningPartyId = CircleParty::convenerIdFor($circleId);

            $query->where(function ($q) use ($partyId, $conveningPartyId) {
                $q->where('circle_party_id', $partyId);

                if ($conveningPartyId !== null && $partyId === $conveningPartyId) {
                    $q->orWhereNull('circle_party_id');
                }
            });
        }

        return $query->pluck('user_id')->all();
    }

    /** Promote a comment into the export packet after the fact. */
    public function markForRecord(Comment $comment, User $actor): Comment
    {
        if ($comment->for_the_record) {
            return $comment;
        }

        return DB::transaction(function () use ($comment, $actor) {
            $comment->update(['for_the_record' => true]);

            $this->audit->record(
                AuditEventType::CommentMarkedRecord,
                $comment->thread->circle,
                ActorType::User,
                $actor->id,
                $comment->thread->subject_type,
                $comment->thread->subject_id,
                metadata: ['comment_id' => $comment->id],
            );

            return $comment->fresh();
        });
    }

    /**
     * Widen a party thread to the whole Circle.
     *
     * One-way on purpose. Narrowing it again would be a promise the product
     * cannot keep — the other parties have already read it.
     */
    public function share(CommentThread $thread, User $actor): CommentThread
    {
        abort_if($thread->visibility === CommentVisibility::Circle, 422, 'This thread is already shared.');

        return DB::transaction(function () use ($thread, $actor) {
            $thread->update([
                'visibility'          => CommentVisibility::Circle,
                'visible_to_party_id' => null,
            ]);

            $this->audit->record(
                AuditEventType::CommentThreadShared,
                $thread->circle,
                ActorType::User,
                $actor->id,
                $thread->subject_type,
                $thread->subject_id,
                metadata: ['thread_id' => $thread->id],
            );

            return $thread->fresh();
        });
    }

    /**
     * Move a general thread onto the object it turned out to be about.
     *
     * This is the whole answer to §20.3. That section refused a Circle-wide
     * channel because substance migrates into it and the structured record
     * decays — and it was right that substance migrates. What it could not do
     * was stop people talking; it could only stop them talking *here*, which
     * sent the conversation to email and lost it entirely.
     *
     * So drift is allowed and made reversible. A question asked in the general
     * room before anyone had drawn the goal can be moved onto that goal the
     * moment it exists, and the whole conversation — every comment, every
     * mention, every on-record marking — goes with it, because none of that
     * lives on the subject. The record stops decaying not because nobody
     * drifted but because somebody could tidy up afterwards in one action.
     *
     * One direction only. A thread already about a decision is not general, and
     * moving it back would be the decay this is meant to undo.
     */
    public function attachTo(
        CommentThread $thread,
        User $actor,
        string $subjectType,
        string $subjectId,
    ): CommentThread {
        abort_unless(
            $thread->isGeneral(),
            422,
            'Only a general discussion can be attached. This thread is already about a ' . $thread->subject_type . '.',
        );

        abort_unless(
            in_array($subjectType, CommentThread::ATTACHABLE, true),
            422,
            'A thread can be attached to a goal, claim, decision, commitment or evidence item.',
        );

        $subject = $this->resolveSubject($thread->circle, $subjectType, $subjectId);

        abort_if($subject === null, 404, 'That object is not in this Circle.');

        $from = $thread->title;

        return DB::transaction(function () use ($thread, $actor, $subjectType, $subjectId, $from) {
            $thread->update([
                'subject_type'        => $subjectType,
                'subject_id'          => $subjectId,
                // The title goes: the subject now names the thread, and two
                // sources of truth for what a conversation is called is how
                // they end up disagreeing.
                'title'               => null,
                'attached_at'         => now(),
                'attached_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                AuditEventType::CommentThreadAttached,
                $thread->circle,
                ActorType::User,
                $actor->id,
                $subjectType,
                $subjectId,
                metadata: array_filter([
                    'thread_id'   => $thread->id,
                    'from'        => 'circle',
                    'was_titled'  => $from,
                    'comments'    => $thread->comments()->count(),
                ]),
            );

            return $thread->fresh(['comments.mentions.user', 'visibleToParty', 'attachedBy']);
        });
    }

    /**
     * Confirm an object exists and belongs to this Circle.
     *
     * Checked rather than trusted: a thread carrying a subject_id from another
     * Circle would be readable through the Circle it sits in and would name an
     * object nobody there can see.
     */
    private function resolveSubject(Circle $circle, string $subjectType, string $subjectId): ?object
    {
        $model = match ($subjectType) {
            'goal'          => \App\Models\Goal::class,
            'claim'         => \App\Models\Claim::class,
            'decision'      => \App\Models\Decision::class,
            'commitment'    => \App\Models\Commitment::class,
            'evidence_item' => \App\Models\EvidenceItem::class,
            default         => null,
        };

        if ($model === null) {
            return null;
        }

        // Evidence reaches its Circle through its resource; everything else
        // carries circle_id directly.
        if ($subjectType === 'evidence_item') {
            return \App\Models\EvidenceItem::where('id', $subjectId)
                ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
                ->first();
        }

        return $model::where('id', $subjectId)->where('circle_id', $circle->id)->first();
    }

    public function resolve(CommentThread $thread, User $actor): CommentThread
    {
        $thread->update([
            'status'              => 'resolved',
            'resolved_by_user_id' => $actor->id,
            'resolved_at'         => now(),
        ]);

        return $thread->fresh();
    }

    /** Unread mentions for one person across a Circle. */
    public function unreadMentions(Circle $circle, User $user)
    {
        return CommentMention::query()
            ->where('mentioned_user_id', $user->id)
            ->whereNull('read_at')
            ->whereHas('comment', fn ($q) => $q->where('circle_id', $circle->id))
            ->with(['comment.thread'])
            ->latest()
            ->get();
    }

    public function markMentionsRead(Circle $circle, User $user): int
    {
        return CommentMention::query()
            ->where('mentioned_user_id', $user->id)
            ->whereNull('read_at')
            ->whereHas('comment', fn ($q) => $q->where('circle_id', $circle->id))
            ->update(['read_at' => now()]);
    }
}
