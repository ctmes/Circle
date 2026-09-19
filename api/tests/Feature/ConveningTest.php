<?php

namespace Tests\Feature;

use App\Enums\ArtifactType;
use App\Enums\CircleRole;
use App\Enums\CommitmentStatus;
use App\Enums\DecisionStatus;
use App\Jobs\RunStewardBrief;
use App\Models\Commitment;
use App\Models\Decision;
use Illuminate\Support\Facades\Queue;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\CircleParty;
use App\Models\DerivedArtifact;
use App\Models\Goal;
use App\Services\Ai\AiProvider;
use App\Services\Authorisation\AccessGate;
use App\Services\Convening\ConveningPrompt;
use App\Services\Convening\ConveningService;
use App\Services\Goals\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Convening a Circle from an engagement of terms (spec §23).
 *
 * The model's output is scripted throughout, because almost nothing these tests
 * are about is the model's work. What is being pinned here is the half of the
 * feature that is arithmetic: a period becomes a date against a calendar, a
 * flat list of levels becomes a tree, a defined term becomes a party, a page
 * number is checked against a document that has that many pages. All of it is
 * deterministic, and a re-run that produced a different plan from the same
 * output would be a defect in a system of record.
 *
 * The other thing pinned here is the boundary. The Convener proposes; a person
 * accepts. Nothing the model returns reaches the Circle until somebody submits
 * it, and what reaches the Circle is what *they* submitted.
 */
class ConveningTest extends TestCase
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

    /**
     * A subcontract with the shape a real one has: a term expressed as a date,
     * milestones expressed as periods, two parties named by defined term, and
     * one step the document does not actually support.
     */
    private function scenario(bool $agentRead = true): array
    {
        $owner  = $this->makeUser('Dana Okafor', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner, [
            'name'    => 'engagement-of-terms.pdf',
            'purpose' => 'Convening from an uploaded document.',
        ]);

        $document = $this->makeEvidence(
            $circle,
            $owner,
            'Subcontract — Bay Junction rail access',
            agentRead: $agentRead,
            extractedText: "SUBCONTRACT AGREEMENT\nbetween Northline Rail (the Principal)\n"
                . "and JWA Mats Pty Ltd (the Supplier)\n\n"
                . "2.1 The term commences on 1 March 2027.\n"
                . "4.3 The Supplier shall deliver the access matting within 20 business days of commencement.\n",
            extractionExtra: ['pages' => [
                ['page' => 1, 'start_char' => 0, 'end_char' => 120],
                ['page' => 2, 'start_char' => 120, 'end_char' => 240],
            ]],
        );

        return [$owner, $circle, $document];
    }

    /** What the model returns for the scenario. Deliberately imperfect. */
    private function reading(array $overrides = []): array
    {
        return array_merge([
            'mission' => [
                'name'      => 'Bay Junction rail access matting',
                'purpose'   => 'Supply and install access matting for the Bay Junction works.',
                'commences' => ['on' => '2027-03-01'],
                'concludes' => ['after_commencement' => ['value' => 6, 'unit' => 'months']],
                'basis'     => 'stated',
                'citation'  => ['page' => 1, 'excerpt' => 'The term commences on 1 March 2027.'],
            ],
            'parties' => [
                [
                    'display_name' => 'Northline Rail',
                    'defined_term' => 'the Principal',
                    'party_role'   => 'principal',
                    'basis'        => 'stated',
                ],
                [
                    'display_name' => 'JWA Mats Pty Ltd',
                    'defined_term' => 'the Supplier',
                    'party_role'   => 'contractor',
                    'basis'        => 'stated',
                ],
            ],
            'plan' => [
                [
                    'level'                => 1,
                    'title'                => 'Access matting delivered to site',
                    'acceptance_condition' => 'Matting delivered and signed for at the Bay Junction compound.',
                    'responsible_party'    => 'the Supplier',
                    'due'                  => ['after_commencement' => ['value' => 20, 'unit' => 'business_days']],
                    'clause'               => 'cl 4.3',
                    'basis'                => 'stated',
                    'citation'             => ['page' => 1, 'excerpt' => 'within 20 business days of commencement'],
                ],
                [
                    'level'             => 2,
                    'title'             => 'Delivery schedule agreed',
                    'responsible_party' => 'the Supplier',
                    'basis'             => 'inferred',
                ],
            ],
            'open_questions' => [
                ['question' => 'Who provides site access on delivery day?', 'why_it_matters' => 'It sets the delivery window.'],
            ],
        ], $overrides);
    }

    // ------------------------------------------------------- proposing

    public function test_it_proposes_a_plan_and_changes_nothing_about_the_circle(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);

        $this->assertSame(ArtifactType::ConvenedPlan, $artifact->artifact_type);
        $this->assertSame('ready', $artifact->status);

        // The Circle is untouched. Everything the model said is on the artifact
        // and nowhere else until somebody accepts it.
        $this->assertSame('engagement-of-terms.pdf', $circle->fresh()->name);
        $this->assertSame(0, Goal::where('circle_id', $circle->id)->count());
        $this->assertSame(0, CircleParty::where('circle_id', $circle->id)->count());

        $this->assertDatabaseHas('audit_events', [
            'circle_id'  => $circle->id,
            'event_type' => 'circle.convened',
        ]);
    }

    public function test_it_reads_only_the_document_it_was_pointed_at(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->makeEvidence($circle, $owner, 'Site photographs', agentRead: true, extractedText: 'Photo log.');

        $this->ai->setResponse($this->reading());

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);

        $this->assertSame(1, $artifact->source_manifest_json['retrieved']);
        $this->assertStringContainsString('Subcontract', $this->ai->lastUserPrompt);
        $this->assertStringNotContainsString('Photo log', $this->ai->lastUserPrompt);
    }

    public function test_it_refuses_a_document_that_is_not_marked_agent_readable(): void
    {
        [$owner, $circle, $document] = $this->scenario(agentRead: false);

        $this->ai->setResponse($this->reading());

        $this->expectExceptionMessage('No readable document was available.');

        app(ConveningService::class)->propose($circle, $owner, [$document->id]);
    }

    /**
     * The Steward's per-source cap is sized for forty documents. Applied here it
     * cut a nine-page contract at page five, and the model could only ask
     * whether the liability clauses it never saw existed.
     */
    public function test_it_reads_a_named_contract_past_the_stewards_per_source_cap(): void
    {
        config(['circle.agent.max_chars_per_source' => 50]);

        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        app(ConveningService::class)->propose($circle, $owner, [$document->id]);

        // Clause 4.3 is the last line of the document.
        $this->assertStringContainsString('4.3 The Supplier shall deliver', $this->ai->lastUserPrompt);
        $this->assertStringNotContainsString('NOTE: truncated', $this->ai->lastUserPrompt);
    }

    public function test_the_budget_is_shared_across_named_documents_and_what_it_cuts_is_flagged(): void
    {
        config([
            'circle.agent.max_chars_per_source'      => 20,
            'circle.agent.tasks.convening.max_chars' => 120,
        ]);

        [$owner, $circle, $document] = $this->scenario();
        $rates = $this->makeEvidence($circle, $owner, 'Schedule of rates', agentRead: true,
            extractedText: str_repeat('Rate per mat per week. ', 20));

        $this->ai->setResponse($this->reading());

        app(ConveningService::class)->propose($circle, $owner, [$document->id, $rates->id]);

        $this->assertStringContainsString('NOTE: truncated — showing 60 of', $this->ai->lastUserPrompt);
        $this->assertStringNotContainsString('4.3 The Supplier shall deliver', $this->ai->lastUserPrompt);
    }

    /**
     * The mandate, not the prompt, is what stops the Convener writing.
     *
     * It holds `read_only`, whose ceiling is two permissions, so the gate
     * refuses it a goal before any code in the convening path is reached. This
     * is the reason the plan can be produced by an agent and still be nobody's
     * commitment.
     */
    public function test_the_convener_cannot_write_to_the_circle_at_all(): void
    {
        [, $circle] = $this->scenario();

        $agent = app(ConveningService::class)->instanceFor($circle);
        $gate  = app(AccessGate::class);

        $this->assertTrue($gate->allows($agent, Permission::ResourceAgentRead, $circle));

        foreach ([Permission::GoalCreate, Permission::ClaimCreate, Permission::PartyManage] as $permission) {
            $this->assertFalse(
                $gate->allows($agent, $permission, $circle),
                "The Convener must not hold {$permission->value}.",
            );
        }
    }

    // -------------------------------------------------------- resolving

    public function test_it_resolves_periods_against_commencement_instead_of_asking_the_model(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        // 1 March 2027 is a Sunday; twenty business days lands on 27 March.
        $this->assertSame('2027-03-01', $plan['anchor_date']);
        $this->assertSame('2027-03-29', $plan['plan'][0]['due_on']);
        $this->assertStringContainsString('20 business days after 2027-03-01', $plan['plan'][0]['due_note']);
        $this->assertStringContainsString('weekends excluded', $plan['plan'][0]['due_note']);

        // Six months is a calendar question, not ninety days plus ninety days.
        $this->assertSame('2027-09-01', $plan['mission']['concludes_on']);
    }

    public function test_a_different_start_date_re_resolves_the_same_proposal_without_a_second_model_call(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        $convening = app(ConveningService::class);
        $artifact  = $convening->propose($circle, $owner, [$document->id]);

        $this->assertSame(1, $this->ai->calls);

        $moved = $convening->reresolve($artifact, new \DateTimeImmutable('2027-06-01'));

        $this->assertSame(1, $this->ai->calls, 'Re-anchoring must not call the model again.');
        $this->assertSame('2027-06-01', $moved['anchor_date']);
        // 1 June 2027 is a Tuesday; twenty business days lands on 29 June.
        $this->assertSame('2027-06-29', $moved['plan'][0]['due_on']);
        $this->assertSame('2027-12-01', $moved['mission']['concludes_on']);

        // A date the document stated does not move when the anchor does.
        $this->assertSame($artifact->content_json['plan']['plan'][0]['title'], $moved['plan'][0]['title']);
    }

    public function test_it_builds_the_tree_from_levels(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        $this->assertCount(1, $plan['plan']);
        $this->assertCount(1, $plan['plan'][0]['children']);
        $this->assertSame('Delivery schedule agreed', $plan['plan'][0]['children'][0]['title']);
        $this->assertSame(2, $plan['counts']['steps']);
        $this->assertSame(1, $plan['counts']['steps_inferred']);
    }

    public function test_it_repairs_a_level_that_skips_a_step_and_says_so(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['plan' => [
            ['level' => 1, 'title' => 'Mobilisation complete'],
            ['level' => 3, 'title' => 'Compound established'],
        ]]));

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        $this->assertCount(1, $plan['plan']);
        $this->assertSame('Compound established', $plan['plan'][0]['children'][0]['title']);
        $this->assertNotEmpty(array_filter(
            $plan['notes'],
            fn (string $n) => str_contains($n, 'skipped a level'),
        ));
    }

    public function test_it_matches_a_step_to_the_party_by_the_term_the_document_uses(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        $supplier = collect($plan['parties'])->firstWhere('display_name', 'JWA Mats Pty Ltd');

        $this->assertSame($supplier['key'], $plan['plan'][0]['responsible_party_key']);
        $this->assertSame(2, $plan['counts']['steps_assigned']);
    }

    public function test_it_leaves_a_step_unassigned_when_the_party_was_never_named(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['plan' => [
            ['level' => 1, 'title' => 'Geotechnical survey issued', 'responsible_party' => 'Vector Geotechnics'],
        ]]));

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        $this->assertNull($plan['plan'][0]['responsible_party_key']);
        $this->assertSame('Vector Geotechnics', $plan['plan'][0]['responsible_as_written']);
        $this->assertNotEmpty(array_filter(
            $plan['notes'],
            fn (string $n) => str_contains($n, 'is not one of the parties'),
        ));
    }

    public function test_it_drops_a_citation_naming_a_page_the_document_does_not_have(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['plan' => [
            [
                'level'    => 1,
                'title'    => 'Matting delivered',
                'citation' => ['page' => 9, 'excerpt' => 'within 20 business days'],
            ],
            [
                'level'    => 1,
                'title'    => 'Site demobilised',
                'citation' => ['page' => 14],
            ],
        ]]));

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        // The page went; the quotation is still something a reader can search
        // the document for, so it stays.
        $this->assertArrayNotHasKey('page', $plan['plan'][0]['citation']);
        $this->assertSame('within 20 business days', $plan['plan'][0]['citation']['excerpt']);

        // Nothing left to point at, so the citation goes entirely.
        $this->assertNull($plan['plan'][1]['citation']);
        $this->assertSame(1, $plan['counts']['citations_rejected']);
    }

    public function test_it_lists_one_party_when_the_same_company_is_proposed_twice(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['parties' => [
            ['display_name' => 'Northline Rail', 'party_role' => 'principal'],
            ['display_name' => 'northline rail.', 'party_role' => 'observer'],
        ]]));

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        $this->assertSame(1, $plan['counts']['parties']);
        $this->assertSame('principal', $plan['parties'][0]['party_role']);
    }

    /**
     * The convening seat belongs to the company that opened the Circle, and a
     * model reading a contract is in no position to reassign it.
     */
    public function test_it_will_not_let_the_model_name_a_convener(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['parties' => [
            ['display_name' => 'Northline Rail', 'party_role' => 'convener'],
        ]]));

        $plan = app(ConveningService::class)
            ->propose($circle, $owner, [$document->id])
            ->content_json['plan'];

        $this->assertSame('observer', $plan['parties'][0]['party_role']);
    }

    // ------------------------------------------------------- accepting

    /** The resolved plan, flattened into the shape the accept endpoint takes. */
    private function payloadFrom(array $plan, ?array $only = null): array
    {
        $steps = [];

        $walk = function (array $nodes, ?string $parentKey) use (&$walk, &$steps, $only): void {
            foreach ($nodes as $node) {
                $keep = $only === null || in_array($node['key'], $only, true);

                if ($keep) {
                    $steps[] = [
                        'key'                   => $node['key'],
                        'parent_key'            => $parentKey,
                        'title'                 => $node['title'],
                        'description'           => $node['description'],
                        'acceptance_condition'  => $node['acceptance_condition'],
                        'responsible_party_key' => $node['responsible_party_key'],
                        'starts_on'             => $node['starts_on'],
                        'due_on'                => $node['due_on'],
                        'clause'                => $node['clause'],
                    ];
                }

                $walk($node['children'], $keep ? $node['key'] : $parentKey);
            }
        };

        $walk($plan['plan'], null);

        return [
            'name'       => $plan['mission']['name'],
            'purpose'    => $plan['mission']['purpose'],
            'starts_at'  => $plan['mission']['commences_on'],
            'expires_at' => $plan['mission']['concludes_on'],
            'parties'    => array_map(fn (array $p) => [
                'key'          => $p['key'],
                'display_name' => $p['display_name'],
                'party_role'   => $p['party_role'],
            ], $plan['parties']),
            'steps'          => $steps,
            'open_questions' => $plan['open_questions'],
        ];
    }

    public function test_accepting_writes_the_plan_as_the_work_of_the_person_who_accepted_it(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($owner);

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);
        $payload  = $this->payloadFrom($artifact->content_json['plan']);

        $this->postJson("/api/circles/{$circle->id}/convening/{$artifact->id}/accept", $payload)
            ->assertOk()
            ->assertJsonPath('data.goals', 2)
            ->assertJsonPath('data.parties', 2);

        $circle->refresh();
        $this->assertSame('Bay Junction rail access matting', $circle->name);
        $this->assertSame('2027-03-01', $circle->starts_at->toDateString());
        $this->assertSame('2027-09-01', $circle->expires_at->toDateString());

        $root = Goal::where('circle_id', $circle->id)->whereNull('parent_goal_id')->firstOrFail();

        // Attributed to the person, not to the agent. This is the whole point
        // of splitting the proposal from the acceptance: a plan in the record
        // is somebody's, and the somebody is whoever read it and said yes.
        $this->assertSame('user', $root->created_by_type);
        $this->assertSame($owner->id, $root->created_by_id);
        $this->assertSame('2027-03-29', $root->due_at->toDateString());
        $this->assertStringContainsString('signed for at the Bay Junction compound', $root->acceptance_condition);
        $this->assertSame('JWA Mats Pty Ltd', $root->responsibleParty->display_name);

        // The clause travels onto the goal, because that is what somebody types
        // into the search box of the PDF when they want to check it.
        $this->assertStringContainsString('cl 4.3', (string) $root->description);

        $this->assertSame(1, Goal::where('parent_goal_id', $root->id)->count());

        $this->assertDatabaseHas('audit_events', [
            'circle_id'  => $circle->id,
            'event_type' => 'circle.plan_accepted',
        ]);

        // The rename went through the chain with a before and after, like every
        // other restatement of what the mission is.
        $renamed = AuditEvent::where('circle_id', $circle->id)
            ->where('event_type', 'circle.details_changed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('engagement-of-terms.pdf', $renamed->metadata_json['changes']['name']['from']);
        $this->assertStringContainsString('Convened from', $renamed->metadata_json['reason']);
    }

    public function test_what_is_written_is_what_the_person_submitted_not_what_the_model_said(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($owner);

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);
        $payload  = $this->payloadFrom($artifact->content_json['plan']);

        $payload['name'] = 'Bay Junction - matting supply';
        $payload['steps'][0]['title'] = 'Matting on site and signed for';

        $this->postJson("/api/circles/{$circle->id}/convening/{$artifact->id}/accept", $payload)->assertOk();

        $this->assertSame('Bay Junction - matting supply', $circle->fresh()->name);
        $this->assertTrue(Goal::where('circle_id', $circle->id)
            ->where('title', 'Matting on site and signed for')->exists());

        // The proposal is unchanged, and now carries both readings.
        $artifact->refresh();
        $this->assertSame('applied', $artifact->status);
        $this->assertSame(
            'Access matting delivered to site',
            $artifact->content_json['plan']['plan'][0]['title'],
        );
        $this->assertSame(
            'Matting on site and signed for',
            $artifact->content_json['accepted_payload']['steps'][0]['title'],
        );
    }

    public function test_a_step_whose_parent_was_dropped_is_kept_at_the_root(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($owner);

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);
        $plan     = $artifact->content_json['plan'];

        // Keep only the child. Work that vanishes silently between a review
        // screen and a plan is the failure this flow exists to avoid.
        $payload = $this->payloadFrom($plan, only: ['n2']);

        $this->postJson("/api/circles/{$circle->id}/convening/{$artifact->id}/accept", $payload)
            ->assertOk()
            ->assertJsonPath('data.goals', 1);

        $goal = Goal::where('circle_id', $circle->id)->firstOrFail();

        $this->assertSame('Delivery schedule agreed', $goal->title);
        $this->assertNull($goal->parent_goal_id);
    }

    public function test_a_plan_cannot_be_accepted_twice(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($owner);

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);
        $payload  = $this->payloadFrom($artifact->content_json['plan']);

        $this->postJson("/api/circles/{$circle->id}/convening/{$artifact->id}/accept", $payload)->assertOk();

        $this->postJson("/api/circles/{$circle->id}/convening/{$artifact->id}/accept", $payload)
            ->assertStatus(422);

        $this->assertSame(2, Goal::where('circle_id', $circle->id)->count());
    }

    public function test_accepting_reuses_a_party_the_circle_already_has(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $existing = CircleParty::create([
            'circle_id'    => $circle->id,
            'display_name' => 'Northline Rail',
            'party_role'   => 'principal',
            'status'       => 'active',
            'is_convener'  => false,
        ]);

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($owner);

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);

        $this->postJson(
            "/api/circles/{$circle->id}/convening/{$artifact->id}/accept",
            $this->payloadFrom($artifact->content_json['plan']),
        )->assertOk();

        $this->assertSame(
            1,
            CircleParty::where('circle_id', $circle->id)->where('display_name', 'Northline Rail')->count(),
        );
        $this->assertNotNull($existing->fresh());
    }

    public function test_a_contributor_cannot_accept_a_plan(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $contributor = $this->makeUser('Sam Reyes', 'sam@jwamats.test');
        $this->addMember($circle, $contributor, CircleRole::Contributor);

        $this->ai->setResponse($this->reading());

        $artifact = app(ConveningService::class)->propose($circle, $owner, [$document->id]);
        $payload  = $this->payloadFrom($artifact->content_json['plan']);

        Sanctum::actingAs($contributor);

        $this->postJson("/api/circles/{$circle->id}/convening/{$artifact->id}/accept", $payload)
            ->assertStatus(403);

        $this->assertSame(0, Goal::where('circle_id', $circle->id)->count());
    }

    public function test_the_latest_proposal_can_be_read_back_and_re_anchored_over_http(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($owner);

        app(ConveningService::class)->propose($circle, $owner, [$document->id]);

        $this->getJson("/api/circles/{$circle->id}/convening")
            ->assertOk()
            ->assertJsonPath('data.plan.plan.0.due_on', '2027-03-29')
            ->assertJsonPath('data.label', ConveningPrompt::DERIVED_LABEL);

        $this->getJson("/api/circles/{$circle->id}/convening?anchor=2027-06-01")
            ->assertOk()
            ->assertJsonPath('data.plan.plan.0.due_on', '2027-06-29');

        $this->assertSame(1, $this->ai->calls);
    }

    public function test_a_circle_with_no_proposal_answers_with_nothing(): void
    {
        [$owner, $circle] = $this->scenario();

        Sanctum::actingAs($owner);

        $this->getJson("/api/circles/{$circle->id}/convening")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame(0, DerivedArtifact::where('artifact_type', ArtifactType::ConvenedPlan->value)->count());
    }

    // ----------------------------------------------- the whole Circle at once

    /**
     * A plan with the three shapes that decide what becomes a commitment: a
     * phase with work under it, a leaf nobody dated, and a dated deliverable.
     */
    private function richPlan(): array
    {
        return [
            [
                'level'             => 1,
                'title'             => 'Mobilisation complete',
                'responsible_party' => 'the Supplier',
                'due'               => ['after_commencement' => ['value' => 2, 'unit' => 'weeks']],
                'clause'            => 'cl 3.1',
                'basis'             => 'stated',
            ],
            [
                'level' => 2,
                'title' => 'Traffic management plan agreed',
                'basis' => 'inferred',
            ],
            [
                'level'                => 2,
                'title'                => 'Access matting delivered to site',
                'acceptance_condition' => 'Delivered and signed for at the Bay Junction compound.',
                'responsible_party'    => 'the Supplier',
                'due'                  => ['after_commencement' => ['value' => 20, 'unit' => 'business_days']],
                'clause'               => 'cl 4.3',
                'basis'                => 'stated',
            ],
        ];
    }

    public function test_convening_builds_the_whole_circle_in_one_call(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['plan' => $this->richPlan()]));

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])->assertCreated();

        $response->assertJsonPath('data.applied', true);
        $response->assertJsonPath('data.created.goals', 3);
        $response->assertJsonPath('data.created.parties', 2);
        $response->assertJsonPath('data.created.decisions', 1);

        $circle->refresh();
        $this->assertSame('Bay Junction rail access matting', $circle->name);
        $this->assertSame('2027-03-01', $circle->starts_at->toDateString());
        $this->assertSame('2027-09-01', $circle->expires_at->toDateString());

        $this->assertSame(3, Goal::where('circle_id', $circle->id)->count());
        $this->assertSame(2, CircleParty::where('circle_id', $circle->id)->count());
        $this->assertSame(1, Decision::where('circle_id', $circle->id)->count());

        // Nothing was left for a person to press. The Circle is live.
        $this->assertDatabaseHas('audit_events', [
            'circle_id'  => $circle->id,
            'event_type' => 'circle.plan_accepted',
        ]);
    }

    /**
     * A reading never fills the tree to its cap.
     *
     * A plan read to the full depth left every one of its leaves at the cap, so
     * the first thing anybody tried — breaking a convened deliverable down into
     * the work it takes — was refused on every row the machine wrote. One level
     * is kept back for people, and the deepest convened job then takes a
     * sub-job and an edit exactly as one somebody typed would.
     */
    public function test_a_convened_plan_leaves_a_level_free_for_people(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $cap = GoalService::maxDepth();

        $this->ai->setResponse($this->reading(['plan' => array_map(
            fn (int $level) => ['level' => $level, 'title' => "Level {$level} work", 'basis' => 'stated'],
            range(1, $cap),
        )]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])->assertCreated()->assertJsonPath('data.applied', true);

        $deepest = Goal::where('circle_id', $circle->id)->where('title', "Level {$cap} work")->firstOrFail();

        // Raised one short of the cap, and the job page is told where it sits.
        $this->getJson("/api/goals/{$deepest->id}")
            ->assertOk()
            ->assertJsonPath('data.depth', $cap - 2);

        $child = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'          => 'Matting laid out on the compound plan',
            'parent_goal_id' => $deepest->id,
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/goals/{$child}")
            ->assertOk()
            ->assertJsonPath('data.depth', $cap - 1);

        $this->patchJson("/api/goals/{$deepest->id}", ['title' => 'Matting delivered and laid'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Matting delivered and laid');
    }

    /**
     * A deliverable is a leaf with a date. Both halves are structural, so both
     * are decided in PHP rather than asked of the model.
     */
    public function test_only_a_dated_leaf_becomes_a_commitment(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['plan' => $this->richPlan()]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])->assertCreated()->assertJsonPath('data.created.commitments', 1);

        $commitments = Commitment::where('circle_id', $circle->id)->get();

        $this->assertCount(1, $commitments);

        $commitment = $commitments->first();

        // The dated deliverable, not the phase above it and not its undated sibling.
        $this->assertSame('Access matting delivered to site', $commitment->title);
        $this->assertSame('2027-03-29', $commitment->due_at->toDateString());
        $this->assertSame(CommitmentStatus::Open, $commitment->status);
        $this->assertStringContainsString('signed for at the Bay Junction compound', $commitment->acceptance_condition);

        // Owed by a company, not by a person nobody has named.
        $this->assertNull($commitment->owner_user_id);
        $this->assertSame('JWA Mats Pty Ltd', $commitment->ownerParty->display_name);

        // Hung off the goal it came from, so the node reads as one piece of
        // work with a date against it rather than appearing twice.
        $this->assertSame(
            'Access matting delivered to site',
            Goal::find($commitment->goal_id)?->title,
        );
    }

    public function test_the_document_is_filed_against_every_goal_it_produced(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['plan' => $this->richPlan()]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])->assertCreated()->assertJsonPath('data.created.filings', 3);

        foreach (Goal::where('circle_id', $circle->id)->get() as $goal) {
            $this->assertTrue(
                $goal->evidence()->whereKey($document->id)->exists(),
                "The contract should be filed against “{$goal->title}”.",
            );
        }
    }

    /**
     * A contract and its schedule convened together. A clause reference names
     * the document its step was cited from, never just the first one read.
     */
    public function test_several_documents_are_named_where_each_step_came_from(): void
    {
        [$owner, $circle, $document] = $this->scenario();
        $schedule = $this->makeEvidence($circle, $owner, 'Schedule 2 — rates', agentRead: true,
            extractedText: 'S2.1 Matting is hired at $40 per mat per week.');

        $this->ai->setResponse($this->reading([
            'plan' => [
                [
                    'level'    => 1,
                    'title'    => 'Hire rates agreed',
                    'clause'   => 'S2.1',
                    'basis'    => 'stated',
                    'citation' => [
                        'evidence_version_id' => $schedule->currentVersion()->id,
                        'excerpt'             => 'Matting is hired at $40 per mat per week.',
                    ],
                ],
                ['level' => 1, 'title' => 'Access matting delivered', 'clause' => 'cl 4.3', 'basis' => 'stated'],
            ],
        ]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id, $schedule->id],
            'brief'             => false,
        ])->assertCreated()->assertJsonPath('data.created.goals', 2);

        $this->assertStringEndsWith(
            '[S2.1, Schedule 2 — rates]',
            Goal::where('title', 'Hire rates agreed')->value('description'),
        );

        // Uncited, so it names no document rather than guessing the first one.
        $this->assertSame('[cl 4.3]', Goal::where('title', 'Access matting delivered')->value('description'));

        $this->assertStringContainsString(
            'convening from Subcontract — Bay Junction rail access, Schedule 2 — rates. The documents do not settle it.',
            Decision::where('circle_id', $circle->id)->value('description'),
        );
    }

    public function test_open_questions_become_draft_decisions_nobody_owns_yet(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['open_questions' => [
            ['question' => 'Who provides traffic control?', 'why_it_matters' => 'It sets the delivery window.'],
            ['question' => 'What is the liquidated damages rate?'],
        ]]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])->assertCreated()->assertJsonPath('data.created.decisions', 2);

        $decision = Decision::where('circle_id', $circle->id)
            ->where('title', 'Who provides traffic control?')
            ->firstOrFail();

        // Draft, with nobody named. A decision only becomes actionable when
        // somebody is put against it, and nothing here may do that for them.
        $this->assertSame(DecisionStatus::Draft, $decision->status);
        $this->assertNull($decision->approver_user_id);
        $this->assertStringContainsString('It sets the delivery window.', $decision->description);
        $this->assertStringContainsString('Name who decides.', $decision->description);
    }

    public function test_the_steward_is_asked_for_an_opening_brief(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        Queue::fake();

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
        ])->assertCreated();

        Queue::assertPushed(
            RunStewardBrief::class,
            fn (RunStewardBrief $job) => $job->circleId === $circle->id
                && $job->triggeredByUserId === $owner->id,
        );
    }

    /**
     * Convening a past engagement to build its record is a legitimate thing to
     * do, and it must not hand somebody a Circle the gate refuses on the next
     * request. The date is on the acceptance event either way.
     */
    public function test_a_contract_that_has_already_concluded_does_not_expire_the_circle(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['mission' => [
            'name'      => 'Last season matting supply',
            'purpose'   => 'Supply matting for the works that finished in March.',
            'commences' => ['on' => '2025-01-06'],
            'concludes' => ['on' => '2025-03-31'],
            'basis'     => 'stated',
        ]]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])->assertCreated()->assertJsonPath('data.applied', true);

        $circle->refresh();

        $this->assertSame('2025-01-06', $circle->starts_at->toDateString());

        // The concluded date was not written onto the Circle, and whatever
        // expiry it already had was left alone — declining to enforce a past
        // date is not licence to clear a live one somebody else set.
        $this->assertNotSame('2025-03-31', $circle->expires_at?->toDateString());
        $this->assertFalse($circle->isExpired());

        $accepted = AuditEvent::where('circle_id', $circle->id)
            ->where('event_type', 'circle.plan_accepted')
            ->firstOrFail();

        $this->assertSame('2025-03-31', $accepted->metadata_json['concludes_on']);
        $this->assertFalse($accepted->metadata_json['expiry_set']);
    }

    /**
     * The reading is worth having even when the reader cannot write it in.
     * Losing a model call because the last of three permissions was missing
     * would be a poor trade for a check that could have been reported.
     */
    public function test_someone_who_cannot_write_still_gets_the_reading(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        // A reviewer may run an agent and may not restate what the mission is,
        // which is exactly the gap this path has to answer for.
        $reviewer = $this->makeUser('Sam Reyes', 'sam@jwamats.test');
        $this->addMember($circle, $reviewer, CircleRole::Reviewer);

        $this->ai->setResponse($this->reading());

        Sanctum::actingAs($reviewer);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.blocked_by', 'circle.manage_members')
            ->assertJsonPath('data.plan.counts.steps', 2);

        $this->assertSame(0, Goal::where('circle_id', $circle->id)->count());
        $this->assertSame(0, CircleParty::where('circle_id', $circle->id)->count());
    }

    public function test_convening_a_second_time_reads_but_does_not_double_the_plan(): void
    {
        [$owner, $circle, $document] = $this->scenario();

        $this->ai->setResponse($this->reading(['plan' => $this->richPlan()]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])->assertCreated()->assertJsonPath('data.created.goals', 3);

        // A second reading is a legitimate act — it is how a variation arrives,
        // and how somebody re-reads a contract after the extraction improved.
        // What it must not do is write the plan in again on top of itself.
        $this->postJson("/api/circles/{$circle->id}/convening", [
            'evidence_item_ids' => [$document->id],
            'brief'             => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.blocked_by', 'circle_already_has_a_plan')
            ->assertJsonPath('data.plan.counts.steps', 3);

        $this->assertSame(3, Goal::where('circle_id', $circle->id)->count());
        $this->assertSame(2, CircleParty::where('circle_id', $circle->id)->count());
        $this->assertSame(1, Commitment::where('circle_id', $circle->id)->count());
    }
}
