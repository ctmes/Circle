<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CircleStatus;
use App\Enums\Permission;
use App\Models\OrganisationMembership;
use App\Models\ResourceAccessOverride;
use App\Models\RoleGrant;
use App\Services\Authorisation\AccessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/** The authorisation model (spec §10). */
class AccessGateTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    private AccessGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
        $this->gate = app(AccessGate::class);
    }

    public function test_role_defaults_match_the_specification(): void
    {
        $expected = [
            'owner'       => [Permission::CircleManageMembers, Permission::CircleClose, Permission::DecisionApprove, Permission::ResourceDelete],
            'approver'    => [Permission::DecisionApprove, Permission::ClaimReview, Permission::ResourceUpload],
            'reviewer'    => [Permission::ClaimReview, Permission::ResourceUpload],
            'contributor' => [Permission::ResourceUpload, Permission::ClaimCreate],
            'viewer'      => [Permission::CircleView, Permission::ResourceView],
        ];

        foreach ($expected as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertTrue(
                    CircleRole::from($role)->grants($permission),
                    "{$role} should grant {$permission->value}",
                );
            }
        }

        // The boundaries that matter most.
        $this->assertFalse(CircleRole::Reviewer->grants(Permission::DecisionApprove), 'reviewer has no final approval');
        $this->assertFalse(CircleRole::Contributor->grants(Permission::ClaimReview), 'contributor cannot review');
        $this->assertFalse(CircleRole::Viewer->grants(Permission::ResourceUpload), 'viewer cannot upload');
        $this->assertFalse(CircleRole::Viewer->grants(Permission::ResourceDownload), 'viewer has no download right');
        $this->assertFalse(CircleRole::Approver->grants(Permission::CircleManageMembers), 'approver does not manage members');
    }

    public function test_the_agent_role_cannot_approve_delete_or_manage_members(): void
    {
        foreach ([
            Permission::DecisionApprove, Permission::ClaimApprove, Permission::ResourceDelete,
            Permission::CircleManageMembers, Permission::CircleClose, Permission::ResourceShare,
            Permission::ResourceDownload, Permission::ExportCreate, Permission::ClaimReview,
        ] as $permission) {
            $this->assertFalse(
                CircleRole::Agent->grants($permission),
                "the agent role must never grant {$permission->value}",
            );
        }
    }

    public function test_organisation_membership_alone_grants_nothing(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $colleague = $this->makeUser('Colleague', 'colleague@jwamats.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        // Same organisation, deliberately not a Circle member.
        OrganisationMembership::create([
            'organisation_id' => $org->id, 'user_id' => $colleague->id, 'org_role' => 'member',
        ]);

        $decision = $this->gate->inspect($colleague, Permission::CircleView, $circle);
        $this->assertFalse($decision->allowed);
        $this->assertSame('not_member', $decision->reason);
    }

    public function test_revoked_membership_denies_immediately(): void
    {
        $owner = $this->makeUser('Owner', 'owner2@jwamats.test');
        $contributor = $this->makeUser('Contributor', 'c2@jwamats.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $membership = $this->addMember($circle, $contributor, CircleRole::Contributor);

        $this->assertTrue($this->gate->allows($contributor, Permission::ResourceUpload, $circle));

        $membership->forceFill(['revoked_at' => now(), 'invite_status' => 'revoked'])->save();

        $decision = $this->gate->inspect($contributor, Permission::ResourceUpload, $circle);
        $this->assertFalse($decision->allowed);
        $this->assertSame('membership_revoked', $decision->reason);
    }

    public function test_expired_membership_denies(): void
    {
        $owner = $this->makeUser('Owner', 'owner3@jwamats.test');
        $temp = $this->makeUser('Temp', 'temp@northernrail.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        $this->addMember($circle, $temp, CircleRole::Contributor)
            ->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame('membership_expired',
            $this->gate->inspect($temp, Permission::ResourceView, $circle)->reason);
    }

    public function test_external_collaborators_are_denied_download_and_share_by_default(): void
    {
        $owner = $this->makeUser('Owner', 'owner4@jwamats.test');
        $client = $this->makeUser('Client', 'client@northernrail.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $this->addMember($circle, $client, CircleRole::Approver, external: true);

        $item = $this->makeEvidence($circle, $owner, 'RFQ');

        // An external approver keeps their review rights...
        $this->assertTrue($this->gate->allows($client, Permission::ClaimReview, $circle));

        // ...but not the file rights that leak material outside (spec §10).
        // Download is the sharp case: the approver role *does* grant it, so the
        // refusal can only be coming from the external default.
        $this->assertSame('external_default_denies',
            $this->gate->inspect($client, Permission::ResourceDownload, $circle, $item->resource)->reason);

        // Share is denied twice over — the approver role never grants it, so
        // the role gate answers first.
        $share = $this->gate->inspect($client, Permission::ResourceShare, $circle, $item->resource);
        $this->assertFalse($share->allowed);
        $this->assertSame('role_denies', $share->reason);
    }

    public function test_the_external_default_strips_sharing_even_from_a_role_that_grants_it(): void
    {
        $owner = $this->makeUser('Owner', 'owner4b@jwamats.test');
        $partner = $this->makeUser('Partner', 'partner@northernrail.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        // An owner role grants share; being external must still remove it.
        $this->addMember($circle, $partner, CircleRole::Owner, external: true);
        $item = $this->makeEvidence($circle, $owner, 'RFQ');

        $this->assertSame('external_default_denies',
            $this->gate->inspect($partner, Permission::ResourceShare, $circle, $item->resource)->reason);
        $this->assertSame('external_default_denies',
            $this->gate->inspect($partner, Permission::CircleManageMembers, $circle)->reason);
    }

    public function test_an_explicit_override_can_grant_an_external_user_download(): void
    {
        $owner = $this->makeUser('Owner', 'owner5@jwamats.test');
        $client = $this->makeUser('Client', 'client5@northernrail.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $this->addMember($circle, $client, CircleRole::Approver, external: true);
        $item = $this->makeEvidence($circle, $owner, 'RFQ');

        ResourceAccessOverride::create([
            'resource_id'        => $item->resource_id,
            'user_id'            => $client->id,
            'permission'         => Permission::ResourceDownload->value,
            'allow'              => true,
            'granted_by_user_id' => $owner->id,
        ]);

        $this->assertTrue($this->gate->allows($client, Permission::ResourceDownload, $circle, $item->resource));
    }

    public function test_an_explicit_deny_beats_the_role_default(): void
    {
        $owner = $this->makeUser('Owner', 'owner6@jwamats.test');
        $contributor = $this->makeUser('Contributor', 'c6@jwamats.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $this->addMember($circle, $contributor, CircleRole::Contributor);

        RoleGrant::create([
            'circle_id'          => $circle->id,
            'user_id'            => $contributor->id,
            'permission'         => Permission::ResourceUpload->value,
            'allow'              => false,
            'granted_by_user_id' => $owner->id,
        ]);

        $this->assertSame('explicitly_denied',
            $this->gate->inspect($contributor, Permission::ResourceUpload, $circle)->reason);
    }

    public function test_download_is_a_distinct_right_from_view(): void
    {
        $owner = $this->makeUser('Owner', 'owner7@jwamats.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $item = $this->makeEvidence($circle, $owner, 'Restricted drawing');

        $item->forceFill(['downloadable' => false])->save();

        $this->assertTrue($this->gate->allows($owner, Permission::ResourceView, $circle, $item->resource));
        $this->assertSame('download_disabled',
            $this->gate->inspect($owner, Permission::ResourceDownload, $circle, $item->resource)->reason);
    }

    public function test_closure_revokes_externals_but_leaves_internals_a_read_only_record(): void
    {
        $owner = $this->makeUser('Owner', 'owner8@jwamats.test');
        $staff = $this->makeUser('Staff', 'staff8@jwamats.test');
        $client = $this->makeUser('Client', 'client8@northernrail.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $this->addMember($circle, $staff, CircleRole::Contributor);
        $this->addMember($circle, $client, CircleRole::Viewer, external: true);

        $circle->forceFill(['status' => CircleStatus::Archived, 'closed_at' => now()])->save();
        $circle->refresh();

        $this->assertSame('circle_closed', $this->gate->inspect($client, Permission::CircleView, $circle)->reason);
        $this->assertTrue($this->gate->allows($staff, Permission::CircleView, $circle), 'internal read survives closure');
        $this->assertSame('circle_closed', $this->gate->inspect($staff, Permission::ResourceUpload, $circle)->reason);
        $this->assertTrue($this->gate->allows($owner, Permission::ExportCreate, $circle), 'the packet can still be exported');
    }

    public function test_an_expired_circle_accepts_no_changes_but_remains_readable(): void
    {
        $owner = $this->makeUser('Owner', 'owner9@jwamats.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner, ['expires_at' => now()->subDay()]);

        $this->assertTrue($this->gate->allows($owner, Permission::CircleView, $circle));
        $this->assertSame('circle_expired', $this->gate->inspect($owner, Permission::ResourceUpload, $circle)->reason);
    }

    public function test_a_resource_cannot_be_reached_through_another_circle(): void
    {
        $owner = $this->makeUser('Owner', 'owner10@jwamats.test');
        $org = $this->makeOrganisation();
        $circleA = $this->makeCircle($org, $owner);
        $circleB = $this->makeCircle($org, $owner, ['name' => 'Other mission']);

        $item = $this->makeEvidence($circleA, $owner, 'Drawing');

        $this->assertSame('wrong_circle',
            $this->gate->inspect($owner, Permission::ResourceView, $circleB, $item->resource)->reason);
    }

    public function test_denials_are_recorded_in_the_audit_log(): void
    {
        $owner = $this->makeUser('Owner', 'owner11@jwamats.test');
        $stranger = $this->makeUser('Stranger', 'stranger@nowhere.test');
        $org = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);

        try {
            $this->gate->authorise($stranger, Permission::CircleView, $circle);
        } catch (\Throwable) {
            // expected
        }

        $event = \App\Models\AuditEvent::where('circle_id', $circle->id)
            ->where('event_type', 'access.denied')->firstOrFail();

        $this->assertSame('circle.view', $event->metadata_json['permission']);
        $this->assertSame('not_member', $event->metadata_json['reason']);
        $this->assertSame($stranger->id, $event->actor_id);
    }
}
