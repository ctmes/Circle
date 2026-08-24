<?php

namespace Tests\Feature;

use App\Enums\AgentActionStatus;
use App\Enums\AgentExecutionMode;
use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Enums\SideEffect;
use App\Models\AgentBlueprint;
use App\Models\AgentInstance;
use App\Models\AgentTool;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\User;
use App\Services\Authorisation\AccessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * The boundary around an agent that can act.
 *
 * Letting agents execute is the change that makes every other guarantee in the
 * product load-bearing, so these tests are about refusal rather than capability:
 * what an agent cannot do, cannot grant itself, and cannot do without a human.
 */
class AgentExecutionBoundaryTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    private function blueprint(AgentExecutionMode $mode, array $allowed): AgentBlueprint
    {
        return AgentBlueprint::create([
            'key'                => 'authored_' . uniqid(),
            'name'               => 'Authored Agent',
            'mandate'            => 'A blueprint written by a customer, not by us.',
            'version'            => '1.0.0',
            'status'             => 'active',
            'is_system'          => false,
            'execution_mode'     => $mode->value,
            'provider'           => 'internal',
            'allowed_actions'    => array_map(fn (Permission $p) => $p->value, $allowed),
            'prohibited_actions' => [],
            'prompt_version'     => 'test-1',
        ]);
    }

    private function agentIn(Circle $circle, AgentBlueprint $blueprint): AgentInstance
    {
        return AgentInstance::create([
            'agent_blueprint_id' => $blueprint->id,
            'circle_id'          => $circle->id,
            'status'             => 'active',
        ]);
    }

    public function test_a_read_only_agent_cannot_write_even_when_its_mandate_claims_it_may(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('Owner', 'o@jwamats.test'));

        // The blueprint asks for claim creation while declaring itself read-only.
        // The mode is the outer bound, so the declaration buys nothing.
        $blueprint = $this->blueprint(AgentExecutionMode::ReadOnly, [
            Permission::CircleView,
            Permission::ResourceAgentRead,
            Permission::ClaimCreate,
        ]);

        $this->assertFalse($blueprint->grants(Permission::ClaimCreate));
        $this->assertTrue($blueprint->grants(Permission::ResourceAgentRead));

        $decision = app(AccessGate::class)->inspect(
            $this->agentIn($circle, $blueprint),
            Permission::ClaimCreate,
            $circle,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('agent_read_only', $decision->reason);
    }

    public function test_a_proposing_agent_may_draft_but_never_execute(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('Owner', 'o@jwamats.test'));

        $blueprint = $this->blueprint(AgentExecutionMode::Propose, [
            Permission::CircleView,
            Permission::ClaimCreate,
            Permission::GoalCreate,
            Permission::AgentExecute,
        ]);

        $gate  = app(AccessGate::class);
        $agent = $this->agentIn($circle, $blueprint);

        $this->assertTrue($gate->allows($agent, Permission::ClaimCreate, $circle));
        $this->assertTrue($gate->allows($agent, Permission::GoalCreate, $circle));

        $decision = $gate->inspect($agent, Permission::AgentExecute, $circle);
        $this->assertFalse($decision->allowed);
        $this->assertSame('agent_cannot_execute', $decision->reason);
    }

    public function test_an_executing_agent_is_still_bound_by_what_it_declared(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('Owner', 'o@jwamats.test'));

        // Execute mode, but the mandate never mentions commitment updates.
        $blueprint = $this->blueprint(AgentExecutionMode::Execute, [
            Permission::CircleView,
            Permission::AgentExecute,
        ]);

        $gate  = app(AccessGate::class);
        $agent = $this->agentIn($circle, $blueprint);

        $this->assertTrue($gate->allows($agent, Permission::AgentExecute, $circle));

        $decision = $gate->inspect($agent, Permission::CommitmentUpdate, $circle);
        $this->assertFalse($decision->allowed);
        $this->assertSame('agent_mandate_denies', $decision->reason);
    }

    public function test_a_circle_scoped_agent_cannot_be_used_in_another_circle(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Owner', 'o@jwamats.test');
        $home   = $this->makeCircle($org, $owner);
        $other  = $this->makeCircle($org, $owner, ['name' => 'A different mission']);

        $blueprint = $this->blueprint(AgentExecutionMode::Propose, [
            Permission::CircleView,
            Permission::ClaimCreate,
        ]);
        $blueprint->update(['circle_id' => $home->id]);

        // An instance bound to the wrong Circle is refused even though the
        // organisation is the same one that authored the agent.
        $decision = app(AccessGate::class)->inspect(
            $this->agentIn($other, $blueprint),
            Permission::ClaimCreate,
            $other,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('agent_blueprint_wrong_circle', $decision->reason);
    }

    public function test_suspending_a_blueprint_stops_every_instance_of_it(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('Owner', 'o@jwamats.test'));

        $blueprint = $this->blueprint(AgentExecutionMode::Propose, [
            Permission::CircleView,
            Permission::ClaimCreate,
        ]);
        $agent = $this->agentIn($circle, $blueprint);
        $gate  = app(AccessGate::class);

        $this->assertTrue($gate->allows($agent, Permission::ClaimCreate, $circle));

        $blueprint->update(['status' => 'suspended']);

        $decision = $gate->inspect($agent->fresh(), Permission::ClaimCreate, $circle);
        $this->assertFalse($decision->allowed);
        $this->assertSame('agent_blueprint_inactive', $decision->reason);
    }

    public function test_a_tool_cannot_declare_a_weaker_approver_than_its_side_effect_requires(): void
    {
        $blueprint = $this->blueprint(AgentExecutionMode::Execute, [Permission::AgentExecute]);

        // The author tries to let a contributor sign off on sending money.
        $tool = AgentTool::create([
            'agent_blueprint_id'    => $blueprint->id,
            'key'                   => 'issue_payment',
            'name'                  => 'Issue payment',
            'description'           => 'Releases a progress payment.',
            'side_effect'           => SideEffect::Financial->value,
            'requires_approval'     => false,
            'approval_role'         => CircleRole::Contributor->value,
            'requires_owning_party' => false,
        ]);

        // Both attempts to weaken the tool are ignored.
        $this->assertTrue($tool->needsApproval());
        $this->assertSame(CircleRole::Owner, $tool->effectiveApprovalRole());
        $this->assertTrue($tool->needsOwningPartyApprover());
    }

    public function test_a_harmless_tool_needs_nobody(): void
    {
        $blueprint = $this->blueprint(AgentExecutionMode::Execute, [Permission::AgentExecute]);

        $tool = AgentTool::create([
            'agent_blueprint_id' => $blueprint->id,
            'key'                => 'summarise_evidence',
            'name'               => 'Summarise evidence',
            'description'        => 'Reads permitted evidence and writes a summary.',
            'side_effect'        => SideEffect::None->value,
            'requires_approval'  => false,
        ]);

        $this->assertFalse($tool->needsApproval());
        $this->assertNull($tool->effectiveApprovalRole());
    }

    public function test_an_approval_that_has_expired_is_no_longer_consent(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Owner', 'o@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $party = CircleParty::create([
            'circle_id'       => $circle->id,
            'organisation_id' => $org->id,
            'display_name'    => 'JWA Mats',
            'party_role'      => 'convener',
            'status'          => 'active',
            'is_convener'     => true,
        ]);

        $blueprint = $this->blueprint(AgentExecutionMode::Execute, [Permission::AgentExecute]);
        $agent     = $this->agentIn($circle, $blueprint);

        $action = $agent->circle->agentActions()->create([
            'agent_instance_id'     => $agent->id,
            'tool_key'              => 'send_notice',
            'side_effect'           => SideEffect::ExternalWrite->value,
            'status'                => AgentActionStatus::Approved->value,
            'on_behalf_of_party_id' => $party->id,
            'approved_by_user_id'   => $owner->id,
            'approved_at'           => now()->subHours(3),
            'expires_at'            => now()->subHour(),
        ]);

        // Approved, but the window it was approved for has passed. Stale
        // consent is treated the same way as an approval bound to a superseded
        // version: it no longer applies.
        $this->assertSame(AgentActionStatus::Approved, $action->status);
        $this->assertTrue($action->isExpired());
        $this->assertFalse($action->isExecutable());
    }

    public function test_a_closed_circle_stops_agent_action_entirely(): void
    {
        $owner  = $this->makeUser('Owner', 'o@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner, [
            'status'    => 'archived',
            'closed_at' => now(),
        ]);

        $blueprint = $this->blueprint(AgentExecutionMode::Execute, [
            Permission::CircleView,
            Permission::AgentExecute,
        ]);

        $decision = app(AccessGate::class)->inspect(
            $this->agentIn($circle, $blueprint),
            Permission::AgentExecute,
            $circle,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('circle_closed', $decision->reason);
    }
}
