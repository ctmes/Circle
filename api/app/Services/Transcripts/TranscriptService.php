<?php

namespace App\Services\Transcripts;

use App\Enums\ActorType;
use App\Enums\AgentActionStatus;
use App\Enums\ArtifactType;
use App\Enums\AuditEventType;
use App\Enums\Classification;
use App\Enums\Permission;
use App\Jobs\ProcessEvidenceVersion;
use App\Jobs\ProcessTranscriptImport;
use App\Models\AgentBlueprint;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\AgentTool;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\DerivedArtifact;
use App\Models\EvidenceItem;
use App\Models\Goal;
use App\Models\Organisation;
use App\Models\TranscriptImport;
use App\Models\User;
use App\Services\Agent\AgentActionService;
use App\Services\Agent\AgentExecutor;
use App\Services\Agent\AgentRetrieval;
use App\Services\Ai\AiProvider;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use App\Services\Circles\CircleService;
use App\Services\Convening\ConveningService;
use App\Services\Evidence\EvidenceService;
use App\Services\Evidence\EvidenceStorage;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Meeting transcripts in, a current plan out, and nobody in between (spec §24).
 *
 * The flow, end to end:
 *
 *   receive()   a transcript arrives for a company, from a person's connector
 *               or from somebody pasting it in. Recorded, deduplicated, queued.
 *   process()   routed to a Circle — an existing one, a new one, or none —
 *               filed there as ordinary evidence, and then either convened
 *               (a meeting that starts a piece of work) or read by the Scribe
 *               (a meeting about work already under way).
 *
 * Nothing waits for a person. That is the requirement, and it is met by
 * building on the one part of this product designed for agents that act: the
 * action ledger. Every change the Scribe makes is proposed, approved by policy
 * because the Scribe is autonomous and the change stays inside the Circle, and
 * executed — each step on the ledger with the operation's intent, its
 * arguments, a quotation from the meeting, and its result. Autonomy removed
 * the approval; it did not remove the record, and the record is how a person
 * who was not in the room finds out why a goal closed on Tuesday.
 *
 * Each stage checkpoints on the import row, so a failed job retried from the
 * log resumes where it stopped: a transcript is routed once and filed once, and
 * a Scribe run that completed is never run again.
 */
class TranscriptService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly AiProvider $ai,
        private readonly AgentRetrieval $retrieval,
        private readonly AgentActionService $actions,
        private readonly AgentExecutor $executor,
        private readonly TranscriptRouter $router,
        private readonly TranscriptPrompt $prompt,
        private readonly CircleService $circles,
        private readonly ConveningService $convening,
        private readonly EvidenceService $evidence,
        private readonly EvidenceStorage $storage,
    ) {}

    // ------------------------------------------------------------- intake

    /**
     * Record a transcript and queue it.
     *
     * Two ways a resend is caught. The sender's own meeting id, where there is
     * one, is unique per company — a webhook that retries is the ordinary case,
     * not an edge case. Without one, the same text arriving twice is caught by
     * its digest. Either way the original row comes back and nothing is re-run,
     * because re-running a meeting would apply every one of its changes twice.
     *
     * @param  array{text: string, title?: string|null, occurred_at?: string|null, participants?: list<string>|null,
     *               source?: string|null, external_id?: string|null, circle_id?: string|null}  $data
     */
    public function receive(Organisation $organisation, User $submittedBy, array $data): TranscriptImport
    {
        abort_unless(
            $organisation->memberships()->where('user_id', $submittedBy->id)->exists(),
            403,
            'You are not a member of this company.',
        );

        $text = trim((string) $data['text']);

        abort_if($text === '', 422, 'The transcript is empty.');

        $digest     = hash('sha256', $text);
        $externalId = isset($data['external_id']) && trim((string) $data['external_id']) !== ''
            ? trim((string) $data['external_id'])
            : null;

        $existing = $externalId !== null
            ? TranscriptImport::where('organisation_id', $organisation->id)->where('external_id', $externalId)->first()
            : TranscriptImport::where('organisation_id', $organisation->id)
                ->where('content_sha256', $digest)
                ->whereNotIn('status', [TranscriptImport::FAILED, TranscriptImport::DUPLICATE])
                ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (isset($data['circle_id'])) {
            $circle = Circle::find($data['circle_id']);

            abort_if(
                $circle === null || $circle->organisation_id !== $organisation->id,
                422,
                'That Circle does not belong to this company.',
            );
        }

        $import = TranscriptImport::create([
            'organisation_id'      => $organisation->id,
            'submitted_by_user_id' => $submittedBy->id,
            'requested_circle_id'  => $data['circle_id'] ?? null,
            'source'               => mb_substr(trim((string) ($data['source'] ?? 'manual')) ?: 'manual', 0, 60),
            'external_id'          => $externalId,
            'title'                => isset($data['title']) ? mb_substr(trim((string) $data['title']), 0, 300) : null,
            'occurred_at'          => $data['occurred_at'] ?? null,
            'participants_json'    => $data['participants'] ?? null,
            'content_sha256'       => $digest,
            'content_chars'        => mb_strlen($text),
            'content'              => $text,
            'status'               => TranscriptImport::PENDING,
        ]);

        ProcessTranscriptImport::dispatch($import->id)->afterCommit();

        return $import;
    }

    /** Put a failed import back in the queue. It resumes from its last checkpoint. */
    public function retry(TranscriptImport $import): TranscriptImport
    {
        abort_unless($import->status === TranscriptImport::FAILED, 422, 'Only a failed import can be retried.');

        $import->update(['status' => TranscriptImport::PENDING, 'error' => null]);

        ProcessTranscriptImport::dispatch($import->id)->afterCommit();

        return $import;
    }

    // -------------------------------------------------------------- work

    public function process(TranscriptImport $import): TranscriptImport
    {
        if ($import->isSettled() && $import->status !== TranscriptImport::PENDING) {
            return $import;
        }

        $import->update(['status' => TranscriptImport::PROCESSING, 'error' => null]);

        try {
            $user = $import->submittedBy ?? throw new \RuntimeException(
                'The person whose connector sent this no longer has an account. Nothing was written.',
            );

            $text = $this->textOf($import);

            if ($import->circle_id === null) {
                $route = $this->router->route($import, $user, $text);

                // Which model made the call, if any did. Every model call in
                // this product leaves a record of itself; routing is a cheap
                // one, not an exempt one.
                $routedBy = ['routing_model' => $route['model']];

                if ($route['decision'] === TranscriptRouter::NONE) {
                    // Not about a piece of work, so not kept: the text is
                    // cleared and only the fact that it arrived remains.
                    $import->update([
                        'status'         => TranscriptImport::IGNORED,
                        'routing'        => 'none',
                        'routing_reason' => $route['reason'],
                        'result_json'    => $routedBy,
                        'content'        => null,
                        'processed_at'   => now(),
                    ]);

                    return $import->fresh();
                }

                $circle = $route['decision'] === TranscriptRouter::NEW
                    ? $this->openCircle($import, $user)
                    : $route['circle'];

                $import->update([
                    'circle_id'      => $circle->id,
                    'routing'        => match (true) {
                        $route['decision'] === TranscriptRouter::NEW => 'opened_new',
                        $import->requested_circle_id !== null        => 'pinned',
                        default                                      => 'routed_to_existing',
                    },
                    'routing_reason' => $route['reason'],
                    'result_json'    => $routedBy,
                ]);
            }

            $circle = $import->circle()->firstOrFail();

            if ($import->evidence_item_id === null) {
                $item = $this->file($circle, $user, $import, $text);

                // Filed, hashed and behind the gate: the buffer is no longer
                // the copy of record, so it goes.
                $import->update(['evidence_item_id' => $item->id, 'content' => null]);
            }

            $item = $import->evidenceItem()->firstOrFail();

            $result = $import->routing === 'opened_new'
                ? $this->convene($circle, $user, $import, $item, $text)
                : $this->scribe($circle, $user, $import, $item, $text);

            $import->update([
                'status'       => TranscriptImport::APPLIED,
                'result_json'  => array_merge(
                    ['routing_model' => $import->result_json['routing_model'] ?? null],
                    $result,
                ),
                'agent_run_id' => $result['agent_run_id'] ?? null,
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('A transcript could not be applied', ['import' => $import->id, 'error' => $e->getMessage()]);

            $import->update([
                'status'       => TranscriptImport::FAILED,
                'error'        => $e->getMessage(),
                'processed_at' => now(),
            ]);
        }

        return $import->fresh();
    }

    /**
     * The text, from the buffer while it has not been filed, or from the vault
     * after — which is what lets a retry resume past the filing step.
     */
    private function textOf(TranscriptImport $import): string
    {
        if (is_string($import->content) && $import->content !== '') {
            return $import->content;
        }

        $version = $import->evidenceItem?->currentVersion();
        $text    = $version === null ? null : $this->storage->get($version->storage_key);

        if (! is_string($text) || $text === '') {
            throw new \RuntimeException('The transcript text is no longer available to process.');
        }

        return $text;
    }

    /**
     * A meeting that is the start of a piece of work gets a Circle of its own,
     * owned by the person whose connector sent it — as though they had opened
     * it by hand, because in every sense that matters they did.
     */
    private function openCircle(TranscriptImport $import, User $user): Circle
    {
        $date = ($import->occurred_at ?? now())->toDateString();

        return $this->circles->create(
            organisation: $import->organisation,
            owner: $user,
            name: $import->title ?: "Meeting {$date}",
            purpose: 'Opened from a meeting transcript. This wording is replaced by the one read out of the meeting.',
        );
    }

    /**
     * File the transcript as ordinary evidence.
     *
     * Stored in the vault, registered through EvidenceService like any upload,
     * and hashed before anything reads it — synchronously, because the gate
     * refuses an agent any version whose integrity has not been verified, and
     * the Scribe is about to read this one. Extraction runs in the background
     * as for any document, for the Steward and the search index later.
     */
    private function file(Circle $circle, User $user, TranscriptImport $import, string $text): EvidenceItem
    {
        $date     = ($import->occurred_at ?? now())->toDateString();
        $filename = sprintf('transcript-%s-%s.md', $date, substr($import->id, -6));
        $key      = $this->storage->stagedUploadKey($circle->id, $filename);

        $this->storage->put($key, $text, 'text/markdown');

        $item = $this->evidence->createItem(
            circle: $circle,
            uploader: $user,
            storageKey: $key,
            originalFilename: $filename,
            displayName: 'Meeting — ' . ($import->title ?: $date),
            declaredMimeType: 'text/markdown',
            classification: Classification::Internal,
            sourceLabel: 'Transcript via ' . $import->source,
            // Readable by agents because reading it is the reason it was sent.
            agentRead: true,
        );

        ProcessEvidenceVersion::dispatchSync($item->currentVersion()->id);

        return $item->refresh();
    }

    // -------------------------------------------------------- new Circle

    /**
     * A meeting that starts a piece of work is convened like a contract would
     * be: mission statement, parties, plan, dated deliverables, open questions,
     * and the Steward's brief behind it. The Convener was built for engagement
     * terms; told that this is a meeting, it reads what was agreed as the plan.
     */
    private function convene(Circle $circle, User $user, TranscriptImport $import, EvidenceItem $item, string $text): array
    {
        $result = $this->convening->convene(
            circle: $circle,
            actor: $user,
            lookFor: [
                'This is a meeting transcript rather than a contract. Treat what the meeting agreed — work taken on, '
                . 'dates set, responsibilities assigned — as the plan, and what it left unresolved as open questions. '
                . 'Mark anything nobody explicitly agreed as inferred.',
            ],
            apply: true,
            brief: true,
            suppliedText: [$item->id => $text],
            // "Within three weeks" in a kickoff means three weeks from the
            // kickoff. Without this it meant three weeks from whenever the
            // transcript happened to be processed.
            fallbackAnchor: $import->occurred_at,
        );

        $artifact = $result['artifact'];
        $plan     = $artifact->content_json['plan'] ?? [];

        return [
            'mode'         => 'convened',
            'summary'      => $plan['mission']['purpose'] ?? null,
            'uncertainty'  => $plan['uncertainty'] ?? null,
            'applied'      => $result['applied'],
            'blocked_by'   => $result['blocked_by'],
            'counts'       => $result['created'],
            'agent_run_id' => $artifact->agent_run_id,
        ];
    }

    // ------------------------------------------------------------ Scribe

    /**
     * Read a meeting against the plan and apply what it changed.
     *
     * @return array<string, mixed>  The log line: summary, counts, and one entry per operation.
     */
    private function scribe(Circle $circle, User $user, TranscriptImport $import, EvidenceItem $item, string $text): array
    {
        // A Scribe run that already completed for this import is never run
        // again. A retry after the run is past the model call would otherwise
        // read the meeting a second time and apply every change twice.
        if ($import->agent_run_id !== null
            && AgentRun::whereKey($import->agent_run_id)->where('status', 'completed')->exists()) {
            return $import->result_json ?? ['mode' => 'scribe', 'agent_run_id' => $import->agent_run_id];
        }

        // The person first: the Scribe runs on the authority of whoever
        // connected the note-taker, and a Circle where they could not start an
        // agent is not one their meetings may change.
        $this->gate->authorise($user, Permission::AgentRun, $circle);

        $agent = $this->scribeFor($circle);

        // Then the agent, independently. A closed Circle refuses it here.
        $this->gate->authorise($agent, Permission::ResourceAgentRead, $circle);

        $ai = $this->ai->forTask('transcript');

        $run = AgentRun::create([
            'agent_instance_id'    => $agent->id,
            'circle_id'            => $circle->id,
            'triggered_by_user_id' => $user->id,
            'run_type'             => 'transcript',
            'status'               => 'running',
            'model_provider'       => $ai->name(),
            'model_name'           => $ai->model(),
            'prompt_version'       => $this->prompt->version(),
            'started_at'           => now(),
        ]);

        $import->update(['agent_run_id' => $run->id]);

        $this->audit->record(
            AuditEventType::AgentRunStarted, $circle, ActorType::Agent, $agent->id,
            'agent_run', $run->id, metadata: [
                'triggered_by'      => $user->id,
                'agent'             => $agent->blueprint->name,
                'run_type'          => 'transcript',
                'transcript_import' => $import->id,
                'model'             => $ai->model(),
                'prompt_version'    => $this->prompt->version(),
            ],
        );

        try {
            ['sources' => $sources, 'manifest' => $manifest] = $this->retrieval->gatherText(
                $agent, $circle, $run, [$item->id => $text],
            );

            $run->forceFill(['retrieval_manifest_json' => $manifest])->save();

            abort_if($sources === [], 422, 'The transcript could not be read by the Scribe.');

            $goals = Goal::where('circle_id', $circle->id)
                ->with('responsibleParty')
                ->orderBy('position')
                ->get();

            $parties = CircleParty::where('circle_id', $circle->id)->orderBy('display_name')->get();

            $result = $ai->generateStructured(
                $this->prompt->systemPrompt(),
                $this->prompt->userPrompt($circle, $import, $sources, $goals, $parties),
                $this->prompt->outputSchema(),
            );

            $operations = is_array($result->data['operations'] ?? null) ? $result->data['operations'] : [];

            ['counts' => $counts, 'actions' => $actions] = $this->apply(
                $circle, $agent, $run, $import, $operations, $goals,
            );

            $summary     = trim((string) ($result->data['summary'] ?? '')) ?: null;
            $uncertainty = trim((string) ($result->data['uncertainty'] ?? '')) ?: null;

            DerivedArtifact::create([
                'circle_id'            => $circle->id,
                'parent_resource_type' => 'evidence_version',
                'parent_resource_id'   => $item->currentVersion()?->id,
                'artifact_type'        => ArtifactType::MeetingRecord,
                'content_json'         => [
                    'summary'           => $summary,
                    'uncertainty'       => $uncertainty,
                    'counts'            => $counts,
                    'actions'           => $actions,
                    // What the model returned, beside what was done with it.
                    'model_output'      => $result->data,
                    'label'             => $this->prompt->derivedLabel(),
                    'transcript_import' => $import->id,
                    'meeting'           => [
                        'title'       => $import->title,
                        'occurred_at' => $import->occurred_at?->toISOString(),
                        'source'      => $import->source,
                    ],
                ],
                'model_provider'       => $run->model_provider,
                'model_name'           => $result->model,
                'prompt_version'       => $run->prompt_version,
                'agent_run_id'         => $run->id,
                'source_manifest_json' => $manifest,
                'status'               => 'ready',
            ]);

            $run->forceFill([
                'status'                      => 'completed',
                'output_json'                 => $result->data,
                'model_name'                  => $result->model,
                'input_tokens'                => $result->inputTokens,
                'output_tokens'               => $result->outputTokens,
                'cache_read_input_tokens'     => $result->cacheReadInputTokens,
                'cache_creation_input_tokens' => $result->cacheCreationInputTokens,
                'finished_at'                 => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::TranscriptApplied, $circle, ActorType::Agent, $agent->id,
                'transcript_import', $import->id, metadata: [
                    'agent_run_id' => $run->id,
                    'submitted_by' => $user->id,
                    'source'       => $import->source,
                    'title'        => $import->title,
                    'summary'      => $summary,
                    'counts'       => $counts,
                ],
            );

            return [
                'mode'         => 'scribe',
                'summary'      => $summary,
                'uncertainty'  => $uncertainty,
                'counts'       => $counts,
                'actions'      => $actions,
                'agent_run_id' => $run->id,
            ];
        } catch (\Throwable $e) {
            $run->forceFill([
                'status'      => 'failed',
                'error'       => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::AgentRunFailed, $circle, ActorType::Agent, $agent->id,
                'agent_run', $run->id, metadata: ['error' => $e->getMessage(), 'run_type' => 'transcript'],
            );

            throw $e;
        }
    }

    /**
     * Turn each operation into a ledger action and run it, in order.
     *
     * In order because operations depend on each other: a sub-goal hangs off a
     * goal created two lines earlier, and it can only name that goal once it
     * exists. `ref` labels are mapped to real ids as the creates execute, and
     * an operation that names a label nothing created is refused with a reason
     * rather than guessed at.
     *
     * One operation failing does not stop the rest. A meeting that closed four
     * packages and misnamed a fifth has still closed four, and the log says
     * which one failed and why.
     *
     * @param  list<array<string, mixed>>  $operations
     * @param  Collection<int, Goal>  $goals
     * @return array{counts: array<string, int>, actions: list<array<string, mixed>>}
     */
    private function apply(
        Circle $circle,
        AgentInstance $agent,
        AgentRun $run,
        TranscriptImport $import,
        array $operations,
        Collection $goals,
    ): array {
        $meeting = CarbonImmutable::instance($import->occurred_at ?? now())->startOfDay();
        $tools   = AgentTool::where('agent_blueprint_id', $agent->agent_blueprint_id)->get()->keyBy('key');
        $titles  = $goals->pluck('title', 'id')->all();
        $refs    = [];

        $counts = [
            'created' => 0, 'updated' => 0, 'completed' => 0, 'abandoned' => 0,
            'commitments' => 0, 'failed' => 0, 'skipped' => 0,
        ];

        $actions = [];

        foreach ($operations as $index => $op) {
            $kind     = is_array($op) ? (string) ($op['op'] ?? '') : '';
            $evidence = is_array($op) ? trim((string) ($op['evidence'] ?? '')) : '';

            $line = [
                'op'       => $kind,
                'title'    => null,
                'evidence' => $evidence !== '' ? $evidence : null,
                'status'   => 'skipped',
            ];

            if (! in_array($kind, TranscriptSchema::OPERATIONS, true) || ! isset($tools[$kind])) {
                $line['error'] = "No operation called \"{$kind}\".";
                $counts['skipped']++;
                $actions[] = $line;

                continue;
            }

            try {
                ['arguments' => $arguments, 'due_note' => $dueNote] = $this->argumentsFor($kind, $op, $refs, $meeting);
            } catch (\RuntimeException $e) {
                $line['status'] = 'failed';
                $line['error']  = $e->getMessage();
                $counts['failed']++;
                $actions[] = $line;

                continue;
            }

            $subject       = $arguments['goal_id'] ?? null;
            $line['title'] = $arguments['title'] ?? ($subject !== null ? ($titles[$subject] ?? null) : null);

            $action = $this->actions->propose(
                agent: $agent,
                tool: $tools[$kind],
                arguments: $arguments,
                intent: $this->intentFor($kind, $line['title'], $evidence),
                onBehalfOf: null,
                agentRunId: $run->id,
                idempotencyKey: "transcript:{$import->id}:{$run->id}:{$index}",
            );

            // Approved by policy at proposal, so this runs now. If the Scribe
            // were ever not autonomous, the action would sit in the queue for a
            // person like any other, and the log would say so.
            if ($action->status === AgentActionStatus::Approved) {
                $action = $this->executor->execute($action);
            }

            $line['status']          = $action->status->value;
            $line['agent_action_id'] = $action->id;

            if ($dueNote !== null) {
                $line['due_note'] = $dueNote;
            }

            if ($action->status === AgentActionStatus::Executed) {
                $counts[match ($kind) {
                    'create_goal'       => 'created',
                    'update_goal'       => 'updated',
                    'complete_goal'     => 'completed',
                    'abandon_goal'      => 'abandoned',
                    'create_commitment' => 'commitments',
                }]++;

                if ($kind === 'create_goal') {
                    $newId = $action->result_json['goal_id'] ?? null;

                    if ($newId !== null) {
                        $titles[$newId] = $line['title'];

                        if (($ref = trim((string) ($op['ref'] ?? ''))) !== '') {
                            $refs[$ref] = $newId;
                        }
                    }
                }
            } else {
                $line['error'] = $action->error;
                $counts['failed']++;
            }

            $actions[] = $line;
        }

        return ['counts' => $counts, 'actions' => $actions];
    }

    /**
     * The tool arguments for one operation, with every date resolved and
     * every reference mapped. Deterministic: no model is asked anything here.
     *
     * @param  array<string, mixed>  $op
     * @param  array<string, string>  $refs
     * @return array{arguments: array<string, mixed>, due_note: string|null}
     */
    private function argumentsFor(string $kind, array $op, array $refs, CarbonImmutable $meeting): array
    {
        $text = fn (string $key): ?string => isset($op[$key]) && trim((string) $op[$key]) !== ''
            ? trim((string) $op[$key])
            : null;

        $goalId   = $this->resolveId($text('goal_id'), $refs);
        $evidence = $text('evidence') ?? '';

        $current = $goalId !== null ? Goal::find($goalId)?->due_at : null;
        $due     = $this->resolveDue($op['due'] ?? null, $meeting, $current);

        $arguments = match ($kind) {
            'create_goal' => [
                'title'                => $text('title') ?? throw new \RuntimeException('A new goal needs a title.'),
                'description'          => $text('description'),
                'parent_goal_id'       => $this->resolveId($text('parent_goal_id'), $refs),
                'acceptance_condition' => $text('acceptance_condition'),
                'responsible_party_id' => $text('responsible_party_id'),
                'due_at'               => $due['date'],
            ],
            'update_goal' => [
                'goal_id'              => $goalId ?? throw new \RuntimeException('An update needs the goal it changes.'),
                'title'                => $text('title'),
                'description'          => $text('description'),
                'acceptance_condition' => $text('acceptance_condition'),
                'status'               => $text('status'),
                'progress'             => isset($op['progress']) ? (int) $op['progress'] : null,
                'due_at'               => $due['date'],
                // A moved date always carries a reason. Where the model gave
                // none, the quotation it acted on is the reason.
                'reason'               => $text('reason') ?? ($due['date'] !== null ? $evidence : null),
            ],
            'complete_goal' => [
                'goal_id'  => $goalId ?? throw new \RuntimeException('Completing needs the goal that was done.'),
                'evidence' => $evidence !== '' ? $evidence : throw new \RuntimeException('Completing a goal needs the words that say it was done.'),
            ],
            'abandon_goal' => [
                'goal_id' => $goalId ?? throw new \RuntimeException('Abandoning needs the goal that was dropped.'),
                'reason'  => $text('reason') ?? ($evidence !== '' ? $evidence : throw new \RuntimeException('Abandoning a goal needs a reason.')),
            ],
            'create_commitment' => [
                'title'                => $text('title') ?? throw new \RuntimeException('A commitment needs a title.'),
                'description'          => $text('description'),
                'goal_id'              => $goalId,
                'acceptance_condition' => $text('acceptance_condition'),
                'due_at'               => $due['date'],
            ],
        };

        return [
            'arguments' => array_filter($arguments, fn ($v) => $v !== null),
            'due_note'  => $due['note'],
        ];
    }

    /**
     * An id from the plan, or a label an earlier operation created.
     *
     * A ULID passes through for the tool to check against the Circle; a label
     * in the map is replaced by the goal it created; anything else is a label
     * nothing created, and refusing it is better than guessing which goal was
     * meant.
     *
     * @param  array<string, string>  $refs
     */
    private function resolveId(?string $value, array $refs): ?string
    {
        if ($value === null) {
            return null;
        }

        if (isset($refs[$value])) {
            return $refs[$value];
        }

        if (preg_match('/^[0-9a-z]{26}$/i', $value) === 1) {
            return strtolower($value);
        }

        throw new \RuntimeException(sprintf(
            'This refers to "%s", which is neither a goal in the plan nor one an earlier change created.',
            $value,
        ));
    }

    /**
     * A date from what the meeting said, with the note that explains it.
     *
     * @return array{date: string|null, note: string|null}
     */
    private function resolveDue(mixed $due, CarbonImmutable $meeting, ?\DateTimeInterface $current): array
    {
        if (! is_array($due) || $due === []) {
            return ['date' => null, 'note' => null];
        }

        if (isset($due['on']) && trim((string) $due['on']) !== '') {
            try {
                return ['date' => CarbonImmutable::parse((string) $due['on'])->toDateString(), 'note' => null];
            } catch (\Throwable) {
                throw new \RuntimeException(sprintf('"%s" is not a date this can read.', $due['on']));
            }
        }

        foreach (['from_meeting' => $meeting, 'shift' => $current] as $key => $anchor) {
            $period = $due[$key] ?? null;

            if (! is_array($period) || ! isset($period['value'], $period['unit'])) {
                continue;
            }

            if ($anchor === null) {
                throw new \RuntimeException('The meeting moved a date by a period, but the goal has no date to move.');
            }

            $from = CarbonImmutable::instance($anchor)->startOfDay();
            $date = WorkingCalendar::add($from, (int) $period['value'], (string) $period['unit']);

            if ($date === null) {
                throw new \RuntimeException(sprintf('"%s" is not a unit this can count.', $period['unit']));
            }

            return [
                'date' => $date->toDateString(),
                'note' => WorkingCalendar::describe(
                    (int) $period['value'],
                    (string) $period['unit'],
                    $from,
                    $key === 'from_meeting' ? 'after the meeting on' : 'after the previous due date,',
                ),
            ];
        }

        return ['date' => null, 'note' => null];
    }

    /** One sentence for the ledger's intent column: what, to which goal, and why. */
    private function intentFor(string $kind, ?string $title, string $evidence): string
    {
        $what = match ($kind) {
            'create_goal'       => 'Add goal',
            'update_goal'       => 'Update',
            'complete_goal'     => 'Mark done',
            'abandon_goal'      => 'Drop',
            'create_commitment' => 'Add commitment',
            default             => $kind,
        };

        $quote = $evidence === '' ? '' : ' — "' . mb_substr($evidence, 0, 160) . '"';

        return trim($what . ($title !== null ? " \"{$title}\"" : '')) . $quote;
    }

    /** Lazily binds the Scribe blueprint to this Circle. */
    public function scribeFor(Circle $circle): AgentInstance
    {
        $blueprint = AgentBlueprint::where('key', AgentBlueprint::SCRIBE)->firstOrFail();

        return AgentInstance::firstOrCreate(
            ['agent_blueprint_id' => $blueprint->id, 'circle_id' => $circle->id],
            ['status' => 'active'],
        )->load('blueprint');
    }
}
