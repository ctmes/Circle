<?php

namespace App\Services\Convening;

use App\Enums\ActorType;
use App\Enums\ArtifactType;
use App\Enums\AuditEventType;
use App\Enums\CommitmentStatus;
use App\Enums\Permission;
use App\Jobs\RunStewardBrief;
use App\Models\AgentBlueprint;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\DerivedArtifact;
use App\Models\EvidenceItem;
use App\Models\Goal;
use App\Models\User;
use App\Services\Agent\AgentRetrieval;
use App\Services\Ai\AiProvider;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use App\Services\Circles\CircleService;
use App\Services\Decisions\DecisionService;
use App\Services\Evidence\GoalFiling;
use App\Services\Goals\GoalService;
use Illuminate\Support\Facades\DB;

/**
 * Convening a Circle from an engagement of terms (spec §23).
 *
 * One gesture for the person, two acts in the record, and the separation is the
 * whole design.
 *
 * `propose()` is the agent's act. The Circle Convener reads documents somebody
 * has marked agent-readable and writes one derived artifact — a plan, labelled
 * as a reading of a document. It changes nothing else. The Convener holds
 * `read_only`, whose ceiling is `circle.view` and `resource.agent_read`, so
 * this is enforced by the gate rather than promised here.
 *
 * `applyProposal()` is the person's act, and `convene()` runs the two back to
 * back so that dropping a contract produces a working Circle rather than a
 * form. Everything written is written by the person who convened: the goals
 * through GoalService with them as creator, the mission statement through
 * CircleService with a before and after on the chain, the parties and
 * commitments and decisions as though entered by hand. Nothing in the record
 * says an agent planned this mission, because an agent did not — an agent read
 * a document that a person chose, and that person's name is on the result.
 *
 * The artifact keeps both the model's raw output and the plan that was made of
 * it, so six months later the question "what did it actually say, and what did
 * we do with it" has an answer.
 */
class ConveningService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly AgentRetrieval $retrieval,
        private readonly AiProvider $ai,
        private readonly ConveningPrompt $prompt,
        private readonly PlanResolver $resolver,
        private readonly CircleService $circles,
        private readonly GoalService $goals,
        private readonly DecisionService $decisions,
        private readonly GoalFiling $filing,
    ) {}

    /**
     * Read the documents and build the Circle.
     *
     * The write is attempted only if the person holds everything it needs. A
     * reader who can run an agent but cannot create goals still gets the
     * proposal — the reading is worth having, and somebody else can accept it —
     * and the response says plainly why nothing was written, rather than
     * failing the whole call and losing the run.
     *
     * @param  list<string>  $evidenceItemIds  Documents to read. Empty means every agent-readable item.
     * @param  list<string>  $lookFor          Anything the convening person asked to be checked for.
     * @return array{artifact: DerivedArtifact, applied: bool, blocked_by: string|null, created: array<string, int>}
     */
    public function convene(
        Circle $circle,
        User $actor,
        array $evidenceItemIds = [],
        array $lookFor = [],
        bool $apply = true,
        bool $brief = true,
        /**
         * Text the caller already holds, keyed by evidence item id — a meeting
         * transcript that has just been filed (spec 24). Read through the
         * gate like anything else, but without waiting on an extraction job
         * for words that arrived a moment ago.
         *
         * @var array<string, string>
         */
        array $suppliedText = [],
        /** Where periods are measured from if the document names no start — a meeting's date. */
        ?\DateTimeInterface $fallbackAnchor = null,
    ): array {
        $artifact = $this->propose($circle, $actor, $evidenceItemIds, $lookFor, $suppliedText, $fallbackAnchor);

        $blockedBy = $apply ? $this->blockedFrom($actor, $circle) : 'not requested';

        if ($blockedBy !== null) {
            return ['artifact' => $artifact, 'applied' => false, 'blocked_by' => $blockedBy, 'created' => []];
        }

        $created = $this->applyProposal($circle, $actor, $artifact);

        // After the plan is committed, never as part of it. A Steward that is
        // over quota must not be able to undo a contract somebody just read in.
        if ($brief) {
            RunStewardBrief::dispatch($circle->id, $actor->id)->afterCommit();
        }

        return [
            'artifact'   => $artifact->refresh(),
            'applied'    => true,
            'blocked_by' => null,
            'created'    => $created,
        ];
    }

    /**
     * Read the documents and write the proposal. Changes nothing else.
     *
     * @param  list<string>  $evidenceItemIds
     * @param  list<string>  $lookFor
     */
    public function propose(
        Circle $circle,
        User $actor,
        array $evidenceItemIds = [],
        array $lookFor = [],
        array $suppliedText = [],
        ?\DateTimeInterface $fallbackAnchor = null,
    ): DerivedArtifact {
        $this->gate->authorise($actor, Permission::AgentRun, $circle);

        $agent = $this->instanceFor($circle);

        // The agent's own standing in this Circle, checked independently of the
        // person's. A closed or expired Circle fails here even for an owner.
        $this->gate->authorise($agent, Permission::ResourceAgentRead, $circle);

        // Resolved once, before the run row is written, so a run that fails
        // records the model that was actually going to be called rather than
        // the instance default.
        $ai = $this->ai->forTask('convening');

        $run = AgentRun::create([
            'agent_instance_id'    => $agent->id,
            'circle_id'            => $circle->id,
            'triggered_by_user_id' => $actor->id,
            'run_type'             => 'convening',
            'status'               => 'running',
            'model_provider'       => $ai->name(),
            'model_name'           => $ai->model(),
            'prompt_version'       => $this->prompt->version(),
            'started_at'           => now(),
        ]);

        $this->audit->record(
            AuditEventType::AgentRunStarted, $circle, ActorType::Agent, $agent->id,
            'agent_run', $run->id, metadata: [
                'triggered_by'   => $actor->id,
                'agent'          => $agent->blueprint->name,
                'run_type'       => 'convening',
                'model'          => $ai->model(),
                'prompt_version' => $this->prompt->version(),
            ],
        );

        try {
            ['sources' => $sources, 'manifest' => $manifest] = $suppliedText !== []
                ? $this->retrieval->gatherText($agent, $circle, $run, $suppliedText)
                : $this->retrieval->gather(
                    $agent, $circle, $run,
                    $evidenceItemIds === [] ? null : $evidenceItemIds,
                    $this->charsPerDocument($evidenceItemIds),
                );

            $run->forceFill(['retrieval_manifest_json' => $manifest])->save();

            abort_if(
                $sources === [],
                422,
                'No readable document was available. The document must be marked agent-readable, '
                . 'and its text extraction must have finished.',
            );

            $result = $ai->generateStructured(
                $this->prompt->systemPrompt(),
                $this->prompt->userPrompt($circle, $sources, $lookFor),
                $this->prompt->outputSchema(),
            );

            $plan = $this->resolver->resolve($result->data, $sources, fallback: $fallbackAnchor);

            $artifact = DerivedArtifact::create([
                'circle_id'            => $circle->id,
                'parent_resource_type' => 'circle',
                'parent_resource_id'   => $circle->id,
                'artifact_type'        => ArtifactType::ConvenedPlan,
                'content_json'         => [
                    'plan'              => $plan,
                    // What the model actually said, kept beside what was made
                    // of it. The resolved plan is a reading of this, and a
                    // reader six months from now is entitled to both.
                    'model_output'      => $result->data,
                    'label'             => $this->prompt->derivedLabel(),
                    'agent_instance_id' => $agent->id,
                    'agent_name'        => $agent->blueprint->name,
                    'blueprint_version' => $agent->blueprint->version,
                    'sources'           => array_map(fn (array $s) => [
                        'evidence_item_id'    => $s['evidence_item_id'],
                        'evidence_version_id' => $s['evidence_version_id'],
                        'name'                => $s['name'],
                        'sha256'              => $s['sha256'],
                        // Carried so a re-resolution can still check a page
                        // number against the document this was read from,
                        // without going back to a vault where the version may
                        // since have been superseded.
                        'pages'               => self::pageCountOf($s),
                    ], $sources),
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
                AuditEventType::CircleConvened, $circle, ActorType::Agent, $agent->id,
                'derived_artifact', $artifact->id, metadata: [
                    'agent_run_id' => $run->id,
                    'documents'    => array_column($sources, 'name'),
                    'counts'       => $plan['counts'],
                    'model'        => $result->model,
                ],
            );

            return $artifact;
        } catch (\Throwable $e) {
            $run->forceFill([
                'status'      => 'failed',
                'error'       => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::AgentRunFailed, $circle, ActorType::Agent, $agent->id,
                'agent_run', $run->id, metadata: ['error' => $e->getMessage(), 'run_type' => 'convening'],
            );

            throw $e;
        }
    }

    /**
     * Re-read a proposal against a different start date.
     *
     * Free, deterministic, and no model call: the raw output is on the
     * artifact, and every date in the plan is either a date the document stated
     * — which does not move — or a period measured from commencement, which
     * PlanResolver recalculates. Asking a model to "redo the plan starting in
     * March" would spend tokens to get a different plan.
     */
    public function reresolve(DerivedArtifact $artifact, ?\DateTimeInterface $anchor): array
    {
        $content = $artifact->content_json ?? [];

        return $this->resolver->resolve(
            is_array($content['model_output'] ?? null) ? $content['model_output'] : [],
            $this->sourcesForResolution($content),
            $anchor,
        );
    }

    /**
     * Write the whole proposal in, unedited.
     *
     * This is what convening does by default, and it is a different act from
     * `accept()` only in where the payload comes from — everything the plan
     * describes is kept, because nobody has been asked which parts to drop. The
     * write path, the attribution and the audit trail are identical, and the
     * artifact records that nothing was edited.
     *
     * @return array<string, int>
     */
    public function applyProposal(Circle $circle, User $actor, DerivedArtifact $artifact): array
    {
        $plan = $artifact->content_json['plan'] ?? null;

        abort_if(! is_array($plan), 422, 'This proposal has no plan in it.');

        return $this->accept($circle, $actor, $artifact, $this->payloadFrom($plan));
    }

    /**
     * Write a reviewed plan into the Circle.
     *
     * The three permissions are checked before anything is written rather than
     * as each write is attempted. A half-applied plan — a renamed Circle with
     * no work under it — is worse than a refused one, because the Circle now
     * describes a mission that has nothing in it.
     *
     * @param  array  $payload  The plan to write. See ConveningController for its shape.
     * @return array<string, int>
     */
    public function accept(Circle $circle, User $actor, DerivedArtifact $artifact, array $payload): array
    {
        $this->gate->authorise($actor, Permission::CircleManageMembers, $circle);
        $this->gate->authorise($actor, Permission::PartyManage, $circle);
        $this->gate->authorise($actor, Permission::GoalCreate, $circle);

        abort_unless($artifact->circle_id === $circle->id, 404, 'That plan belongs to another Circle.');
        abort_unless($circle->acceptsContributions(), 422, 'This Circle is not accepting contributions.');
        abort_if(
            $artifact->status === 'applied',
            422,
            'This plan has already been written into the Circle. Edit it in the plan from here on.',
        );

        $content     = $artifact->content_json ?? [];
        $sourceNames = array_column($content['sources'] ?? [], 'name');
        $stepSources = $this->sourceOfEachStep($content);
        $sourceItems = EvidenceItem::with('resource')
            ->whereIn('id', array_column($content['sources'] ?? [], 'evidence_item_id'))
            ->get();

        return DB::transaction(function () use (
            $circle, $actor, $artifact, $payload, $sourceNames, $stepSources, $sourceItems
        ) {
            $concluded = $this->date($payload['expires_at'] ?? null);

            // A contract that has already concluded does not make the Circle
            // read-only on arrival.
            //
            // The Circle's expiry is an authorisation decision — the gate
            // refuses every write past it on the very next request (spec
            // 21.2) — while the document's end date is a fact about the
            // document. Convening a past engagement in order to build its
            // record is a legitimate thing to do, and handing somebody a fully
            // populated Circle they cannot write to is a very confusing way to
            // tell them the contract has ended. The date is on the acceptance
            // event either way, so nothing is lost by not enforcing it.
            $expires = $concluded !== null && $concluded > now() ? $payload['expires_at'] : null;

            $this->circles->updateDetails(
                $circle,
                $actor,
                array_filter([
                    'name'       => $payload['name'] ?? null,
                    'purpose'    => $payload['purpose'] ?? null,
                    'starts_at'  => $payload['starts_at'] ?? null,
                    'expires_at' => $expires,
                ], fn ($v) => $v !== null),
                reason: 'Convened from ' . ($sourceNames === [] ? 'an uploaded document' : implode(', ', $sourceNames)),
            );

            $parties = $this->createParties($circle, $actor, $payload['parties'] ?? []);

            ['count' => $goalCount, 'by_key' => $byKey, 'filings' => $filings] = $this->createGoals(
                $circle, $actor, $payload['steps'] ?? [], $parties, $stepSources, $sourceItems,
            );

            $commitments = $this->createCommitments(
                $circle, $actor, $payload['steps'] ?? [], $byKey, $parties,
            );

            $decisions = $this->createDecisions(
                $circle, $actor, $payload['open_questions'] ?? [], $sourceNames,
            );

            $counts = [
                'parties'     => count($parties),
                'goals'       => $goalCount,
                'commitments' => $commitments,
                'decisions'   => $decisions,
                'filings'     => $filings,
            ];

            $artifact->forceFill([
                'status'       => 'applied',
                'content_json' => array_merge($artifact->content_json ?? [], [
                    'applied_at' => now()->toISOString(),
                    'applied_by' => ['id' => $actor->id, 'name' => $actor->name],
                    'created'    => $counts,
                    // What was written, beside what was proposed. Where the two
                    // differ, the difference is the reviewer's work, and it is
                    // the most interesting thing in this whole record.
                    'accepted_payload' => $payload,
                ]),
            ])->save();

            $this->audit->record(
                AuditEventType::CirclePlanAccepted, $circle, ActorType::User, $actor->id,
                'derived_artifact', $artifact->id, metadata: array_merge($counts, [
                    'agent_run_id' => $artifact->agent_run_id,
                    'documents'    => $sourceNames,
                    'name'         => $payload['name'] ?? $circle->name,
                    'concludes_on' => $payload['expires_at'] ?? null,
                    // Recorded separately from the date, so the record says
                    // both what the document stated and what was done with it.
                    'expiry_set'   => $expires !== null,
                ]),
            );

            return $counts;
        });
    }

    /** Lazily binds the Convener blueprint to this Circle. */
    public function instanceFor(Circle $circle): AgentInstance
    {
        $blueprint = AgentBlueprint::where('key', AgentBlueprint::CONVENER)->firstOrFail();

        return AgentInstance::firstOrCreate(
            ['agent_blueprint_id' => $blueprint->id, 'circle_id' => $circle->id],
            ['status' => 'active'],
        )->load('blueprint');
    }

    /**
     * How much of each named document the Convener is shown.
     *
     * The per-source cap is sized for the Steward, which fits up to forty
     * documents into one prompt. Convening reads the contract it was pointed
     * at, and at that cap a nine-page engagement of terms was cut at page five
     * — the liability, IP and data-handling sections never reached the model,
     * which could then only ask whether they existed. The task's budget is
     * split across the documents named instead, never below the default cap.
     *
     * Unscoped, convening reads every agent-readable document in the Circle,
     * which is the case the default cap exists for, so it keeps it.
     *
     * @param  list<string>  $evidenceItemIds
     */
    private function charsPerDocument(array $evidenceItemIds): ?int
    {
        if ($evidenceItemIds === []) {
            return null;
        }

        return max(
            (int) config('circle.agent.max_chars_per_source'),
            intdiv((int) config('circle.agent.tasks.convening.max_chars'), count(array_unique($evidenceItemIds))),
        );
    }

    // ------------------------------------------------------------- payload

    /**
     * The whole resolved plan, flattened into the shape the write path takes.
     *
     * Nothing is dropped and nothing is renamed. This is the "write it all in"
     * path, and a function here that quietly filtered would be making editorial
     * decisions in a place nobody would think to look for them.
     *
     * @return array<string, mixed>
     */
    private function payloadFrom(array $plan): array
    {
        $steps = [];

        $walk = function (array $nodes, ?string $parentKey) use (&$walk, &$steps): void {
            foreach ($nodes as $node) {
                $steps[] = [
                    'key'                   => $node['key'],
                    'parent_key'            => $parentKey,
                    'title'                 => $node['title'],
                    'description'           => $node['description'] ?? null,
                    'acceptance_condition'  => $node['acceptance_condition'] ?? null,
                    'responsible_party_key' => $node['responsible_party_key'] ?? null,
                    'starts_on'             => $node['starts_on'] ?? null,
                    'due_on'                => $node['due_on'] ?? null,
                    'clause'                => $node['clause'] ?? null,
                ];

                $walk($node['children'] ?? [], $node['key']);
            }
        };

        $walk($plan['plan'] ?? [], null);

        return [
            'name'       => $plan['mission']['name'] ?? null,
            'purpose'    => $plan['mission']['purpose'] ?? null,
            'starts_at'  => $plan['mission']['commences_on'] ?? null,
            'expires_at' => $plan['mission']['concludes_on'] ?? null,
            'parties'    => array_map(fn (array $p) => [
                'key'          => $p['key'],
                'display_name' => $p['display_name'],
                'party_role'   => $p['party_role'],
            ], $plan['parties'] ?? []),
            'steps'          => $steps,
            'open_questions' => $plan['open_questions'] ?? [],
        ];
    }

    /**
     * Why this person cannot have the plan written for them, or null.
     *
     * Checked with `allows()` rather than `authorise()`: a proposal that cannot
     * be applied is not an attempted access, and writing `access.denied` three
     * times for somebody who only ever asked to read a document would fill the
     * record with events nobody caused.
     */
    private function blockedFrom(User $actor, Circle $circle): ?string
    {
        // A Circle that already has a plan is not written into again.
        //
        // Reading the same contract twice is a legitimate act — it is how a
        // variation arrives, and how somebody re-reads a document whose text
        // extraction has since improved — but writing the second reading in on
        // top of the first would silently double every goal, and a plan with
        // two of everything is worse than no plan at all. The proposal is still
        // produced and still readable; applying it is then a deliberate act
        // against a Circle whose existing work somebody has looked at.
        if (Goal::where('circle_id', $circle->id)->exists()) {
            return 'circle_already_has_a_plan';
        }

        foreach ([
            Permission::CircleManageMembers,
            Permission::PartyManage,
            Permission::GoalCreate,
        ] as $permission) {
            if (! $this->gate->allows($actor, $permission, $circle)) {
                return $permission->value;
            }
        }

        return null;
    }

    // ------------------------------------------------------------- writing

    /**
     * @param  list<array<string, mixed>>  $parties
     * @return array<string, CircleParty>  keyed by the proposal-local key
     */
    private function createParties(Circle $circle, User $actor, array $parties): array
    {
        // Whatever is already here wins. Convening a Circle a counterparty has
        // already been admitted to must not produce a second row for the same
        // company — the memberships hang off the existing one.
        $existing = CircleParty::where('circle_id', $circle->id)->get();
        $created  = [];

        foreach ($parties as $party) {
            $name = trim((string) ($party['display_name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $match = $existing->first(
                fn (CircleParty $p) => mb_strtolower(trim($p->display_name)) === mb_strtolower($name),
            );

            if ($match !== null) {
                $created[$party['key'] ?? $name] = $match;

                continue;
            }

            $row = CircleParty::create([
                'circle_id'          => $circle->id,
                'display_name'       => $name,
                'party_role'         => $party['party_role'],
                'status'             => 'invited',
                'is_convener'        => false,
                'invited_by_user_id' => $actor->id,
            ]);

            $existing->push($row);
            $created[$party['key'] ?? $name] = $row;

            $this->audit->record(
                AuditEventType::PartyInvited, $circle, ActorType::User, $actor->id,
                'circle_party', $row->id, metadata: [
                    'name'   => $row->display_name,
                    'role'   => $row->party_role->value,
                    'source' => 'convened',
                ],
            );
        }

        return $created;
    }

    /**
     * @param  list<array<string, mixed>>  $steps  Flat, each naming its parent's key.
     * @param  array<string, CircleParty>  $parties
     * @param  array<string, string>  $stepSources  Document name by step key.
     * @param  \Illuminate\Support\Collection<int, EvidenceItem>  $sourceItems
     * @return array{count: int, by_key: array<string, Goal>, filings: int}
     */
    private function createGoals(
        Circle $circle,
        User $actor,
        array $steps,
        array $parties,
        array $stepSources,
        $sourceItems,
    ): array {
        /** @var array<string, Goal> $byKey */
        $byKey   = [];
        $count   = 0;
        $filings = 0;

        // One pass in the order given. A step whose parent has not been created
        // yet — because the client reordered them, or because the parent was
        // deselected on a review screen — lands at the root rather than being
        // dropped: work that silently disappears between a plan and a Circle is
        // the failure this whole flow exists to avoid.
        foreach ($steps as $step) {
            $title = trim((string) ($step['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $parentKey = $step['parent_key'] ?? null;
            $parent    = $parentKey === null ? null : ($byKey[$parentKey] ?? null);

            $goal = $this->goals->create(
                circle: $circle,
                creator: $actor,
                title: $title,
                description: $this->describe($step, $stepSources[$step['key'] ?? ''] ?? null),
                parent: $parent,
                owner: null,
                responsibleParty: $parties[$step['responsible_party_key'] ?? ''] ?? null,
                acceptanceCondition: $this->trimmedOrNull($step['acceptance_condition'] ?? null),
                startsAt: $this->date($step['starts_on'] ?? null),
                dueAt: $this->date($step['due_on'] ?? null),
            );

            // The document that produced this work is filed against it, so
            // opening a job shows the contract it came from rather than sending
            // somebody to the vault to guess which file was the source.
            foreach ($sourceItems as $item) {
                if ($this->filing->attach($goal, $item, $actor)) {
                    $filings++;
                }
            }

            if (isset($step['key'])) {
                $byKey[$step['key']] = $goal;
            }

            $count++;
        }

        return ['count' => $count, 'by_key' => $byKey, 'filings' => $filings];
    }

    /**
     * A dated obligation for every deliverable at the bottom of the tree.
     *
     * Which steps those are is computed, not asked of the model: a step is a
     * deliverable if nothing hangs beneath it and it has a date. That is the
     * distinction the product already draws — the tree holds the shape of the
     * mission, and commitments are where individual pieces of work live — and
     * it is a structural question, so it is answered structurally.
     *
     * A phase with children is not a commitment, and a leaf with no date is not
     * one either: an obligation with no date is a goal, which it already is.
     *
     * Each one hangs off the goal it came from rather than floating beside it,
     * so the node reads as one piece of work with a date against it instead of
     * appearing twice in the Circle.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, Goal>  $byKey
     * @param  array<string, CircleParty>  $parties
     */
    private function createCommitments(
        Circle $circle,
        User $actor,
        array $steps,
        array $byKey,
        array $parties,
    ): int {
        $hasChildren = [];

        foreach ($steps as $step) {
            if (($step['parent_key'] ?? null) !== null) {
                $hasChildren[$step['parent_key']] = true;
            }
        }

        $created = 0;

        foreach ($steps as $step) {
            $key = $step['key'] ?? null;
            $due = $this->date($step['due_on'] ?? null);

            if ($key === null || $due === null || isset($hasChildren[$key]) || ! isset($byKey[$key])) {
                continue;
            }

            $this->decisions->createCommitment(
                circle: $circle,
                creator: $actor,
                title: $byKey[$key]->title,
                owner: null,
                dueAt: $due,
                description: $this->trimmedOrNull($step['description'] ?? null),
                acceptanceCondition: $this->trimmedOrNull($step['acceptance_condition'] ?? null),
                // Open rather than draft. Draft is where an agent's suggestions
                // wait for a human, and this is not an agent's suggestion — it
                // is a deliverable a person has just written into the Circle
                // from a contract, and it is owed from now.
                status: CommitmentStatus::Open,
                goal: $byKey[$key],
                ownerParty: $parties[$step['responsible_party_key'] ?? ''] ?? null,
            );

            $created++;
        }

        return $created;
    }

    /**
     * What the document leaves unsettled, as draft decisions.
     *
     * §22.4 refused automatic filing on the grounds that a question filed
     * automatically is a question nobody owns. A draft decision is precisely
     * the product's existing word for that state: it has no approver, so it is
     * not actionable and nothing is waiting on anybody, and the only way it
     * becomes live is for a person to name who decides it. The list is
     * therefore a queue of "somebody must own this" rather than a pile of work
     * pretending to be assigned.
     *
     * @param  list<array<string, mixed>>  $questions
     */
    private function createDecisions(Circle $circle, User $actor, array $questions, array $sourceNames): int
    {
        $created = 0;

        foreach ($questions as $question) {
            $title = $this->trimmedOrNull($question['question'] ?? null);

            if ($title === null) {
                continue;
            }

            $why = $this->trimmedOrNull($question['why_it_matters'] ?? null);

            $this->decisions->create(
                circle: $circle,
                creator: $actor,
                title: mb_substr($title, 0, 255),
                // Every document is named: an open question is about what the
                // whole set leaves unsettled, not about the first file read.
                description: trim(
                    ($why ?? '')
                    . "\n\n[Raised while convening from "
                    . ($sourceNames === [] ? 'an uploaded document' : implode(', ', $sourceNames))
                    . (count($sourceNames) > 1 ? '. The documents do not settle it.' : '. The document does not settle it.')
                    . ' Name who decides.]',
                ),
                // No approver, so this stays a draft. Naming one is the act
                // that makes it somebody's, and nothing here may do that on a
                // person's behalf.
                approver: null,
            );

            $created++;
        }

        return $created;
    }

    /**
     * The description, with the clause it came from appended.
     *
     * The citation itself lives on the artifact and cannot travel onto a goal —
     * goals have no citation table, and giving them one would make a goal look
     * like a claim. A line of text naming the clause is the part a person
     * actually uses: it is what they type into the search box of the PDF when
     * they want to check the plan against what they signed.
     */
    private function describe(array $step, ?string $source): ?string
    {
        $description = $this->trimmedOrNull($step['description'] ?? null);
        $clause      = $this->trimmedOrNull($step['clause'] ?? null);

        if ($clause === null) {
            return $description;
        }

        $reference = '[' . $clause . ($source === null ? '' : ', ' . $source) . ']';

        return $description === null ? $reference : $description . "\n\n" . $reference;
    }

    /**
     * Which document each step was read from, by step key.
     *
     * One document answers for every step, cited or not, as it always has.
     * With several, a step is named after the document its citation points
     * at, and an uncited step after none: a clause number pinned to the wrong
     * file sends somebody searching a PDF that does not contain it, while a
     * bare "[cl 4.3]" at least tells them to look.
     *
     * @return array<string, string>
     */
    private function sourceOfEachStep(array $content): array
    {
        $sources = $content['sources'] ?? [];
        $names   = array_column($sources, 'name', 'evidence_version_id');
        $only    = count($sources) === 1 ? $sources[0]['name'] : null;
        $byStep  = [];

        $walk = function (array $nodes) use (&$walk, &$byStep, $names, $only): void {
            foreach ($nodes as $node) {
                $name = $only ?? ($names[$node['citation']['evidence_version_id'] ?? ''] ?? null);

                if ($name !== null && isset($node['key'])) {
                    $byStep[$node['key']] = $name;
                }

                $walk($node['children'] ?? []);
            }
        };

        $walk($content['plan']['plan'] ?? []);

        return $byStep;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The retrieval record, in the shape PlanResolver reads.
     *
     * Reconstructed from the artifact rather than fetched from the vault again.
     * A proposal must keep resolving against the exact versions it was made
     * from — if the contract has since been superseded, re-reading the plan
     * against a start date is not the moment to quietly switch documents.
     */
    private function sourcesForResolution(array $content): array
    {
        return array_map(fn (array $s) => [
            'evidence_version_id' => $s['evidence_version_id'],
            'content'             => [
                // Only the last page is needed: PlanResolver takes the highest
                // page number as the document's length.
                'page_index' => isset($s['pages']) ? [['page' => (int) $s['pages']]] : null,
            ],
        ], $content['sources'] ?? []);
    }

    /** The highest page number retrieval found in a document, or null if it has no page index. */
    private static function pageCountOf(array $source): ?int
    {
        $pages = $source['content']['page_index'] ?? null;

        if (! is_array($pages) || $pages === []) {
            return null;
        }

        $numbers = array_filter(array_column($pages, 'page'), 'is_int');

        return $numbers === [] ? null : max($numbers);
    }
}
