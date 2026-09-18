<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CommentVisibility;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\Comment;
use App\Models\CommentThread;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Say something on a goal, claim, decision, commitment or evidence item.
 *
 * The most useful thing an agent can do and the least dangerous, which is why
 * it is the first tool: an agent that notices a contradiction between two
 * uploads can now raise it where the work is, instead of burying it in a brief
 * nobody opens.
 *
 * Two deliberate limits.
 *
 * An agent's comment defaults to its own party's visibility, matching the rule
 * for people (spec §20.3). An agent that reasons out loud in front of the
 * counterparty on its owner's behalf is exactly the failure that stops a
 * contractor from ever admitting one.
 *
 * Mentions in an agent's comment are recorded like anyone else's, so the one
 * push signal in the product works the same whoever pulled it — but the agent
 * cannot mark its own comment for the record. What belongs in the packet is a
 * human's judgement.
 */
class PostCommentTool extends BaseTool
{
    public function __construct(private readonly AuditChain $audit) {}

    public function key(): string
    {
        return 'post_comment';
    }

    public function name(): string
    {
        return 'Post a comment';
    }

    public function description(): string
    {
        return 'Add a comment to the discussion on a goal, claim, decision, commitment or evidence item. '
            . 'Use it to raise a contradiction, ask for a missing input, or flag something that needs a person.';
    }

    public function sideEffect(): SideEffect
    {
        return SideEffect::CircleWrite;
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['subject_type', 'subject_id', 'body'],
            'properties'           => [
                'subject_type' => [
                    'type' => 'string',
                    'enum' => ['goal', 'claim', 'decision', 'commitment', 'evidence_item'],
                ],
                'subject_id' => ['type' => 'string'],
                'body'       => [
                    'type'        => 'string',
                    'description' => 'What to say. Mention a person with @their.name to notify them.',
                ],
                'visibility' => [
                    'type'        => 'string',
                    'enum'        => ['party', 'circle'],
                    'description' => 'Defaults to party — visible only to the organisation this agent acts for.',
                ],
            ],
        ];
    }

    public function handle(AgentAction $action): array
    {
        $circle      = $this->circle($action);
        $agent       = $this->agent($action);
        $subjectType = (string) $this->requireArg($action, 'subject_type');
        $subjectId   = (string) $this->requireArg($action, 'subject_id');
        $body        = (string) $this->requireArg($action, 'body');

        $party = $action->onBehalfOfParty;

        $visibility = $this->arg($action, 'visibility') === 'circle'
            ? CommentVisibility::Circle
            : CommentVisibility::Party;

        // An agent with no party cannot post privately to one; falling back to
        // Circle visibility is the honest reading, and a party-scoped thread
        // with no party would be readable by nobody anyway.
        if ($visibility === CommentVisibility::Party && $party === null) {
            $visibility = CommentVisibility::Circle;
        }

        return DB::transaction(function () use (
            $circle, $agent, $action, $subjectType, $subjectId, $body, $visibility, $party
        ) {
            $thread = CommentThread::where('circle_id', $circle->id)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('visibility', $visibility->value)
                ->where('visible_to_party_id', $party?->id)
                ->where('status', 'open')
                ->latest('last_activity_at')
                ->first();

            $opened = false;

            if ($thread === null) {
                $thread = CommentThread::create([
                    'circle_id'           => $circle->id,
                    'subject_type'        => $subjectType,
                    'subject_id'          => $subjectId,
                    'visibility'          => $visibility,
                    'visible_to_party_id' => $party?->id,
                    'created_by_type'     => 'agent',
                    'created_by_id'       => $agent->id,
                    'last_activity_at'    => now(),
                ]);

                $opened = true;

                $this->audit->record(
                    AuditEventType::CommentThreadOpened, $circle, ActorType::Agent, $agent->id,
                    $subjectType, $subjectId, metadata: [
                        'thread_id'  => $thread->id,
                        'visibility' => $visibility->value,
                        'party'      => $party?->label(),
                    ],
                );
            }

            $comment = Comment::create([
                'comment_thread_id' => $thread->id,
                'circle_id'         => $circle->id,
                'author_type'       => 'agent',
                'author_id'         => $agent->id,
                'author_party_id'   => $party?->id,
                'body'              => $body,
                // Never true for an agent. Whether something belongs in the
                // permanent record is a judgement, and judgements are human.
                'for_the_record'    => false,
                'action_type'       => 'agent_action',
                'action_id'         => $action->id,
            ]);

            $thread->update(['last_activity_at' => now()]);

            $this->audit->record(
                AuditEventType::CommentPosted, $circle, ActorType::Agent, $agent->id,
                $subjectType, $subjectId, metadata: [
                    'thread_id'    => $thread->id,
                    'comment_id'   => $comment->id,
                    'agent_action' => $action->id,
                    'approved_by'  => $action->approved_by_user_id,
                ],
            );

            return [
                'thread_id'     => $thread->id,
                'comment_id'    => $comment->id,
                'thread_opened' => $opened,
                'visibility'    => $visibility->value,
            ];
        });
    }
}
