<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CircleStatus;
use App\Models\AgentResourceAccess;
use App\Models\AuditEvent;
use App\Models\Claim;
use App\Models\Decision;
use App\Models\DerivedArtifact;
use App\Services\Agent\CircleSteward;
use App\Services\Agent\StewardPrompt;
use App\Services\Ai\AiProvider;
use App\Services\Authorisation\AuthorisationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCircles;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * The Circle Steward's guarantees (spec §9, §16).
 *
 * These cover what the end-to-end demo cannot: the model's output is scripted,
 * so the system's handling of it — including hostile output — is exact.
 */
class CircleStewardTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    private FakeAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();

        $this->ai = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->ai);
    }

    private function scenario(): array
    {
        $owner = $this->makeUser('Dana Okafor', 'gm@jwamats.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $readable = $this->makeEvidence(
            $circle, $owner, 'Drawing 4417-02 Rev B', agentRead: true,
            extractedText: "CRANE PAD GENERAL ARRANGEMENT\nOperating crane assumption: 70 t",
            extractionExtra: ['pages' => [['page' => 1, 'start_char' => 0, 'end_char' => 60]]],
        );

        $private = $this->makeEvidence(
            $circle, $owner, 'Confidential margin analysis', agentRead: false,
            extractedText: 'Target margin 18%. Walk-away price 240k.',
        );

        return [$owner, $circle, $readable, $private];
    }

    public function test_it_retrieves_only_evidence_marked_agent_readable(): void
    {
        [$owner, $circle, $readable, $private] = $this->scenario();

        $this->ai->setResponse(['summary' => 'ok', 'status' => 'on_track', 'claims' => [], 'decision_drafts' => [], 'missing_evidence' => []]);

        $run = app(CircleSteward::class)->runBrief($circle, $owner);

        $this->assertSame(1, $run->retrieval_manifest_json['retrieved']);
        $this->assertStringContainsString('Operating crane assumption: 70 t', $this->ai->lastUserPrompt);

        // The decisive assertion: private content never reached the model.
        $this->assertStringNotContainsString('Walk-away price', $this->ai->lastUserPrompt);
        $this->assertStringNotContainsString('Target margin', $this->ai->lastUserPrompt);
        $this->assertStringNotContainsString($private->id, $this->ai->lastUserPrompt);
    }

    public function test_it_discards_claims_citing_evidence_it_was_never_shown(): void
    {
        [$owner, $circle, $readable] = $this->scenario();
        $realVersion = $readable->currentVersion()->id;

        $this->ai->setResponse([
            'summary' => 'Conflict found.',
            'status'  => 'at_risk',
            'claims'  => [
                [
                    'statement'  => 'Grounded claim citing evidence the agent actually read.',
                    'claim_type' => 'technical_assessment',
                    'confidence' => 0.87,
                    'citations'  => [['evidence_version_id' => $realVersion, 'locator' => ['page' => 1]]],
                ],
                [
                    // A fabricated id — the exact failure mode the citation
                    // allow-list exists to catch.
                    'statement'  => 'Invented claim citing a version that does not exist.',
                    'claim_type' => 'factual',
                    'confidence' => 0.99,
                    'citations'  => [['evidence_version_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']],
                ],
                [
                    // Cites real evidence, but evidence this run was refused.
                    'statement'  => 'Claim citing the confidential item.',
                    'claim_type' => 'commercial_assessment',
                    'confidence' => 0.8,
                    'citations'  => [['evidence_version_id' => $this->scenarioPrivateVersion($circle)]],
                ],
            ],
            'decision_drafts'  => [],
            'missing_evidence' => [],
        ]);

        $run = app(CircleSteward::class)->runBrief($circle, $owner);

        $claims = Claim::where('circle_id', $circle->id)->get();
        $this->assertCount(1, $claims, 'only the grounded claim should survive');
        $this->assertSame('Grounded claim citing evidence the agent actually read.', $claims->first()->statement);

        // The full model output is retained verbatim for inspection, even the
        // parts that were refused persistence.
        $this->assertCount(3, $run->output_json['claims']);

        $audit = AuditEvent::where('circle_id', $circle->id)
            ->where('event_type', 'agent.output_created')->first();
        $this->assertSame(1, $audit->metadata_json['claims']);
        $this->assertSame(2, $audit->metadata_json['claims_rejected']);
    }

    private function scenarioPrivateVersion($circle): string
    {
        return \App\Models\EvidenceItem::query()
            ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
            ->where('agent_read', false)
            ->first()
            ->currentVersion()->id;
    }

    public function test_agent_output_is_always_derived_and_never_approved(): void
    {
        [$owner, $circle, $readable] = $this->scenario();

        $this->ai->setResponse([
            'summary' => 'The bid is at risk because the load schedule and drawing conflict.',
            'status'  => 'at_risk',
            'claims'  => [[
                'statement'  => 'The load schedule specifies 95 t while Rev B assumes 70 t.',
                'claim_type' => 'technical_assessment',
                'confidence' => 0.87,
                'citations'  => [['evidence_version_id' => $readable->currentVersion()->id, 'locator' => ['page' => 1]]],
            ]],
            'decision_drafts' => [[
                'title'                   => 'Confirm crane load basis',
                'description'             => 'Technical lead must confirm the governing assumption.',
                'suggested_approver_role' => 'technical_reviewer',
            ]],
            'missing_evidence' => [['description' => 'Signed freight confirmation']],
        ]);

        app(CircleSteward::class)->runBrief($circle, $owner);

        $claim = Claim::where('circle_id', $circle->id)->firstOrFail();
        $this->assertSame('derived', $claim->status->value, 'agent claims must never enter as reviewed or approved');
        $this->assertSame('agent', $claim->author_type);
        $this->assertNotNull($claim->agent_run_id);
        $this->assertSame('document_page', $claim->citations->first()->citation_type->value);

        $decision = Decision::where('circle_id', $circle->id)->firstOrFail();
        $this->assertSame('draft', $decision->status->value, 'agent decisions must be drafts');
        $this->assertNull($decision->approver_user_id, 'the agent must not assign an approver');
        $this->assertStringContainsString(StewardPrompt::DERIVED_LABEL, $decision->description);

        $brief = DerivedArtifact::where('circle_id', $circle->id)
            ->where('artifact_type', 'agent_summary')->firstOrFail();
        $this->assertSame(StewardPrompt::DERIVED_LABEL, $brief->content_json['label']);
        $this->assertSame('fake', $brief->model_provider);
        $this->assertSame('fake-model-1', $brief->model_name);
        $this->assertSame(StewardPrompt::VERSION, $brief->prompt_version);
        $this->assertNotNull($brief->source_manifest_json);
    }

    public function test_every_retrieval_and_refusal_is_audit_logged(): void
    {
        [$owner, $circle] = $this->scenario();

        $this->ai->setResponse(['summary' => 'ok', 'status' => 'on_track', 'claims' => [], 'decision_drafts' => [], 'missing_evidence' => []]);

        $run = app(CircleSteward::class)->runBrief($circle, $owner);

        $accesses = AgentResourceAccess::where('agent_run_id', $run->id)->get();
        $this->assertCount(1, $accesses, 'only the agent-readable item is even attempted');
        $this->assertTrue($accesses->first()->permitted);

        $types = AuditEvent::where('circle_id', $circle->id)->pluck('event_type')->map->value;
        $this->assertContains('agent.run_started', $types);
        $this->assertContains('agent.resource_retrieved', $types);
        $this->assertContains('agent.output_created', $types);
    }

    public function test_a_closed_circle_blocks_agent_runs(): void
    {
        [$owner, $circle] = $this->scenario();

        $circle->forceFill(['status' => CircleStatus::Archived, 'closed_at' => now()])->save();

        $this->expectException(AuthorisationException::class);
        app(CircleSteward::class)->runBrief($circle->refresh(), $owner);
    }

    public function test_a_member_without_agent_run_permission_cannot_trigger_it(): void
    {
        [$owner, $circle] = $this->scenario();

        $viewer = $this->makeUser('Sam Bright', 'viewer@northernrail.test');
        $this->addMember($circle, $viewer, CircleRole::Viewer, external: true);

        $this->expectException(AuthorisationException::class);
        app(CircleSteward::class)->runBrief($circle, $viewer);
    }

    public function test_a_failed_model_call_still_records_the_run_and_its_manifest(): void
    {
        [$owner, $circle] = $this->scenario();

        $this->app->instance(AiProvider::class, new FakeAiProvider(throws: new \RuntimeException('provider exploded')));

        try {
            app(CircleSteward::class)->runBrief($circle, $owner);
            $this->fail('expected the provider failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('provider exploded', $e->getMessage());
        }

        $run = \App\Models\AgentRun::where('circle_id', $circle->id)->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('provider exploded', $run->error);
        $this->assertNotNull($run->retrieval_manifest_json, 'what was retrieved must survive a failure');

        $types = AuditEvent::where('circle_id', $circle->id)->pluck('event_type')->map->value;
        $this->assertContains('agent.run_failed', $types);
    }

    public function test_the_prompt_forbids_uncited_assertions_and_states_the_agent_cannot_act(): void
    {
        [$owner, $circle] = $this->scenario();

        $this->ai->setResponse(['summary' => 'ok', 'status' => 'on_track', 'claims' => [], 'decision_drafts' => [], 'missing_evidence' => []]);
        app(CircleSteward::class)->runBrief($circle, $owner);

        $system = $this->ai->lastSystemPrompt;
        $this->assertStringContainsString('If you cannot cite a claim, do not make it', $system);
        $this->assertStringContainsString('cannot email, message, or contact anyone', $system);
        $this->assertStringContainsString('draft for human review', $system);

        // The schema must force citations rather than merely request them.
        $claimSchema = $this->ai->lastSchema['properties']['claims']['items'];
        $this->assertContains('citations', $claimSchema['required']);
        $this->assertSame(1, $claimSchema['properties']['citations']['minItems']);
    }

    public function test_truncated_sources_are_flagged_to_the_model(): void
    {
        $owner = $this->makeUser('Dana Okafor', 'gm2@jwamats.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        config(['circle.agent.max_chars_per_source' => 50]);
        $this->makeEvidence($circle, $owner, 'Long report', agentRead: true,
            extractedText: str_repeat('A very long geotechnical narrative. ', 40));

        $this->ai->setResponse(['summary' => 'ok', 'status' => 'on_track', 'claims' => [], 'decision_drafts' => [], 'missing_evidence' => []]);
        app(CircleSteward::class)->runBrief($circle, $owner);

        $this->assertStringContainsString('truncated', $this->ai->lastUserPrompt);
        $this->assertStringContainsString('Do not conclude anything is absent', $this->ai->lastUserPrompt);
    }
}
