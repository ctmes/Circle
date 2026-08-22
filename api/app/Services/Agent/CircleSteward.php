<?php

namespace App\Services\Agent;

use App\Enums\ActorType;
use App\Enums\ArtifactType;
use App\Enums\AuditEventType;
use App\Enums\CitationType;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\CommitmentStatus;
use App\Enums\DecisionStatus;
use App\Enums\Permission;
use App\Models\AgentBlueprint;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\Circle;
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
 * Runs the Circle Steward (spec §9).
 *
 * The critical property: the model never mutates business data. It returns a
 * structured proposal; this class validates every citation against evidence the
 * agent was actually allowed to read, discards anything it cannot verify, and
 * writes the survivors as *drafts* attributed to the agent.
 */
class CircleSteward
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly StewardRetrieval $retrieval,
        private readonly StewardPrompt $prompt,
        private readonly AiProvider $ai,
    ) {}

    public function runBrief(Circle $circle, User $triggeredBy, array $openQuestions = []): AgentRun
    {
        // The human must be allowed to run the agent...
        $this->gate->authorise($triggeredBy, Permission::AgentRun, $circle);

        $agent = $this->instanceFor($circle);

        // ...and the agent must independently be allowed to act in this Circle.
        // A closed or expired Circle fails here even if the user is an owner.
        $this->gate->authorise($agent, Permission::ResourceAgentRead, $circle);

        $run = AgentRun::create([
            'agent_instance_id'    => $agent->id,
            'circle_id'            => $circle->id,
            'triggered_by_user_id' => $triggeredBy->id,
            'run_type'             => 'steward_brief',
            'status'               => 'running',
            'model_provider'       => $this->ai->name(),
            'model_name'           => $this->ai->model(),
            'prompt_version'       => StewardPrompt::VERSION,
            'started_at'           => now(),
        ]);

        $this->audit->record(
            AuditEventType::AgentRunStarted, $circle, ActorType::Agent, $agent->id,
            'agent_run', $run->id, metadata: [
                'triggered_by'   => $triggeredBy->id,
                'model'          => $this->ai->model(),
                'prompt_version' => StewardPrompt::VERSION,
            ],
        );

        try {
            ['sources' => $sources, 'manifest' => $manifest] = $this->retrieval->gather($agent, $circle, $run);

            // Recorded before the model call so a failed run still shows
            // exactly what was retrieved and what was refused.
            $run->forceFill(['retrieval_manifest_json' => $manifest])->save();

            $result = $this->ai->generateStructured(
                $this->prompt->systemPrompt(),
                $this->prompt->userPrompt($circle, $sources, $openQuestions),
                $this->prompt->outputSchema(),
            );

            $persisted = $this->persist($circle, $agent, $run, $result->data, $sources);

            $run->forceFill([
                'status'        => 'completed',
                'output_json'   => $result->data,
                'model_name'    => $result->model,
                'input_tokens'  => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'finished_at'   => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::AgentOutputCreated, $circle, ActorType::Agent, $agent->id,
                'agent_run', $run->id, metadata: array_merge($persisted, [
                    'model'  => $result->model,
                    'tokens' => ['input' => $result->inputTokens, 'output' => $result->outputTokens],
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
     * Writes the model's proposal into the domain — as drafts only.
     *
     * @return array<string, int> counts of what was persisted vs. rejected
     */
    private function persist(Circle $circle, AgentInstance $agent, AgentRun $run, array $output, array $sources): array
    {
        // Only versions this run actually retrieved may be cited. Anything else
        // is a hallucinated id and its claim is dropped.
        $allowedVersionIds = array_column($sources, 'evidence_version_id');

        $counts = ['claims' => 0, 'claims_rejected' => 0, 'decisions' => 0, 'citations' => 0];

        DB::transaction(function () use ($circle, $agent, $run, $output, $allowedVersionIds, &$counts) {
            // The brief itself, with full provenance (spec §9 output contract).
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
                    'potentially_stale' => $output['potentially_stale'] ?? [],
                    'label'             => StewardPrompt::DERIVED_LABEL,
                    'agent_instance_id' => $agent->id,
                    'blueprint_version' => $agent->blueprint->version,
                ],
                'model_provider'       => $run->model_provider,
                'model_name'           => $run->model_name,
                'prompt_version'       => $run->prompt_version,
                'agent_run_id'         => $run->id,
                'source_manifest_json' => $run->retrieval_manifest_json,
                'status'               => 'ready',
            ]);

            foreach ($output['claims'] ?? [] as $claimData) {
                $citations = array_values(array_filter(
                    $claimData['citations'] ?? [],
                    fn (array $c) => in_array($c['evidence_version_id'] ?? null, $allowedVersionIds, true),
                ));

                // A claim whose every citation was fabricated is not evidence
                // of anything. Drop it rather than storing an unsourced
                // assertion attributed to the agent.
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
                    // Agent claims enter as `derived` — never reviewed, never
                    // approved, and never silently promoted (spec §5).
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

            foreach ($output['decision_drafts'] ?? [] as $draft) {
                Decision::create([
                    'circle_id'    => $circle->id,
                    'title'        => $draft['title'],
                    'description'  => trim(($draft['description'] ?? '')
                        . "\n\n[" . StewardPrompt::DERIVED_LABEL . '. Suggested approver role: '
                        . ($draft['suggested_approver_role'] ?? 'unspecified') . ']'),
                    // Draft, with no approver assigned. A human must name the
                    // approver before it becomes pending (spec §9).
                    'status'       => DecisionStatus::Draft,
                    'agent_run_id' => $run->id,
                ]);

                $counts['decisions']++;
            }
        });

        return $counts;
    }

    /**
     * Infers the citation type from the locator shape and the cited version's
     * media lane, so the UI knows how to deep-link it.
     */
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

    /** Lazily binds the Steward blueprint to this Circle. */
    public function instanceFor(Circle $circle): AgentInstance
    {
        $blueprint = AgentBlueprint::where('key', AgentBlueprint::STEWARD)->firstOrFail();

        return AgentInstance::firstOrCreate(
            ['agent_blueprint_id' => $blueprint->id, 'circle_id' => $circle->id],
            ['status' => 'active'],
        )->load('blueprint');
    }
}
