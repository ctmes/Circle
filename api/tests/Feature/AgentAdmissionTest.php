<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Models\AgentBlueprint;
use App\Models\AgentConnection;
use App\Models\AgentInstance;
use App\Models\CircleParty;
use App\Models\Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * A party bringing its own agent, and the counterparty accepting it (spec §20.4).
 *
 * The intake half of this shipped with the amendment and the acceptance half
 * did not, so every connection sat at `pending` forever and `isAdmitted()` was
 * a method that could never return true.
 */
class AgentAdmissionTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\User, 2: \App\Models\Circle, 3: CircleParty, 4: CircleParty} */
    private function twoPartyCircle(): array
    {
        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation('JWA Mats');
        $circle = $this->makeCircle($org, $dana);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $beamOrg = $this->makeOrganisation('Beam Rail');

        $contractor = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $beamOrg->id,
            'display_name' => 'Beam Rail', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $circle->memberships()->where('user_id', $dana->id)
            ->update(['circle_party_id' => $convener->id]);

        $rae = $this->makeUser('Rae Nkomo', 'rae@beamrail.test');
        $this->addMember($circle, $rae, CircleRole::Owner, external: true)
            ->update(['circle_party_id' => $contractor->id]);

        return [$dana, $rae, $circle, $convener, $contractor];
    }

    private function connect(\App\Models\Circle $circle, CircleParty $party, ?string $blueprintId = null): AgentConnection
    {
        return AgentConnection::create([
            'circle_id'          => $circle->id,
            'circle_party_id'    => $party->id,
            'agent_blueprint_id' => $blueprintId,
            'name'               => 'Beam Rail Scheduler',
            'auth_mode'          => 'signed_webhook',
            'endpoint_url'       => 'https://agents.beamrail.test/circle',
            'key_fingerprint'    => 'SHA256:9f2c4a1be77d0c3e5a8b6d4f2019ccae',
            'status'             => 'pending',
        ]);
    }

    public function test_a_party_cannot_admit_its_own_agent(): void
    {
        [, $rae, $circle, , $contractor] = $this->twoPartyCircle();

        $connection = $this->connect($circle, $contractor);

        Sanctum::actingAs($rae);

        // Rae owns Beam Rail's seat and brought this agent. Accepting it
        // themselves would make the fingerprint ceremony meaningless.
        $this->postJson("/api/agent-connections/{$connection->id}/admit")
            ->assertStatus(403);

        $this->assertFalse($connection->fresh()->isAdmitted());
    }

    public function test_the_counterparty_admits_it_and_the_acceptance_becomes_a_decision(): void
    {
        [$dana, , $circle, , $contractor] = $this->twoPartyCircle();

        $connection = $this->connect($circle, $contractor);

        Sanctum::actingAs($dana);

        $this->postJson("/api/agent-connections/{$connection->id}/admit", [
            'note' => 'Fingerprint confirmed with Beam Rail on a call.',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_admitted', true)
            ->assertJsonPath('data.admitted_by', 'Dana Okafor');

        $connection->refresh();

        $this->assertNotNull($connection->admitted_at);
        $this->assertSame('active', $connection->status);

        // The acceptance is a decision in the record, and it names the key that
        // was accepted rather than merely that one was.
        $decision = Decision::findOrFail($connection->admitted_via_decision_id);

        $this->assertStringContainsString('Beam Rail Scheduler', $decision->title);
        $this->assertStringContainsString('SHA256:9f2c4a1be77d0c3e5a8b6d4f2019ccae', $decision->description);

        $this->assertDatabaseHas('audit_events', [
            'circle_id'  => $circle->id,
            'event_type' => 'agent.admitted',
        ]);
    }

    public function test_admitting_a_bound_agent_gives_it_a_seat(): void
    {
        [$dana, , $circle, , $contractor] = $this->twoPartyCircle();

        $blueprint = AgentBlueprint::create([
            'key'             => 'beam-rail.scheduler.abcd',
            'organisation_id' => $contractor->organisation_id,
            'is_system'       => false,
            'status'          => 'active',
            'provider'        => 'external',
            'execution_mode'  => 'propose',
            'name'            => 'Beam Rail Scheduler',
            'mandate'         => 'Keeps Beam Rail\'s delivery dates current.',
            'version'         => '1.0.0',
            'allowed_actions' => ['circle.view', 'resource.agent_read', 'claim.create'],
            'prohibited_actions' => ['external_communication'],
            'prompt_version'  => 'authored-1',
        ]);

        $connection = $this->connect($circle, $contractor, $blueprint->id);

        Sanctum::actingAs($dana);
        $this->postJson("/api/agent-connections/{$connection->id}/admit")->assertOk();

        // Without an instance there is no identity for the gate to authorise
        // and nothing for the ledger to point at.
        $this->assertDatabaseHas('agent_instances', [
            'agent_blueprint_id' => $blueprint->id,
            'circle_id'          => $circle->id,
            'status'             => 'active',
        ]);
    }

    public function test_withdrawing_an_agent_stops_it_without_erasing_what_it_did(): void
    {
        [$dana, , $circle, , $contractor] = $this->twoPartyCircle();

        $blueprint = AgentBlueprint::create([
            'key'             => 'beam-rail.scheduler.efgh',
            'organisation_id' => $contractor->organisation_id,
            'is_system'       => false,
            'status'          => 'active',
            'provider'        => 'external',
            'execution_mode'  => 'propose',
            'name'            => 'Beam Rail Scheduler',
            'mandate'         => 'Keeps delivery dates current.',
            'version'         => '1.0.0',
            'allowed_actions' => ['circle.view'],
            'prohibited_actions' => ['external_communication'],
            'prompt_version'  => 'authored-1',
        ]);

        $connection = $this->connect($circle, $contractor, $blueprint->id);

        Sanctum::actingAs($dana);
        $this->postJson("/api/agent-connections/{$connection->id}/admit")->assertOk();

        $this->postJson("/api/agent-connections/{$connection->id}/revoke", [
            'reason' => 'Scope finished.',
        ])->assertOk()->assertJsonPath('data.is_admitted', false);

        $instance = AgentInstance::where('agent_blueprint_id', $blueprint->id)
            ->where('circle_id', $circle->id)
            ->firstOrFail();

        // Disabled, not deleted. The agent stops; the history of what it did
        // stays exactly where it was.
        $this->assertSame('disabled', $instance->status);
        $this->assertNotNull($instance->disabled_at);

        $this->assertDatabaseHas('audit_events', [
            'circle_id'  => $circle->id,
            'event_type' => 'agent.revoked',
        ]);
    }

    public function test_a_connection_with_no_fingerprint_has_nothing_to_admit(): void
    {
        [$dana, , $circle, , $contractor] = $this->twoPartyCircle();

        $connection = $this->connect($circle, $contractor);
        $connection->update(['key_fingerprint' => null]);

        Sanctum::actingAs($dana);

        $this->postJson("/api/agent-connections/{$connection->id}/admit")
            ->assertStatus(422);
    }

    public function test_an_in_house_circle_can_admit_its_own_agent(): void
    {
        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $dana);

        $party = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $circle->memberships()->where('user_id', $dana->id)
            ->update(['circle_party_id' => $party->id]);

        $connection = $this->connect($circle, $party);

        Sanctum::actingAs($dana);

        // There is no counterparty to ask. Refusing here would mean a company
        // could never connect an agent to its own internal work.
        $this->postJson("/api/agent-connections/{$connection->id}/admit")
            ->assertOk()
            ->assertJsonPath('data.is_admitted', true);
    }
}
