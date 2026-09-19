<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CircleStatus;
use App\Enums\CommitmentStatus;
use App\Enums\DecisionStatus;
use App\Enums\GoalStatus;
use App\Models\AgentInstance;
use App\Models\AgentTool;
use App\Models\CircleParty;
use App\Models\Commitment;
use App\Models\Decision;
use App\Models\Goal;
use App\Services\Agent\AgentActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * The summary each card on the Circle list carries.
 *
 * What matters is that "waiting on you" means *you*: a decision naming someone
 * else, your own work in review, or an agent action you would be refused are
 * not counted, because each one would send you into a Circle to do nothing.
 */
class CircleDigestTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
    }

    public function test_waiting_on_you_counts_only_what_is_yours_to_do(): void
    {
        $dana   = $this->makeUser('Dana', 'dana@jwamats.test');
        $sam    = $this->makeUser('Sam Okafor', 'sam@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $dana);
        $this->addMember($circle, $sam, CircleRole::Approver);

        foreach ([$sam, $dana] as $approver) {
            Decision::create([
                'circle_id' => $circle->id, 'title' => "Go/no-go for {$approver->name}",
                'status' => DecisionStatus::Pending, 'approver_user_id' => $approver->id,
                'created_by_user_id' => $dana->id,
            ]);
        }

        Goal::create([
            'circle_id' => $circle->id, 'title' => 'Bid package ready',
            'status' => GoalStatus::InReview, 'position' => 1, 'owner_user_id' => $dana->id,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        Sanctum::actingAs($dana);
        $this->getJson("/api/circles/{$circle->id}/parties")->assertOk();
        $goal = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Crane basis'])->json('data.id');
        $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'goal', 'subject_id' => $goal, 'visibility' => 'circle',
            'body' => 'ping @sam can you confirm the load basis?',
        ])->assertCreated();

        Sanctum::actingAs($sam);
        $this->getJson('/api/circles')
            ->assertOk()
            ->assertJsonPath('data.0.digest.waiting.decisions', 1)
            ->assertJsonPath('data.0.digest.waiting.mentions', 1)
            ->assertJsonPath('data.0.digest.waiting.to_accept', 1);

        // Dana's own work in review is not waiting on Dana — the owner of the
        // work cannot accept it — and she was not the one mentioned.
        Sanctum::actingAs($dana);
        $this->getJson('/api/circles')
            ->assertOk()
            ->assertJsonPath('data.0.digest.waiting.decisions', 1)
            ->assertJsonPath('data.0.digest.waiting.mentions', 0)
            ->assertJsonPath('data.0.digest.waiting.to_accept', 0);
    }

    public function test_the_digest_says_how_the_mission_stands(): void
    {
        $dana   = $this->makeUser('Dana', 'dana@jwamats.test');
        $sam    = $this->makeUser('Sam Okafor', 'sam@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $dana);
        $this->addMember($circle, $sam, CircleRole::Contributor);

        $goal = fn (string $title, GoalStatus $status, ?\DateTimeInterface $due = null, ?string $owner = null) => Goal::create([
            'circle_id' => $circle->id, 'title' => $title, 'status' => $status, 'position' => 1,
            'due_at' => $due, 'owner_user_id' => $owner,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        $goal('Tender issued', GoalStatus::Met, now()->subDays(10));
        $goal('Weld inspection', GoalStatus::Active, now()->subDays(2), $dana->id);
        $goal('Site survey', GoalStatus::Active, now()->addDays(5));
        $goal('Dropped scope', GoalStatus::Abandoned, now()->subDays(3));

        Commitment::create([
            'circle_id' => $circle->id, 'title' => 'Return the RFI',
            'status' => CommitmentStatus::Open, 'owner_user_id' => $sam->id, 'due_at' => now()->subDay(),
        ]);
        Commitment::create([
            'circle_id' => $circle->id, 'title' => 'Send drawings',
            'status' => CommitmentStatus::Open, 'owner_user_id' => $sam->id, 'due_at' => now()->addDays(2),
        ]);

        Sanctum::actingAs($dana);
        $this->patchJson("/api/circles/{$circle->id}", ['progress' => 30])->assertOk();

        $digest = $this->getJson('/api/circles')->assertOk()->json('data.0.digest');

        $this->assertSame(['total' => 3, 'done' => 1], $digest['jobs'], 'abandoned work is not part of the count');
        $this->assertSame(2, $digest['overdue'], 'late work and late promises together');
        $this->assertSame(1, $digest['waiting']['yours_overdue']);
        $this->assertSame('Send drawings', $digest['next_due']['title']);
        $this->assertSame(2, $digest['members']);
        $this->assertNotNull($digest['last_activity_at']);
    }

    public function test_a_closed_circle_is_waiting_on_nobody(): void
    {
        $dana   = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $dana);

        Decision::create([
            'circle_id' => $circle->id, 'title' => 'Go/no-go', 'status' => DecisionStatus::Pending,
            'approver_user_id' => $dana->id, 'created_by_user_id' => $dana->id,
        ]);
        Commitment::create([
            'circle_id' => $circle->id, 'title' => 'Return the RFI',
            'status' => CommitmentStatus::Open, 'owner_user_id' => $dana->id, 'due_at' => now()->subDay(),
        ]);

        $circle->forceFill(['status' => CircleStatus::Archived, 'closed_at' => now()])->save();

        Sanctum::actingAs($dana);
        $digest = $this->getJson('/api/circles')->assertOk()->json('data.0.digest');

        $this->assertSame(0, array_sum($digest['waiting']));
        $this->assertSame(0, $digest['overdue']);
        $this->assertNull($digest['next_due']);
    }

    public function test_an_agent_action_waits_only_on_someone_who_may_approve_it(): void
    {
        $org    = $this->makeOrganisation();
        $dana   = $this->makeUser('Dana', 'dana@jwamats.test');
        $sam    = $this->makeUser('Sam Okafor', 'sam@jwamats.test');
        $circle = $this->makeCircle($org, $dana);

        $party = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);
        $circle->memberships()->where('user_id', $dana->id)->update(['circle_party_id' => $party->id]);
        $this->addMember($circle, $sam, CircleRole::Approver)->update(['circle_party_id' => $party->id]);

        Sanctum::actingAs($dana);
        $blueprintId = $this->postJson("/api/circles/{$circle->id}/agents", [
            'name' => 'Logistics Runner', 'mandate' => 'Confirms freight and notifies the yard.',
            'execution_mode' => 'execute', 'allowed_actions' => ['circle.view', 'agent.execute'],
        ])->assertCreated()->json('data.id');

        // An external write needs an owner to approve it, whatever else the
        // approver holds.
        $toolId = $this->postJson("/api/circles/{$circle->id}/agents/{$blueprintId}/tools", [
            'key' => 'notify_yard', 'name' => 'Notify the yard',
            'description' => 'Sends the yard a mobilisation notice.', 'side_effect' => 'external_write',
        ])->assertCreated()->assertJsonPath('data.approval_role', 'owner')->json('data.id');

        $instanceId = $this->postJson("/api/circles/{$circle->id}/agents/{$blueprintId}/instantiate")
            ->assertCreated()->json('data.id');

        app(AgentActionService::class)->propose(
            agent: AgentInstance::find($instanceId),
            tool: AgentTool::find($toolId),
            arguments: ['yard' => 'Bay Junction'],
            intent: 'Freight is confirmed, so the yard needs the notice today.',
            onBehalfOf: $party,
        );

        $this->getJson('/api/circles')->assertOk()->assertJsonPath('data.0.digest.waiting.agent_actions', 1);

        // Sam holds agent.approve as an approver, and would still be refused.
        Sanctum::actingAs($sam);
        $this->getJson('/api/circles')->assertOk()->assertJsonPath('data.0.digest.waiting.agent_actions', 0);
    }
}
