<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Models\AgentAction;
use App\Models\AgentBlueprint;
use App\Models\AgentInstance;
use App\Models\AgentTool;
use App\Models\CircleParty;
use App\Services\Agent\AgentActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * The three surfaces the UI is built on, exercised over HTTP: the goal tree,
 * threads, and the agent action queue.
 *
 * These are the paths a person actually walks, so they are tested as requests
 * rather than as service calls — a route that authorises the wrong permission
 * is invisible from the service layer.
 */
class WorkflowApiTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
    }

    public function test_the_convening_party_is_backfilled_for_a_circle_that_predates_parties(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $this->assertSame(0, CircleParty::where('circle_id', $circle->id)->count());

        Sanctum::actingAs($owner);

        $this->getJson("/api/circles/{$circle->id}/parties")
            ->assertOk()
            ->assertJsonPath('data.0.is_convener', true)
            ->assertJsonPath('data.0.party_role', 'convener')
            ->assertJsonPath('data.0.is_active', true);

        // Idempotent: a second read must not create a second convener.
        $this->getJson("/api/circles/{$circle->id}/parties")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_goal_tree_reports_derived_progress_for_parents(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $parent = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'  => 'Bid package ready to submit',
            'due_at' => now()->addDays(30)->toISOString(),
        ])->assertCreated()->json('data.id');

        $childA = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'          => 'Geotechnical review complete',
            'parent_goal_id' => $parent,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'          => 'Freight confirmed',
            'parent_goal_id' => $parent,
        ])->assertCreated();

        $this->patchJson("/api/goals/{$childA}/progress", ['progress' => 60])
            ->assertOk()
            ->assertJsonPath('data.progress', 60);

        // 60 and 0 across two children: the parent reports 30, not whatever
        // anyone typed on it.
        $tree = $this->getJson("/api/circles/{$circle->id}/goals")->assertOk();
        $tree->assertJsonPath('data.0.progress', 30)
            ->assertJsonPath('data.0.progress_is_derived', true)
            ->assertJsonCount(2, 'data.0.children');
    }

    public function test_progress_cannot_be_set_on_a_parent(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $parent = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Parent'])
            ->json('data.id');
        $this->postJson("/api/circles/{$circle->id}/goals", [
            'title' => 'Child', 'parent_goal_id' => $parent,
        ])->assertCreated();

        $this->patchJson("/api/goals/{$parent}/progress", ['progress' => 90])
            ->assertStatus(422);
    }

    public function test_a_goal_is_met_by_acceptance_rather_than_by_editing_its_status(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $doer = $this->makeUser('Sam', 'sam@jwamats.test');
        $this->addMember($circle, $doer, CircleRole::Contributor);

        Sanctum::actingAs($owner);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'                => 'Load schedule signed off',
            'owner_user_id'        => $doer->id,
            'acceptance_condition' => 'Signed PDF uploaded to this Circle.',
        ])->assertCreated()->json('data.id');

        // The shortcut is closed off explicitly.
        $this->patchJson("/api/goals/{$goal}", ['status' => 'met'])->assertStatus(422);

        $this->postJson("/api/goals/{$goal}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'met')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.accepted_by', 'Dana');
    }

    public function test_the_owner_of_the_work_cannot_accept_their_own_work(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $owner = $this->makeUser('Dana', 'dana@jwamats.test'));

        Sanctum::actingAs($owner);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'         => 'Something I did myself',
            'owner_user_id' => $owner->id,
        ])->json('data.id');

        $this->postJson("/api/goals/{$goal}/accept")->assertStatus(422);
    }

    public function test_a_moved_deadline_records_a_reason_and_awaits_the_counterparty(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $parties = $this->getJson("/api/circles/{$circle->id}/parties")->json('data');
        $client  = $this->postJson("/api/circles/{$circle->id}/parties", [
            'display_name' => 'Bay Junction Rail',
            'party_role'   => 'principal',
        ])->assertCreated()->json('data.id');

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'  => 'Mobilisation complete',
            'due_at' => now()->addDays(7)->toISOString(),
        ])->json('data.id');

        $response = $this->postJson("/api/goals/{$goal}/reschedule", [
            'due_at'            => now()->addDays(17)->toISOString(),
            'reason'            => 'Freight confirmation still pending.',
            'requires_party_id' => $client,
        ])->assertOk();

        $response->assertJsonPath('change.awaiting_agreement', true)
            ->assertJsonPath('change.requires_party', 'Bay Junction Rail')
            ->assertJsonPath('change.reason', 'Freight confirmation still pending.');

        $changeId = $response->json('change.id');

        $this->postJson("/api/schedule-changes/{$changeId}/agree")
            ->assertOk()
            ->assertJsonPath('data.awaiting_agreement', false);

        $this->assertNotEmpty($parties);
    }

    public function test_a_private_thread_is_invisible_to_the_other_party_over_http(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        Sanctum::actingAs($owner);
        $this->getJson("/api/circles/{$circle->id}/parties")->assertOk();

        $contractorParty = $this->postJson("/api/circles/{$circle->id}/parties", [
            'display_name' => 'Groundworks Co',
            'party_role'   => 'contractor',
        ])->json('data.id');

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Ground works'])
            ->json('data.id');

        // The contractor's own working note.
        $sub = $this->makeUser('Ravi', 'ravi@groundworks.test');
        $subMembership = $this->addMember($circle, $sub, CircleRole::Contributor, external: true);
        $subMembership->update(['circle_party_id' => $contractorParty]);

        Sanctum::actingAs($sub);
        $thread = $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'goal',
            'subject_id'   => $goal,
            'body'         => 'We can absorb this if the client pays for standby.',
        ])->assertCreated();

        $thread->assertJsonPath('data.visibility', 'party')
            ->assertJsonPath('data.party', 'Groundworks Co');

        $threadId = $thread->json('data.id');

        // The contractor sees it.
        $this->getJson("/api/circles/{$circle->id}/threads?subject_type=goal&subject_id={$goal}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // The convener, holding every permission, does not.
        Sanctum::actingAs($owner);
        $this->getJson("/api/circles/{$circle->id}/threads?subject_type=goal&subject_id={$goal}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And cannot reply to it by guessing the id — reported as not-found,
        // because confirming it exists is itself the leak.
        $this->postJson("/api/threads/{$threadId}/comments", ['body' => 'What was that?'])
            ->assertNotFound();
    }

    public function test_sharing_a_thread_is_one_way(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);
        $this->getJson("/api/circles/{$circle->id}/parties")->assertOk();

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Scope'])->json('data.id');

        $threadId = $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'goal',
            'subject_id'   => $goal,
            'body'         => 'Internal read on the scope gap.',
        ])->json('data.id');

        $this->postJson("/api/threads/{$threadId}/share")
            ->assertOk()
            ->assertJsonPath('data.visibility', 'circle');

        // No route back. Sharing again is refused rather than silently ignored.
        $this->postJson("/api/threads/{$threadId}/share")->assertStatus(422);
    }

    public function test_a_mention_is_recorded_for_someone_who_can_read_the_thread(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $colleague = $this->makeUser('Sam Okafor', 'sam@jwamats.test');
        $this->addMember($circle, $colleague, CircleRole::Reviewer);

        Sanctum::actingAs($owner);
        $this->getJson("/api/circles/{$circle->id}/parties")->assertOk();

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Crane basis'])->json('data.id');

        $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'goal',
            'subject_id'   => $goal,
            'visibility'   => 'circle',
            'body'         => 'ping @sam can you confirm the load basis?',
        ])->assertCreated()
            ->assertJsonPath('data.comments.0.mentions.0', 'Sam Okafor');

        Sanctum::actingAs($colleague);
        $this->getJson("/api/circles/{$circle->id}/inbox")
            ->assertOk()
            ->assertJsonCount(1, 'mentions');

        $this->postJson("/api/circles/{$circle->id}/mentions/read")
            ->assertOk()
            ->assertJsonPath('data.marked_read', 1);

        $this->getJson("/api/circles/{$circle->id}/inbox")->assertJsonCount(0, 'mentions');
    }

    /**
     * Regression: mentions in a private thread used to vanish.
     *
     * A member with no party row counts as the convener for readability, but
     * the mention parser resolved readership straight from `circle_party_id` —
     * so in a Circle whose memberships predate parties, the reader list was
     * empty and every mention was silently dropped.
     */
    public function test_a_mention_in_a_private_thread_reaches_the_convening_party(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        // Deliberately left with no circle_party_id, as older Circles are.
        $colleague = $this->makeUser('Sam Okafor', 'sam@jwamats.test');
        $this->addMember($circle, $colleague, CircleRole::Reviewer);

        Sanctum::actingAs($owner);
        $this->getJson("/api/circles/{$circle->id}/parties")->assertOk();

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Margin position'])
            ->json('data.id');

        $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'goal',
            'subject_id'   => $goal,
            // No visibility given, so it defaults to private.
            'body'         => 'Holding 15% here. @sam confirm before we send.',
        ])->assertCreated()
            ->assertJsonPath('data.visibility', 'party')
            ->assertJsonPath('data.comments.0.mentions.0', 'Sam Okafor');

        Sanctum::actingAs($colleague);
        $this->getJson("/api/circles/{$circle->id}/inbox")
            ->assertOk()
            ->assertJsonCount(1, 'mentions');
    }

    public function test_an_authored_agent_cannot_grant_itself_more_than_its_mode_allows(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        // Asks for execute-only rights while declaring itself read-only.
        $agent = $this->postJson("/api/circles/{$circle->id}/agents", [
            'name'            => 'Spec Reader',
            'mandate'         => 'Reads specifications and answers questions about them.',
            'execution_mode'  => 'read_only',
            'allowed_actions' => ['circle.view', 'resource.agent_read', 'claim.create', 'agent.execute'],
        ])->assertCreated();

        $agent->assertJsonPath('data.execution_mode', 'read_only');

        $permissions = $agent->json('data.permissions');
        $this->assertContains('resource.agent_read', $permissions);
        $this->assertNotContains('claim.create', $permissions);
        $this->assertNotContains('agent.execute', $permissions);
    }

    public function test_a_side_effect_tool_cannot_be_declared_by_a_proposing_agent(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $blueprint = $this->postJson("/api/circles/{$circle->id}/agents", [
            'name'           => 'Drafter',
            'mandate'        => 'Drafts summaries.',
            'execution_mode' => 'propose',
        ])->json('data.id');

        $this->postJson("/api/circles/{$circle->id}/agents/{$blueprint}/tools", [
            'key'         => 'send_email',
            'name'        => 'Send email',
            'description' => 'Emails the client.',
            'side_effect' => 'external_write',
        ])->assertStatus(422);
    }

    public function test_the_action_queue_shows_what_is_waiting_and_who_must_approve_it(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        Sanctum::actingAs($owner);
        $party = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);
        $circle->memberships()->where('user_id', $owner->id)->update(['circle_party_id' => $party->id]);

        $blueprintId = $this->postJson("/api/circles/{$circle->id}/agents", [
            'name'            => 'Logistics Runner',
            'mandate'         => 'Confirms freight and notifies the yard.',
            'execution_mode'  => 'execute',
            'allowed_actions' => ['circle.view', 'agent.execute'],
        ])->assertCreated()->json('data.id');

        $tool = $this->postJson("/api/circles/{$circle->id}/agents/{$blueprintId}/tools", [
            'key'         => 'notify_yard',
            'name'        => 'Notify the yard',
            'description' => 'Sends the yard a mobilisation notice.',
            'side_effect' => 'external_write',
        ])->assertCreated();

        // The floor is applied regardless of what was asked for.
        $tool->assertJsonPath('data.needs_approval', true)
            ->assertJsonPath('data.approval_role', 'owner')
            ->assertJsonPath('data.owning_party_only', true);

        $instanceId = $this->postJson("/api/circles/{$circle->id}/agents/{$blueprintId}/instantiate")
            ->assertCreated()->json('data.id');

        // The agent proposes. Done through the service because no model is
        // being called here — the queue's behaviour is what is under test.
        $action = app(AgentActionService::class)->propose(
            agent: AgentInstance::find($instanceId),
            tool: AgentTool::find($tool->json('data.id')),
            arguments: ['yard' => 'Bay Junction', 'date' => '2026-09-14'],
            intent: 'Freight is confirmed, so the yard needs the notice today.',
            onBehalfOf: $party,
        );

        $queue = $this->getJson("/api/circles/{$circle->id}/agent-actions")->assertOk();
        $queue->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tool_key', 'notify_yard')
            ->assertJsonPath('data.0.status', 'awaiting_approval')
            ->assertJsonPath('data.0.needs_role', 'owner')
            ->assertJsonPath('data.0.on_behalf_of', 'JWA Mats');

        $this->postJson("/api/agent-actions/{$action->id}/approve", ['note' => 'Confirmed with the yard by phone.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', 'Dana');

        // Approved actions leave the queue.
        $this->getJson("/api/circles/{$circle->id}/agent-actions")->assertJsonCount(0, 'data');
    }

    public function test_a_rejected_action_is_kept_with_its_reason(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $party = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);
        $circle->memberships()->where('user_id', $owner->id)->update(['circle_party_id' => $party->id]);

        $blueprint = AgentBlueprint::create([
            'key' => 'runner_' . uniqid(), 'name' => 'Runner',
            'mandate' => 'Does things.', 'version' => '1.0.0', 'status' => 'active',
            'execution_mode' => 'execute', 'provider' => 'internal',
            'organisation_id' => $org->id,
            'allowed_actions' => ['circle.view', 'agent.execute'],
            'prohibited_actions' => [], 'prompt_version' => 't',
        ]);

        $tool = AgentTool::create([
            'agent_blueprint_id' => $blueprint->id, 'key' => 'issue_payment',
            'name' => 'Issue payment', 'description' => 'Releases a payment.',
            'side_effect' => 'financial', 'requires_approval' => true,
        ]);

        $instance = AgentInstance::create([
            'agent_blueprint_id' => $blueprint->id,
            'circle_id' => $circle->id, 'status' => 'active',
        ]);

        $action = app(AgentActionService::class)->propose(
            agent: $instance, tool: $tool,
            arguments: ['amount' => 42000],
            onBehalfOf: $party,
        );

        Sanctum::actingAs($owner);

        $this->postJson("/api/agent-actions/{$action->id}/reject", ['reason' => 'Not until the variation is signed.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Not until the variation is signed.');

        // Kept, not deleted: an agent that keeps asking is a pattern worth seeing.
        $this->assertNotNull(AgentAction::find($action->id));

        $this->getJson("/api/circles/{$circle->id}/agent-actions")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('recent.0.status', 'rejected');
    }
}
