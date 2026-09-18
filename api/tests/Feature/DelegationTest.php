<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Models\CircleParty;
use App\Models\ResourceAccessOverride;
use App\Services\Authorisation\AccessGate;
use App\Services\Authorisation\DelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Handing one right to one person, and scoping one resource to one company.
 *
 * Both mechanisms were in the schema from the start and reachable from nowhere,
 * which meant the only way to let a contributor author an agent was to make
 * them an owner — and the only way to keep a subcontractor away from the
 * commercials was to keep the commercials out of the Circle.
 */
class DelegationTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    public function test_a_contributor_can_be_given_one_right_without_the_owner_role(): void
    {
        $owner  = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $sam = $this->makeUser('Sam Iyer', 'sam@jwamats.test');
        $this->addMember($circle, $sam, CircleRole::Contributor);

        $gate = app(AccessGate::class);

        $this->assertFalse($gate->allows($sam, Permission::AgentAuthor, $circle));

        app(DelegationService::class)->grant(
            circle: $circle,
            granter: $owner,
            subject: $sam,
            permission: Permission::AgentAuthor,
            reason: 'Runs the logistics agents for the yard.',
        );

        $this->assertTrue($gate->allows($sam, Permission::AgentAuthor, $circle));

        // The narrow grant stays narrow. This is the whole point of not
        // promoting them to owner.
        $this->assertFalse($gate->allows($sam, Permission::CircleClose, $circle));
        $this->assertFalse($gate->allows($sam, Permission::CircleManageMembers, $circle));
    }

    public function test_nobody_can_grant_a_right_they_do_not_hold(): void
    {
        $owner  = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $lead = $this->makeUser('Rae Nkomo', 'rae@jwamats.test');
        $sam  = $this->makeUser('Sam Iyer', 'sam@jwamats.test');

        $this->addMember($circle, $lead, CircleRole::Approver);
        $this->addMember($circle, $sam, CircleRole::Contributor);

        // An approver holds neither membership control nor agent authoring, so
        // they cannot start handing either out.
        $this->expectExceptionMessageMatches('/do not hold it yourself|not a member|circle.manage_members/i');

        app(DelegationService::class)->grant(
            circle: $circle,
            granter: $lead,
            subject: $sam,
            permission: Permission::AgentAuthor,
        );
    }

    public function test_the_owner_cannot_be_locked_out_of_their_own_circle(): void
    {
        $owner  = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $this->expectExceptionMessage('The Circle owner cannot be denied membership control or closure.');

        app(DelegationService::class)->grant(
            circle: $circle,
            granter: $owner,
            subject: $owner,
            permission: Permission::CircleManageMembers,
            allow: false,
        );
    }

    public function test_a_grant_is_written_to_the_record_with_its_reason(): void
    {
        $owner  = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $sam = $this->makeUser('Sam Iyer', 'sam@jwamats.test');
        $this->addMember($circle, $sam, CircleRole::Contributor);

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/grants", [
            'user_id'    => $sam->id,
            'permission' => 'agent.author',
            'reason'     => 'Owns the yard automation.',
        ])->assertCreated()->assertJsonPath('data.allow', true);

        $this->assertDatabaseHas('audit_events', [
            'circle_id'  => $circle->id,
            'event_type' => 'permission.granted',
        ]);

        // Visible to everyone who can see the Circle, not only to whoever wrote
        // it — a permission model half the Circle cannot read is not in force.
        Sanctum::actingAs($sam);
        $this->getJson("/api/circles/{$circle->id}/grants")
            ->assertOk()
            ->assertJsonPath('data.0.permission', 'agent.author')
            ->assertJsonPath('data.0.reason', 'Owns the yard automation.');
    }

    public function test_granting_what_the_role_already_covers_is_refused_rather_than_recorded(): void
    {
        $owner  = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $sam = $this->makeUser('Sam Iyer', 'sam@jwamats.test');
        $this->addMember($circle, $sam, CircleRole::Contributor);

        Sanctum::actingAs($owner);

        // A contributor already uploads. Recording a "grant" for it would put a
        // decision in the log that nobody made.
        $this->postJson("/api/circles/{$circle->id}/grants", [
            'user_id'    => $sam->id,
            'permission' => 'resource.upload',
        ])->assertStatus(422);
    }

    public function test_a_document_can_be_scoped_to_one_party(): void
    {
        $owner  = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $contractor = CircleParty::create([
            'circle_id' => $circle->id,
            'display_name' => 'Beam Rail', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $circle->memberships()->where('user_id', $owner->id)
            ->update(['circle_party_id' => $convener->id]);

        $rae = $this->makeUser('Rae Nkomo', 'rae@beamrail.test');
        $membership = $this->addMember($circle, $rae, CircleRole::Reviewer);
        $membership->update(['circle_party_id' => $contractor->id]);

        $item = $this->makeEvidence($circle, $owner, 'Commercial assumptions', extractedText: 'Target margin 18%.');
        $gate = app(AccessGate::class);

        // Both sides can see it to begin with.
        $this->assertTrue($gate->allows($rae, Permission::ResourceView, $circle, $item->resource));

        ResourceAccessOverride::create([
            'resource_id'        => $item->resource_id,
            'circle_party_id'    => $contractor->id,
            'permission'         => Permission::ResourceView->value,
            'allow'              => false,
            'reason'             => 'Commercial position, not shared with the contractor.',
            'granted_by_user_id' => $owner->id,
        ]);

        $this->assertFalse($gate->allows($rae, Permission::ResourceView, $circle, $item->resource));

        // Scoping out one party leaves everyone else exactly where they were.
        $this->assertTrue($gate->allows($owner, Permission::ResourceView, $circle, $item->resource));

        $decision = $gate->inspect($rae, Permission::ResourceView, $circle, $item->resource);
        $this->assertSame('party_scope_denies', $decision->reason);
    }

    public function test_a_named_person_outranks_their_party_scope(): void
    {
        $owner  = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $contractor = CircleParty::create([
            'circle_id' => $circle->id,
            'display_name' => 'Beam Rail', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $rae = $this->makeUser('Rae Nkomo', 'rae@beamrail.test');
        $membership = $this->addMember($circle, $rae, CircleRole::Reviewer);
        $membership->update(['circle_party_id' => $contractor->id]);

        $item = $this->makeEvidence($circle, $owner, 'Load schedule', extractedText: 'Operating crane 70t.');

        ResourceAccessOverride::create([
            'resource_id'        => $item->resource_id,
            'circle_party_id'    => $contractor->id,
            'permission'         => Permission::ResourceView->value,
            'allow'              => false,
            'granted_by_user_id' => $owner->id,
        ]);

        ResourceAccessOverride::create([
            'resource_id'        => $item->resource_id,
            'user_id'            => $rae->id,
            'permission'         => Permission::ResourceView->value,
            'allow'              => true,
            'reason'             => 'Rae is the named technical reviewer for this item.',
            'granted_by_user_id' => $owner->id,
        ]);

        // The narrow rule wins. Without this ordering the common shape —
        // "nobody at that company except the one person we agreed on" — cannot
        // be expressed at all.
        $this->assertTrue(
            app(AccessGate::class)->allows($rae, Permission::ResourceView, $circle, $item->resource),
        );
    }
}
