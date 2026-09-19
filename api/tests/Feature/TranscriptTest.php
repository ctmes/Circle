<?php

namespace Tests\Feature;

use App\Enums\AgentActionStatus;
use App\Enums\CircleRole;
use App\Enums\CommitmentStatus;
use App\Enums\GoalStatus;
use App\Jobs\RunStewardBrief;
use App\Models\AgentAction;
use App\Models\AgentBlueprint;
use App\Models\AgentTool;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Commitment;
use App\Models\EvidenceItem;
use App\Models\Goal;
use App\Models\GoalScheduleChange;
use App\Models\TranscriptImport;
use App\Services\Agent\AgentActionService;
use App\Services\Ai\AiProvider;
use App\Services\Transcripts\TranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Meeting transcripts keeping the plan current with nobody checking (spec §24).
 *
 * The queue runs synchronously here, so posting a transcript routes, files and
 * applies it before the request returns. The model is scripted throughout:
 * what these tests pin is everything around it — which writes may happen
 * unattended and which may not, that every one of them lands on the ledger
 * with the words it rests on, that dates are counted rather than asked for,
 * and that a token pasted into somebody's automation reaches one endpoint.
 */
class TranscriptTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    private FakeAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
        Mail::fake();
        Storage::fake('evidence');

        // The Steward's brief behind a convened Circle is its own concern and
        // its own model call; these tests are about the transcript path.
        Queue::fake([RunStewardBrief::class]);

        $this->ai = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->ai);
    }

    /** A Circle part-way through, with a phase, two packages and a dated one. */
    private function underway(): array
    {
        $owner  = $this->makeUser('Dana Okafor', 'gm@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner, ['name' => 'Bay Junction access matting']);

        $northline = CircleParty::create([
            'circle_id' => $circle->id, 'display_name' => 'Northline Rail',
            'party_role' => 'principal', 'status' => 'active', 'is_convener' => false,
        ]);

        $phase = Goal::create([
            'circle_id' => $circle->id, 'title' => 'Mobilisation', 'status' => 'active',
            'position' => 1, 'created_by_type' => 'user', 'created_by_id' => $owner->id,
        ]);

        $pad = Goal::create([
            'circle_id' => $circle->id, 'parent_goal_id' => $phase->id, 'title' => 'Crane pad laid',
            'status' => 'active', 'position' => 1, 'due_at' => '2027-03-29',
            'created_by_type' => 'user', 'created_by_id' => $owner->id,
        ]);

        $route = Goal::create([
            'circle_id' => $circle->id, 'parent_goal_id' => $phase->id, 'title' => 'Second haul route matted',
            'status' => 'active', 'position' => 2,
            'created_by_type' => 'user', 'created_by_id' => $owner->id,
        ]);

        $commitment = Commitment::create([
            'circle_id' => $circle->id, 'goal_id' => $route->id, 'title' => 'Order extra mats',
            'status' => CommitmentStatus::Open, 'created_by_type' => 'user', 'created_by_user_id' => $owner->id,
        ]);

        return compact('owner', 'org', 'circle', 'northline', 'phase', 'pad', 'route', 'commitment');
    }

    private function reading(array $operations, string $summary = 'The pad is down; the second route is dropped.'): array
    {
        return ['summary' => $summary, 'operations' => $operations, 'uncertainty' => ''];
    }

    private function send(Circle $circle, string $text = 'Dana: the crane pad went down Tuesday.', array $extra = [])
    {
        return $this->postJson("/api/circles/{$circle->id}/transcripts", array_merge([
            'text'        => $text,
            'title'       => 'Weekly site meeting',
            'occurred_at' => '2027-03-10',
        ], $extra));
    }

    // -------------------------------------------------------- the boundary

    /**
     * Autonomy is decided by consequence, not by configuration. The Scribe is
     * autonomous and its in-Circle writes run on proposal — but the same agent
     * proposing something that leaves the Circle waits for a person, however
     * the blueprint is flagged.
     */
    public function test_autonomy_reaches_in_circle_writes_and_nothing_further(): void
    {
        ['circle' => $circle] = $this->underway();

        $agent     = app(TranscriptService::class)->scribeFor($circle);
        $blueprint = $agent->blueprint;
        $actions   = app(AgentActionService::class);

        $inside = $actions->propose(
            $agent,
            AgentTool::where('agent_blueprint_id', $blueprint->id)->where('key', 'create_goal')->firstOrFail(),
            ['title' => 'Traffic plan issued'],
        );

        $this->assertSame(AgentActionStatus::Approved, $inside->status);
        $this->assertNull($inside->approved_by_user_id);

        $party = CircleParty::where('circle_id', $circle->id)->firstOrFail();

        $outside = $actions->propose(
            $agent,
            AgentTool::create([
                'agent_blueprint_id' => $blueprint->id, 'key' => 'email_counterparty', 'name' => 'Email',
                'description' => 'Send an email.', 'side_effect' => 'external_write',
                'requires_approval' => true, 'enabled' => true, 'input_schema_json' => [],
            ]),
            ['to' => 'someone@northline.test'],
            onBehalfOf: $party,
        );

        $this->assertSame(AgentActionStatus::AwaitingApproval, $outside->status);
    }

    public function test_an_agent_a_customer_wrote_is_never_autonomous_by_default(): void
    {
        $this->assertFalse((bool) AgentBlueprint::where('key', AgentBlueprint::STEWARD)->value('autonomous'));
        $this->assertTrue((bool) AgentBlueprint::where('key', AgentBlueprint::SCRIBE)->value('autonomous'));
    }

    // ---------------------------------------------------------- operations

    public function test_a_meeting_updates_the_plan_with_nobody_checking(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'complete_goal', 'goal_id' => $s['pad']->id, 'evidence' => 'the crane pad went down Tuesday'],
            ['op' => 'abandon_goal', 'goal_id' => $s['route']->id, 'evidence' => 'we are not doing the second route',
             'reason' => 'Northline dropped the second haul route.'],
            ['op' => 'create_goal', 'title' => 'Traffic management plan issued', 'parent_goal_id' => $s['phase']->id,
             'responsible_party_id' => $s['northline']->id, 'evidence' => 'Northline will issue the TMP',
             'due' => ['from_meeting' => ['value' => 10, 'unit' => 'business_days']]],
        ]));

        $this->send($s['circle'])->assertStatus(202);

        $import = TranscriptImport::firstOrFail();

        $this->assertSame(TranscriptImport::APPLIED, $import->status);
        $this->assertSame('pinned', $import->routing);
        // assertEquals, not assertSame: jsonb does not keep key order.
        $this->assertEquals(['created' => 1, 'updated' => 0, 'completed' => 1, 'abandoned' => 1,
            'commitments' => 0, 'failed' => 0, 'skipped' => 0], $import->result_json['counts']);

        $this->assertSame(GoalStatus::Met, $s['pad']->fresh()->status);
        $this->assertSame(GoalStatus::Abandoned, $s['route']->fresh()->status);

        $tmp = Goal::where('title', 'Traffic management plan issued')->firstOrFail();

        $this->assertSame($s['phase']->id, $tmp->parent_goal_id);
        $this->assertSame($s['northline']->id, $tmp->responsible_party_id);
        // Ten business days from Wednesday 10 March 2027 is Wednesday the 24th.
        $this->assertSame('2027-03-24', $tmp->due_at->toDateString());

        $this->assertSame('transcript', $this->ai->lastTask);
    }

    /**
     * Every change is on the ledger, attributed to the agent, approved by
     * nobody, with the words from the meeting as its intent. That record is
     * what makes unattended writes something a person can audit afterwards.
     */
    public function test_every_change_is_on_the_ledger_with_the_words_it_rests_on(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'complete_goal', 'goal_id' => $s['pad']->id, 'evidence' => 'the crane pad went down Tuesday'],
        ]));

        $this->send($s['circle']);

        $action = AgentAction::where('tool_key', 'complete_goal')->firstOrFail();

        $this->assertSame(AgentActionStatus::Executed, $action->status);
        $this->assertNull($action->approved_by_user_id);
        $this->assertStringContainsString('the crane pad went down Tuesday', $action->intent);

        $this->assertDatabaseHas('audit_events', ['circle_id' => $s['circle']->id, 'event_type' => 'transcript.applied']);
        $this->assertDatabaseHas('audit_events', ['circle_id' => $s['circle']->id, 'event_type' => 'agent.action_executed']);
    }

    /**
     * Closed by a transcript is not the same as accepted by a person, and the
     * record says which. The goal is settled; nobody is named as accepting it;
     * the run that closed it is.
     */
    public function test_a_goal_closed_by_a_meeting_names_the_reading_not_a_person(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'complete_goal', 'goal_id' => $s['pad']->id, 'evidence' => 'pad is done'],
        ]));

        $this->send($s['circle']);

        $pad = $s['pad']->fresh();

        $this->assertSame(GoalStatus::Met, $pad->status);
        $this->assertSame(100, (int) $pad->progress);
        $this->assertNotNull($pad->accepted_at);
        $this->assertNull($pad->accepted_by_user_id);
        $this->assertSame(TranscriptImport::firstOrFail()->agent_run_id, $pad->completed_by_agent_run_id);
    }

    public function test_completing_a_phase_completes_the_open_work_inside_it(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'complete_goal', 'goal_id' => $s['phase']->id, 'evidence' => 'mobilisation is finished'],
        ]));

        $this->send($s['circle']);

        foreach (['phase', 'pad', 'route'] as $key) {
            $this->assertSame(GoalStatus::Met, $s[$key]->fresh()->status, "{$key} should be met");
        }
    }

    /**
     * "Delete" is abandon: the goal leaves the live plan and stays in the
     * record, the work beneath it goes with it, and a commitment still counting
     * down under a dropped package is cancelled rather than left to be found.
     */
    public function test_dropping_a_goal_abandons_it_and_cancels_what_hung_off_it(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'abandon_goal', 'goal_id' => $s['route']->id, 'evidence' => 'forget the second route'],
        ]));

        $this->send($s['circle']);

        $this->assertSame(GoalStatus::Abandoned, $s['route']->fresh()->status);
        $this->assertSame(CommitmentStatus::Cancelled, $s['commitment']->fresh()->status);
        $this->assertTrue(Goal::whereKey($s['route']->id)->exists(), 'Abandoned, not deleted.');
    }

    /**
     * "Push it back a week" is a period measured from the date it already had.
     * The model says the period; PHP counts it; the move is a schedule change
     * attributed to the agent, with the meeting's words as the reason.
     */
    public function test_a_moved_date_is_counted_and_recorded_as_the_agents(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'update_goal', 'goal_id' => $s['pad']->id, 'evidence' => 'push the pad back a week',
             'due' => ['shift' => ['value' => 1, 'unit' => 'weeks']]],
        ]));

        $this->send($s['circle']);

        $this->assertSame('2027-04-05', $s['pad']->fresh()->due_at->toDateString());

        $change = GoalScheduleChange::where('goal_id', $s['pad']->id)->firstOrFail();

        $this->assertNull($change->changed_by_user_id);
        $this->assertNotNull($change->changed_by_agent_instance_id);
        $this->assertSame('push the pad back a week', $change->reason);

        $line = TranscriptImport::firstOrFail()->result_json['actions'][0];
        $this->assertStringContainsString('1 week after the previous due date', $line['due_note']);
    }

    /**
     * A goal made earlier in the same meeting can be built on by a label, and
     * a label nothing created is refused — the other operations still apply.
     */
    public function test_later_operations_can_build_on_goals_created_earlier_in_the_same_meeting(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'create_goal', 'ref' => 'new-1', 'title' => 'Handover to Northline', 'evidence' => 'we need a handover'],
            ['op' => 'create_goal', 'parent_goal_id' => 'new-1', 'title' => 'As-built drawings issued', 'evidence' => 'with drawings'],
            ['op' => 'create_commitment', 'goal_id' => 'new-1', 'title' => 'Book handover walk', 'evidence' => 'book the walk',
             'due' => ['on' => '2027-05-01']],
            ['op' => 'update_goal', 'goal_id' => 'new-9', 'progress' => 50, 'evidence' => 'half done'],
        ]));

        $this->send($s['circle']);

        $handover = Goal::where('title', 'Handover to Northline')->firstOrFail();

        $this->assertSame($handover->id, Goal::where('title', 'As-built drawings issued')->value('parent_goal_id'));
        $this->assertSame($handover->id, Commitment::where('title', 'Book handover walk')->value('goal_id'));

        $counts = TranscriptImport::firstOrFail()->result_json['counts'];
        $this->assertSame(2, $counts['created']);
        $this->assertSame(1, $counts['commitments']);
        $this->assertSame(1, $counts['failed']);
    }

    public function test_a_meeting_that_changed_nothing_changes_nothing(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([], 'A status catch-up; nothing moved.'));

        $this->send($s['circle']);

        $import = TranscriptImport::firstOrFail();

        $this->assertSame(TranscriptImport::APPLIED, $import->status);
        $this->assertSame(0, AgentAction::count());
        $this->assertSame('A status catch-up; nothing moved.', $import->result_json['summary']);
    }

    // ------------------------------------------------------------- filing

    /**
     * The transcript becomes ordinary evidence in the Circle — stored, hashed,
     * readable by agents, filed like any upload — and the buffer it waited in
     * is cleared. It never comes back out through the API.
     */
    public function test_the_transcript_is_filed_as_evidence_and_the_buffer_is_cleared(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([]));

        $response = $this->send($s['circle'], 'Dana: nothing much to report this week.');

        $this->assertStringNotContainsString('nothing much to report', $response->getContent());

        $import = TranscriptImport::firstOrFail();
        $item   = EvidenceItem::findOrFail($import->evidence_item_id);

        $this->assertNull($import->content);
        $this->assertTrue($item->agent_read);
        $this->assertSame($s['circle']->id, $item->resource->circle_id);
        $this->assertSame('ready', $item->currentVersion()->processing_status->value);
        $this->assertSame(hash('sha256', 'Dana: nothing much to report this week.'), $item->currentVersion()->sha256);

        $this->getJson("/api/transcripts/{$import->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.content');
    }

    /**
     * A webhook that retries is the ordinary case. The same meeting id comes
     * back to the same import, and the meeting is applied once.
     */
    public function test_a_resent_meeting_is_applied_once(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse($this->reading([
            ['op' => 'create_goal', 'title' => 'Survey booked', 'evidence' => 'survey is booked'],
        ]));

        $first  = $this->send($s['circle'], 'Survey is booked.', ['external_id' => 'granola-123'])->assertStatus(202);
        $second = $this->send($s['circle'], 'Survey is booked.', ['external_id' => 'granola-123'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, TranscriptImport::count());
        $this->assertSame(1, Goal::where('title', 'Survey booked')->count());
        $this->assertSame(1, $this->ai->calls);
    }

    // ------------------------------------------------------------ routing

    public function test_a_meeting_for_a_company_with_no_circles_opens_one(): void
    {
        $owner = $this->makeUser('Dana Okafor', 'gm@jwamats.test');
        $org   = $this->makeOrganisation();
        \App\Models\OrganisationMembership::create(['organisation_id' => $org->id, 'user_id' => $owner->id, 'org_role' => 'member']);

        Sanctum::actingAs($owner);

        // No Circles to choose from, so no routing call: the only model call is
        // the Convener reading the kickoff.
        $this->ai->setResponse([
            'mission' => [
                'name' => 'Bay Junction matting', 'purpose' => 'Supply matting for Bay Junction.',
                'commences' => ['on' => '2027-03-01'], 'basis' => 'stated',
            ],
            'parties' => [['display_name' => 'Northline Rail', 'party_role' => 'principal', 'basis' => 'stated']],
            'plan' => [['level' => 1, 'title' => 'Crane pad laid', 'basis' => 'stated',
                        'due' => ['on' => '2027-03-29']]],
            'open_questions' => [],
            'uncertainty' => '',
        ]);

        $this->postJson('/api/transcripts', [
            'organisation_id' => $org->id,
            'transcript'      => 'Kickoff: we are supplying matting to Northline at Bay Junction.',
            'title'           => 'Bay Junction kickoff',
        ])->assertStatus(202);

        $import = TranscriptImport::firstOrFail();
        $circle = Circle::findOrFail($import->circle_id);

        $this->assertSame(TranscriptImport::APPLIED, $import->status);
        $this->assertSame('opened_new', $import->routing);
        $this->assertSame('Bay Junction matting', $circle->name);
        $this->assertSame($owner->id, $circle->owner_user_id);
        $this->assertTrue(Goal::where('circle_id', $circle->id)->where('title', 'Crane pad laid')->exists());
        $this->assertSame(['convening'], $this->ai->tasks);
    }

    /**
     * A kickoff says "the pad within three weeks" and names no commencement
     * date. That is three weeks from the kickoff — not from whenever the
     * transcript was processed, which is what it meant the first time this
     * ran against a live model.
     */
    public function test_a_kickoffs_periods_are_measured_from_the_meeting(): void
    {
        $owner = $this->makeUser('Dana Okafor', 'gm@jwamats.test');
        $org   = $this->makeOrganisation();
        \App\Models\OrganisationMembership::create(['organisation_id' => $org->id, 'user_id' => $owner->id, 'org_role' => 'member']);

        Sanctum::actingAs($owner);

        $this->ai->setResponse([
            'mission' => ['name' => 'Bay Junction matting', 'purpose' => 'Matting.', 'basis' => 'stated'],
            'parties' => [],
            'plan' => [['level' => 1, 'title' => 'Crane pad matted', 'basis' => 'stated',
                        'due' => ['after_commencement' => ['value' => 3, 'unit' => 'weeks']]]],
            'open_questions' => [],
            'uncertainty' => '',
        ]);

        $this->postJson('/api/transcripts', [
            'organisation_id' => $org->id,
            'text'            => 'Crane pad within three weeks.',
            'occurred_at'     => '2027-03-01',
        ])->assertStatus(202);

        $circle = Circle::findOrFail(TranscriptImport::firstOrFail()->circle_id);

        $this->assertSame('2027-03-22', Goal::where('circle_id', $circle->id)->firstOrFail()->due_at->toDateString());
        $this->assertSame('2027-03-01', $circle->starts_at->toDateString());
    }

    public function test_a_meeting_is_routed_to_the_circle_it_was_about(): void
    {
        $s     = $this->underway();
        $other = $this->makeCircle($s['org'], $s['owner'], ['name' => 'Depot fit-out']);

        Sanctum::actingAs($s['owner']);

        $this->ai->queueResponses(
            ['decision' => 'existing', 'circle_id' => $s['circle']->id, 'reason' => 'It discusses the crane pad.'],
            $this->reading([['op' => 'complete_goal', 'goal_id' => $s['pad']->id, 'evidence' => 'pad is down']]),
        );

        $this->postJson('/api/transcripts', [
            'organisation_id' => $s['org']->id,
            'text'            => 'The crane pad is down.',
        ])->assertStatus(202);

        $import = TranscriptImport::firstOrFail();

        $this->assertSame($s['circle']->id, $import->circle_id);
        $this->assertSame('routed_to_existing', $import->routing);
        $this->assertSame('It discusses the crane pad.', $import->routing_reason);
        $this->assertSame(GoalStatus::Met, $s['pad']->fresh()->status);
        $this->assertSame(0, Goal::where('circle_id', $other->id)->count());

        // The cheap model routed; the stronger one read.
        $this->assertSame(['routing', 'transcript'], $this->ai->tasks);
    }

    /**
     * Not every meeting is about a piece of work, and a router that could only
     * say "existing" or "new" would open a Circle for every one-to-one.
     */
    public function test_a_meeting_about_no_work_is_recorded_and_not_kept(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse(['decision' => 'none', 'reason' => 'A one-to-one about leave.']);

        $this->postJson('/api/transcripts', [
            'organisation_id' => $s['org']->id,
            'text'            => 'Sam: I am taking leave in April.',
        ])->assertStatus(202);

        $import = TranscriptImport::firstOrFail();

        $this->assertSame(TranscriptImport::IGNORED, $import->status);
        $this->assertNull($import->circle_id);
        $this->assertNull($import->content);
        $this->assertSame(0, EvidenceItem::count());
    }

    /** A choice outside the list the router was shown is not a choice. */
    public function test_a_router_naming_a_circle_it_was_not_shown_writes_nothing(): void
    {
        $s        = $this->underway();
        $stranger = $this->makeCircle($this->makeOrganisation('Someone Else'), $this->makeUser('X', 'x@else.test'));

        Sanctum::actingAs($s['owner']);

        $this->ai->setResponse(['decision' => 'existing', 'circle_id' => $stranger->id, 'reason' => 'Looks related.']);

        $this->postJson('/api/transcripts', [
            'organisation_id' => $s['org']->id,
            'text'            => 'The crane pad is down.',
        ]);

        $import = TranscriptImport::firstOrFail();

        $this->assertSame(TranscriptImport::FAILED, $import->status);
        $this->assertStringContainsString('not one of the candidates', $import->error);
        $this->assertSame(0, EvidenceItem::count());
    }

    /**
     * A failed import retried from the log resumes from its checkpoint. The
     * Circle chosen the first time is kept, and nothing is filed twice.
     */
    public function test_a_failed_import_resumes_where_it_stopped(): void
    {
        $s = $this->underway();
        Sanctum::actingAs($s['owner']);

        $this->app->instance(AiProvider::class, new FakeAiProvider(throws: new \RuntimeException('Overloaded')));

        $this->send($s['circle'], 'The crane pad is down.');

        $import = TranscriptImport::firstOrFail();

        $this->assertSame(TranscriptImport::FAILED, $import->status);
        $this->assertNotNull($import->evidence_item_id);

        $this->app->instance(AiProvider::class, $this->ai);
        $this->ai->setResponse($this->reading([
            ['op' => 'complete_goal', 'goal_id' => $s['pad']->id, 'evidence' => 'pad is down'],
        ]));

        $this->postJson("/api/transcripts/{$import->id}/retry")->assertStatus(202);

        $this->assertSame(TranscriptImport::APPLIED, $import->fresh()->status);
        $this->assertSame(1, EvidenceItem::count());
        $this->assertSame(GoalStatus::Met, $s['pad']->fresh()->status);
    }

    public function test_someone_who_cannot_run_an_agent_cannot_send_a_meeting_to_a_circle(): void
    {
        $s = $this->underway();

        $contributor = $this->makeUser('Sam Reyes', 'sam@jwamats.test');
        $this->addMember($s['circle'], $contributor, CircleRole::Contributor);

        Sanctum::actingAs($contributor);

        $this->send($s['circle'])->assertForbidden();
        $this->assertSame(0, TranscriptImport::count());
    }

    // ----------------------------------------------------------- connector

    private function connector(array $s): string
    {
        Sanctum::actingAs($s['owner']);

        return $this->postJson('/api/connector-tokens', [
            'organisation_id' => $s['org']->id,
            'name'            => 'Granola via Zapier',
        ])->assertCreated()->json('data.token');
    }

    /**
     * A token pasted into Zapier lives in somebody else's system from then on.
     * It posts a transcript to one company and reaches nothing else at all.
     */
    public function test_a_connector_token_can_send_a_transcript_and_do_nothing_else(): void
    {
        $s     = $this->underway();
        $token = $this->connector($s);

        $this->app['auth']->forgetGuards();

        $this->ai->setResponse(['decision' => 'existing', 'circle_id' => $s['circle']->id, 'reason' => 'Pad.']);

        $this->withToken($token)
            ->postJson('/api/transcripts', ['transcript' => 'Pad is down.', 'external_id' => 'g-1'])
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['id', 'status']])
            ->assertJsonMissingPath('data.circle');

        $import = TranscriptImport::firstOrFail();

        $this->assertSame($s['org']->id, $import->organisation_id);
        $this->assertSame($s['owner']->id, $import->submitted_by_user_id);

        foreach ([
            ['GET', '/api/circles'],
            ['GET', "/api/circles/{$s['circle']->id}"],
            ['GET', '/api/transcripts'],
            ['GET', "/api/transcripts/{$import->id}"],
            ['POST', '/api/connector-tokens'],
        ] as [$method, $uri]) {
            $this->app['auth']->forgetGuards();

            $this->withToken($token)->json($method, $uri)->assertForbidden();
        }
    }

    public function test_a_connector_token_cannot_be_pointed_at_another_company(): void
    {
        $s     = $this->underway();
        $token = $this->connector($s);

        $elsewhere = $this->makeOrganisation('Someone Else');
        \App\Models\OrganisationMembership::create([
            'organisation_id' => $elsewhere->id, 'user_id' => $s['owner']->id, 'org_role' => 'member',
        ]);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/transcripts', ['text' => 'Hello.', 'organisation_id' => $elsewhere->id])
            ->assertForbidden();

        $this->assertSame(0, TranscriptImport::count());
    }

    public function test_a_revoked_connector_token_stops_working(): void
    {
        $s     = $this->underway();
        $token = $this->connector($s);

        $id = $this->getJson('/api/connector-tokens')->assertOk()->json('data.0.id');
        $this->deleteJson("/api/connector-tokens/{$id}")->assertNoContent();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/transcripts', ['text' => 'Hello.'])->assertUnauthorized();
    }
}
