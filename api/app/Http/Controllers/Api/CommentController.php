<?php

namespace App\Http\Controllers\Api;

use App\Enums\CommentVisibility;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Comment;
use App\Models\CommentThread;
use App\Services\Authorisation\AccessGate;
use App\Services\Comments\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Threads on objects (spec §20.3).
 *
 * Every read goes through CommentService, which resolves readability against
 * the caller's party. The controller never filters threads itself — one
 * definition of who can see what, or the rule will drift.
 */
class CommentController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly CommentService $comments,
    ) {}

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $data = $request->validate([
            'subject_type' => ['required', 'string', 'in:' . implode(',', CommentThread::SUBJECTS)],
            'subject_id'   => ['required', 'string'],
        ]);

        $threads = $this->comments->threadsFor(
            $circle,
            $this->membership($circle, $request),
            $data['subject_type'],
            $data['subject_id'],
        );

        return response()->json(['data' => $threads->map(fn ($t) => $this->present($t))->all()]);
    }

    /** Everything the caller can read in this Circle, newest activity first. */
    public function inbox(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $threads = $this->comments->inbox($circle, $this->membership($circle, $request));

        return response()->json([
            'data'     => $threads->map(fn ($t) => $this->present($t, withComments: false))->all(),
            'mentions' => $this->comments
                ->unreadMentions($circle, $request->user())
                ->map(fn ($m) => [
                    'id'         => $m->id,
                    'comment_id' => $m->comment_id,
                    'thread_id'  => $m->comment->comment_thread_id,
                    'subject'    => [
                        'type' => $m->comment->thread->subject_type,
                        'id'   => $m->comment->thread->subject_id,
                    ],
                    'excerpt'    => mb_strimwidth($m->comment->body, 0, 140, '…'),
                    'created_at' => $m->created_at?->toISOString(),
                ])->all(),
        ]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CommentCreate, $circle);

        $data = $request->validate([
            'subject_type'   => ['required', 'string', 'in:' . implode(',', CommentThread::SUBJECTS)],
            'subject_id'     => ['required', 'string'],
            'body'           => ['required', 'string', 'max:10000'],
            'visibility'     => ['nullable', 'string', 'in:circle,party'],
            'for_the_record' => ['nullable', 'boolean'],
        ]);

        $membership = $this->membership($circle, $request);

        // Party is the default, and it is taken from the author's own
        // membership rather than the request — nobody opens a thread inside
        // another company's private space.
        $visibility = CommentVisibility::from($data['visibility'] ?? 'party');
        $party      = $visibility === CommentVisibility::Party
            ? $this->partyFor($membership)
            : null;

        $thread = $this->comments->openThread(
            circle: $circle,
            author: $request->user(),
            subjectType: $data['subject_type'],
            subjectId: $data['subject_id'],
            body: $data['body'],
            visibility: $visibility,
            party: $party,
            forTheRecord: (bool) ($data['for_the_record'] ?? false),
        );

        return response()->json(['data' => $this->present($thread)], 201);
    }

    public function reply(Request $request, CommentThread $thread): JsonResponse
    {
        $circle = $thread->circle;
        $this->gate->authorise($request->user(), Permission::CommentCreate, $circle);

        $membership = $this->membership($circle, $request);

        // Replying to a thread you cannot read is refused as not-found rather
        // than forbidden: confirming the thread exists is itself a leak.
        abort_unless($thread->isReadableBy($membership), 404);

        $data = $request->validate([
            'body'           => ['required', 'string', 'max:10000'],
            'for_the_record' => ['nullable', 'boolean'],
        ]);

        $comment = $this->comments->post(
            thread: $thread,
            author: $request->user(),
            body: $data['body'],
            party: $membership->party,
            forTheRecord: (bool) ($data['for_the_record'] ?? false),
        );

        return response()->json(['data' => $this->presentComment($comment)], 201);
    }

    public function markForRecord(Request $request, Comment $comment): JsonResponse
    {
        $circle = $comment->thread->circle;
        $this->gate->authorise($request->user(), Permission::CommentCreate, $circle);

        abort_unless($comment->thread->isReadableBy($this->membership($circle, $request)), 404);

        return response()->json([
            'data' => $this->presentComment($this->comments->markForRecord($comment, $request->user())),
        ]);
    }

    public function share(Request $request, CommentThread $thread): JsonResponse
    {
        $circle = $thread->circle;
        $this->gate->authorise($request->user(), Permission::CommentCreate, $circle);

        $membership = $this->membership($circle, $request);
        abort_unless($thread->isReadableBy($membership), 404);

        return response()->json(['data' => $this->present($this->comments->share($thread, $request->user()))]);
    }

    public function resolve(Request $request, CommentThread $thread): JsonResponse
    {
        $circle = $thread->circle;
        $this->gate->authorise($request->user(), Permission::CommentCreate, $circle);

        abort_unless($thread->isReadableBy($this->membership($circle, $request)), 404);

        return response()->json(['data' => $this->present($this->comments->resolve($thread, $request->user()))]);
    }

    /**
     * Who the composer may offer for an @mention, before the thread exists.
     *
     * Scoped to the visibility being composed for: offering the whole Circle
     * inside a private thread would suggest a reach the thread does not have.
     */
    public function mentionable(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $data = $request->validate([
            'visibility' => ['nullable', 'string', 'in:circle,party'],
        ]);

        $membership = $this->membership($circle, $request);
        $visibility = CommentVisibility::from($data['visibility'] ?? 'party');
        $party      = $visibility === CommentVisibility::Party
            ? $this->resolveParty($membership)
            : null;

        // No party to own a private thread yet: nobody to offer, rather than
        // the 422 the write path owes the author. A picker does not argue.
        if ($visibility === CommentVisibility::Party && $party === null) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $this->comments->mentionable($circle, $visibility, $party)->all(),
        ]);
    }

    /** The same list for a thread that already exists, at its own visibility. */
    public function threadMentionable(Request $request, CommentThread $thread): JsonResponse
    {
        $circle = $thread->circle;
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        abort_unless($thread->isReadableBy($this->membership($circle, $request)), 404);

        return response()->json([
            'data' => $this->comments
                ->mentionable($circle, $thread->visibility, $thread->visibleToParty)
                ->all(),
        ]);
    }

    public function readMentions(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        return response()->json([
            'data' => ['marked_read' => $this->comments->markMentionsRead($circle, $request->user())],
        ]);
    }

    // ------------------------------------------------------------- internals

    private function membership(Circle $circle, Request $request): CircleMembership
    {
        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $request->user()->id)
            ->with('party')
            ->first();

        abort_if($membership === null, 403, 'You are not a member of this Circle.');

        return $membership;
    }

    /**
     * The party a private thread belongs to.
     *
     * A member with no party row sits with the convener by definition, so their
     * private threads belong to it. Falling back to a Circle-wide thread here
     * would quietly publish what the author expected to keep internal.
     */
    private function partyFor(CircleMembership $membership): CircleParty
    {
        $party = $this->resolveParty($membership);

        abort_if(
            $party === null,
            422,
            'This Circle has no parties yet, so a private thread has no owner. Share it with the Circle instead.',
        );

        return $party;
    }

    /** The same resolution without the refusal, for callers that may offer nothing. */
    private function resolveParty(CircleMembership $membership): ?CircleParty
    {
        if ($membership->party !== null) {
            return $membership->party;
        }

        return CircleParty::where('circle_id', $membership->circle_id)
            ->where('is_convener', true)
            ->first();
    }

    private function present(CommentThread $thread, bool $withComments = true): array
    {
        $payload = [
            'id'           => $thread->id,
            'subject'      => ['type' => $thread->subject_type, 'id' => $thread->subject_id],
            'visibility'   => $thread->visibility->value,
            'party'        => $thread->visibleToParty?->label(),
            'status'       => $thread->status,
            'is_resolved'  => $thread->isResolved(),
            'last_activity_at' => $thread->last_activity_at?->toISOString(),
            'created_at'   => $thread->created_at?->toISOString(),
            'comment_count' => $thread->comments()->count(),
        ];

        if ($withComments) {
            $payload['comments'] = $thread->comments
                ->map(fn (Comment $c) => $this->presentComment($c))
                ->all();
        } else {
            $latest = $thread->comments->first();
            $payload['latest'] = $latest === null ? null : $this->presentComment($latest);
        }

        return $payload;
    }

    private function presentComment(Comment $comment): array
    {
        $author = $comment->author_type === 'user'
            ? \App\Models\User::find($comment->author_id)?->name
            : null;

        return [
            'id'             => $comment->id,
            'thread_id'      => $comment->comment_thread_id,
            'author_type'    => $comment->author_type,
            'author'         => $author,
            'author_party'   => $comment->authorParty?->label(),
            'body'           => $comment->body,
            'for_the_record' => (bool) $comment->for_the_record,
            'on_record'      => $comment->isOnRecord(),
            'action_type'    => $comment->action_type,
            'mentions'       => $comment->mentions->map(fn ($m) => $m->user?->name)->filter()->values()->all(),
            'created_at'     => $comment->created_at?->toISOString(),
        ];
    }
}
