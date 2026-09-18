<?php

namespace App\Services\Agent;

use App\Enums\ActorType;
use App\Enums\ArtifactType;
use App\Enums\AuditEventType;
use App\Enums\CitationType;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\DecisionStatus;
use App\Enums\Permission;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\AgentTool;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Claim;
use App\Models\ClaimCitation;
use App\Models\Decision;
use App\Models\DerivedArtifact;
use App\Models\EvidenceVersion;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Support\Facades\DB;

/**
 * Runs any agent (spec §9, §20.4).
 *
 * This was the Circle Steward's private machinery until agents became something
 * customers author. Generalising it was mostly deletion: retrieval never cared
 * whose agent it served, and the citation validator never cared who wrote the
 * claim. What is left specific to an agent is its prompt and its mandate, and
 * both arrive as parameters.
 *
 * The property that has to survive generalisation: the model never mutates
 * business data. It returns a structured proposal. This class validates every
 * citation against evidence the agent was *actually* allowed to read, discards
 * anything it cannot resolve, and writes the survivors as drafts attributed to
 * the agent — and now, for an agent in execute mode, writes its intended
 * actions to a ledger where they wait for a human.
 *
 * Every write below is checked twice: once against the blueprint's declared
 * mandate, and once against AccessGate with the agent as the actor. The first
 * is what the author promised; the second is what the Circle permits. An
 * authored agent that declares `claim.create` still writes nothing into a
 * closed Circle.
 */
class AgentRunner
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly AgentRetrieval $retrieval,
        private readonly AiProvider $ai,
        private readonly AgentActionService $actions,
    ) {}

    /**
     * @param  list<string>  $openQuestions
     */
    public function run(
        AgentInstance $agent,
        User $triggeredBy,
        AgentPromptContract $prompt,
        array $openQuestions = [],
        string $runType = 'agent_brief',
    ): AgentRun {
        $circle = $agent->circle;

        // The Steward's brief and an agent somebody wrote in the studio are
        // different jobs with different budgets. Resolved before the run row so
        // a failure records the model it was about to call.
        $ai = $this->ai->forTask($runType === 'steward_brief' ? 'brief' : 'authored');

        // The human must be allowed to run an agent...
        $this->gate->authorise($triggeredBy, Permission::AgentRun, $circle);

        // ...and the agent must independently be allowed to act in this Circle.
        // A closed or expired Circle fails here even if the user is an owner.
        $this->gate->authorise($agent, Permission::ResourceAgentRead, $circle);

        abort_unless($agent->isActive(), 422, 'This agent is disabled in this Circle.');
        abort_unless(
            $agent->blueprint?->isActive() ?? false,
            422,
            'This agent has been suspended and cannot be run.',
        );

        $run = AgentRun::create([
            'agent_instance_id'    => $agent->id,
            'circle_id'            => $circle->id,
            'triggered_by_user_id' => $triggeredBy->id,
            'run_type'             => $runType,
            'status'               => 'running',
            'model_provider'       => $ai->name(),
            'model_name'           => $ai->model(),
            'prompt_version'       => $prompt->version(),
            'started_at'           => now(),
        ]);

        $this->audit->record(
            AuditEventType::AgentRunStarted, $circle, ActorType::Agent, $agent->id,
            'agent_run', $run->id, metadata: [
                'triggered_by'   => $triggeredBy->id,
                'agent'          => $agent->blueprint->name,
                'execution_mode' => $agent->blueprint->execution_mode?->value,
                'model'          => $ai->model(),
                'prompt_version' => $prompt->version(),
            ],
        );

        try {
            ['sources' => $sources, 'manifest' => $manifest] = $this->retrieval->gather($agent, $circle, $run);

            // Recorded before the model call so a failed run still shows
            // exactly what was retrieved and what was refused.
            $run->forceFill(['retrieval_manifest_json' => $manifest])->save();

            $result = $ai->generateStructured(
                $prompt->systemPrompt(),
                $prompt->userPrompt($circle, $sources, $openQuestions),
                $prompt->outputSchema(),
            );

            $persisted = $this->persist($circle, $agent, $run, $prompt, $result->data, $sources);

            $run->forceFill([
                'status'                     => 'completed',
                'output_json'                => $result->data,
                'model_name'                 => $result->model,
                'input_tokens'               => $result->inputTokens,
                'output_tokens'              => $result->outputTokens,
                // Cached input is billed at a different rate from fresh input,
                // so a run that records only `input_tokens` under-reports what
                // it spent. Kept separate rather than summed: the split is what
                // tells you whether the cache is earning its 1.25x write.
                'cache_read_input_tokens'     => $result->cacheReadInputTokens,
                'cache_creation_input_tokens' => $result->cacheCreationInputTokens,
                'finished_at'                => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::AgentOutputCreated, $circle, ActorType::Agent, $agent->id,
                'agent_run', $run->id, metadata: array_merge($persisted, [
                    'model'  => $result->model,
                    'tokens' => [
                        'input'          => $result->inputTokens,
                        'output'         => $result->outputTokens,
                        'cache_read'     => $result->cacheReadInputTokens,
                        'cache_creation' => $result->cacheCreationInputTokens,
                        'input_total'    => $result->totalInputTokens(),
                    ],
                ]),
            );

            return $run->refresh();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status'      => 'failed',
                'error'       => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::AgentRunFailed, $circle, ActorType::Agent, $agent->id,
                'agent_run', $run->id, metadata: ['error' => $e->getMessage()],
            );

            throw $e;
        }
    }

    /**
     * Writes the model's proposal into the domain — as drafts and proposals only.
     *
     * @return array<string, int|list<string>> counts of what was persisted vs. refused
     */
    private function persist(
        Circle $circle,
        AgentInstance $agent,
        AgentRun $run,
        AgentPromptContract $prompt,
        array $output,
        array $sources,
    ): array {
        // Only versions this run actually retrieved may be cited. Anything else
        // is a hallucinated id and its claim is dropped.
        $allowedVersionIds = array_column($sources, 'evidence_version_id');

        $counts = [
            'claims'           => 0,
            'claims_rejected'  => 0,
            'decisions'        => 0,
            'citations'        => 0,
            'actions_proposed' => 0,
            'refused'          => [],
        ];

        $mayClaim   = $this->agentMay($agent, $circle, Permission::ClaimCreate);
        $mayDecide  = $this->agentMay($agent, $circle, Permission::DecisionCreate);

        // Computed here rather than asked of the model. Same inputs, same
        // answer every time, and no tokens — see EvidenceStaleness.
        $potentiallyStale = EvidenceStaleness::detect($sources);

        $counts['potentially_stale'] = count($potentiallyStale);

        DB::transaction(function () use (
            $circle, $agent, $run, $prompt, $output, $allowedVersionIds,
            $mayClaim, $mayDecide, $potentiallyStale, &$counts
        ) {
            // The brief itself, with full provenance (spec §9 output contract).
            // Written whatever the mandate allows: it is the record of the run,
            // not a change to the Circle, and a read-only agent still owes an
            // account of what it looked at and concluded.
            DerivedArtifact::create([
                'circle_id'            => $circle->id,
                'parent_resource_type' => 'circle',
                'parent_resource_id'   => $circle->id,
                'artifact_type'        => ArtifactType::AgentSummary,
                'content_json'         => [
                    'summary'           => $output['summary'] ?? null,
                    'status'            => $output['status'] ?? null,
                    'uncertainty'       => $output['uncertainty'] ?? null,
                    'missing_evidence'  => $output['missing_evidence'] ?? [],
                    'potentially_stale' => $potentiallyStale,
                    'label'             => $prompt->derivedLabel(),
                    'agent_instance_id' => $agent->id,
                    'agent_name'        => $agent->blueprint->name,
                    'blueprint_version' => $agent->blueprint->version,
                ],
                'model_provider'       => $run->model_provider,
                'model_name'           => $run->model_name,
                'prompt_version'       => $run->prompt_version,
                'agent_run_id'         => $run->id,
                'source_manifest_json' => $run->retrieval_manifest_json,
                'status'               => 'ready',
            ]);

            if (($output['claims'] ?? []) !== [] && ! $mayClaim) {
                $counts['refused'][] = 'claims: this agent does not hold claim.create';
            }

            if ($mayClaim) {
                foreach ($output['claims'] ?? [] as $claimData) {
                    $citations = array_values(array_filter(
                        $claimData['citations'] ?? [],
                        fn (array $c) => in_array($c['evidence_version_id'] ?? null, $allowedVersionIds, true),
                    ));

                    // A claim whose every citation was fabricated is not
                    // evidence of anything. Drop it rather than storing an
                    // unsourced assertion attributed to the agent.
                    if ($citations === []) {
                        $counts['claims_rejected']++;

                        continue;
                    }

                    $claim = Claim::create([
                        'circle_id'    => $circle->id,
                        'author_type'  => 'agent',
                        'author_id'    => $agent->id,
                        'statement'    => $claimData['statement'],
                        'claim_type'   => ClaimType::tryFrom($claimData['claim_type'] ?? '') ?? ClaimType::Factual,
                        // Agent claims enter as `derived` — never reviewed,
                        // never approved, never silently promoted (spec §5).
                        'status'       => ClaimStatus::Derived,
                        'confidence'   => $claimData['confidence'] ?? null,
                        'agent_run_id' => $run->id,
                    ]);

                    foreach ($citations as $citation) {
                        $locator = $citation['locator'] ?? null;

                        ClaimCitation::create([
                            'claim_id'            => $claim->id,
                            'evidence_version_id' => $citation['evidence_version_id'],
                            'citation_type'       => $this->citationTypeFor($citation['evidence_version_id'], $locator),
                            'locator_json'        => $locator,
                            'excerpt'             => $citation['excerpt'] ?? null,
                        ]);

                        $counts['citations']++;
                    }

                    $counts['claims']++;
                }
            }

            if (($output['decision_drafts'] ?? []) !== [] && ! $mayDecide) {
                $counts['refused'][] = 'decision_drafts: this agent does not hold decision.create';
            }

            if ($mayDecide) {
                foreach ($output['decision_drafts'] ?? [] as $draft) {
                    Decision::create([
                        'circle_id'    => $circle->id,
                        'title'        => $draft['title'],
                        'description'  => trim(($draft['description'] ?? '')
                            . "\n\n[" . $prompt->derivedLabel() . '. Suggested approver role: '
                            . ($draft['suggested_approver_role'] ?? 'unspecified') . ']'),
                        // Draft, with no approver assigned. A human must name
                        // the approver before it becomes pending (spec §9).
                        'status'       => DecisionStatus::Draft,
                        'agent_run_id' => $run->id,
                    ]);

                    $counts['decisions']++;
                }
            }
        });

        // Proposals sit outside that transaction on purpose. A tool call the
        // gate refuses must not roll back the claims the same run legitimately
        // produced — the refusal is the interesting record, and losing the rest
        // of the run to it would teach authors to declare fewer tools rather
        // than better ones.
        $this->proposeToolCalls($circle, $agent, $run, $output['tool_calls'] ?? [], $counts);

        return $counts;
    }

    /**
     * Turns the model's intended actions into ledger rows.
     *
     * Nothing here runs anything. `propose` writes the row before any attempt,
     * decides whether a human is needed, and refuses outright if the agent is
     * outside its mandate. A refusal is caught and recorded rather than thrown,
     * because "the agent asked for something it could not have" is a finding
     * about the agent, not a failure of the run.
     */
    private function proposeToolCalls(
        Circle $circle,
        AgentInstance $agent,
        AgentRun $run,
        array $calls,
        array &$counts,
    ): void {
        if ($calls === []) {
            return;
        }

        if (! ($agent->blueprint->execution_mode?->canExecute() ?? false)) {
            $counts['refused'][] = sprintf(
                '%d tool call(s): this agent is not in execute mode',
                count($calls),
            );

            return;
        }

        $onBehalfOf = $this->partyFor($agent, $circle);

        foreach ($calls as $index => $call) {
            $tool = AgentTool::where('agent_blueprint_id', $agent->agent_blueprint_id)
                ->where('key', $call['tool_key'] ?? '')
                ->where('enabled', true)
                ->first();

            // The schema constrained `tool_key` to the declared list; this is
            // the check that actually holds, because the schema is advice to a
            // model and the database is the mandate.
            if ($tool === null) {
                $counts['refused'][] = sprintf('tool_call[%d]: unknown tool %s', $index, $call['tool_key'] ?? '(none)');

                continue;
            }

            try {
                $this->actions->propose(
                    agent: $agent,
                    tool: $tool,
                    arguments: self::decodeArguments($call['arguments'] ?? null),
                    intent: $call['intent'] ?? null,
                    onBehalfOf: $onBehalfOf,
                    agentRunId: $run->id,
                    // Deterministic per run and position, so a retried run
                    // cannot double-propose the same call.
                    idempotencyKey: $run->id . ':' . $index,
                );

                $counts['actions_proposed']++;
            } catch (\Throwable $e) {
                $counts['refused'][] = sprintf('tool_call[%d] %s: %s', $index, $tool->key, $e->getMessage());
            }
        }
    }

    /**
     * The party an agent acts for.
     *
     * Resolved from the blueprint's owning organisation, never from the model's
     * output. An agent authored by the contractor acts for the contractor even
     * when the convener is the one who pressed run — which is the whole reason
     * `on_behalf_of_party_id` exists rather than an `actor` string.
     *
     * A system agent acts for the convener: the Steward is the platform's, and
     * the convener is the party that opened the Circle and receives the packet.
     */
    private function partyFor(AgentInstance $agent, Circle $circle): ?CircleParty
    {
        $organisationId = $agent->blueprint->is_system
            ? $circle->organisation_id
            : $agent->blueprint->organisation_id;

        if ($organisationId === null) {
            return null;
        }

        return CircleParty::where('circle_id', $circle->id)
            ->where('organisation_id', $organisationId)
            ->first();
    }

    /**
     * Both halves of the mandate: what the blueprint declared, and what the
     * Circle permits the agent right now.
     */
    private function agentMay(AgentInstance $agent, Circle $circle, Permission $permission): bool
    {
        return $agent->blueprint->grants($permission)
            && $this->gate->allows($agent, $permission, $circle);
    }

    /**
     * Infers the citation type from the locator shape and the cited version's
     * media lane, so the UI knows how to deep-link it.
     */
    /**
     * The tool arguments, however the model chose to hand them over.
     *
     * The schema asks for a JSON string, because a tool's argument shape
     * belongs to the tool and structured outputs has no way to say "any
     * object". A model that sends the object anyway is obliging rather than
     * wrong, so both are accepted; anything that will not decode becomes an
     * empty argument set, and the proposal lands in the ledger with nothing in
     * it for a human to look at and refuse. Guessing at half-parsed arguments
     * would be the one outcome worse than a visibly empty proposal.
     *
     * @return array<string, mixed>
     */
    private static function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || trim($arguments) === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function citationTypeFor(string $versionId, ?array $locator): CitationType
    {
        if ($locator === null || $locator === []) {
            return CitationType::Generic;
        }

        if (isset($locator['sheet']) || isset($locator['range'])) {
            return CitationType::SpreadsheetCell;
        }

        if (isset($locator['page'])) {
            return CitationType::DocumentPage;
        }

        if (isset($locator['start_seconds'])) {
            $lane = EvidenceVersion::find($versionId)?->lane();

            return $lane === 'audio' ? CitationType::AudioTimestamp : CitationType::VideoTimestamp;
        }

        if (isset($locator['start_char'])) {
            return CitationType::TextRange;
        }

        return CitationType::Generic;
    }
}
