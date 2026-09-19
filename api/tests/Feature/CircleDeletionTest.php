<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CircleStatus;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Archiving and deleting a Circle from the Circle list.
 *
 * Archive is closure under the name people use for it. Delete is a state, not
 * a row delete: the Circle leaves everyone's reach, its record and its chain
 * stay standing, and whoever deleted it can bring it back.
 */
class CircleDeletionTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
    }

    public function test_the_owner_can_delete_a_circle_and_it_leaves_the_list(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/circles/{$circle->id}", ['reason' => 'Opened by mistake.'])
            ->assertOk()
            ->assertJsonPath('data.is_deleted', true)
            ->assertJsonPath('data.is_closed', true);

        $circle->refresh();
        $this->assertTrue($circle->isDeleted());
        $this->assertSame($owner->id, $circle->deleted_by_user_id);

        // Still listed for the owner, because the owner is the one who can
        // bring it back — the list is where the Deleted tab reads from.
        $this->getJson('/api/circles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $circle->id)
            ->assertJsonPath('data.0.is_deleted', true);
    }

    public function test_deleting_an_open_circle_closes_it_first_and_both_land_on_the_chain(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $client = $this->makeUser('Client', 'client@northernrail.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $this->addMember($circle, $client, CircleRole::Viewer, external: true);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/circles/{$circle->id}")->assertOk();

        $circle->refresh();
        $this->assertSame(CircleStatus::Archived, $circle->status);
        $this->assertNotNull(
            $circle->memberships()->where('user_id', $client->id)->value('revoked_at'),
            'deletion is not a way round what closure guarantees',
        );

        $types = AuditEvent::where('circle_id', $circle->id)->orderBy('sequence')->pluck('event_type')
            ->map(fn ($t) => $t->value)->all();
        $this->assertContains('circle.closed', $types);
        $this->assertSame('circle.deleted', end($types));

        $deleted = AuditEvent::where('circle_id', $circle->id)->where('event_type', 'circle.deleted')->sole();
        $this->assertTrue($deleted->metadata_json['closed_by_deletion']);

        $this->assertTrue(app(AuditChain::class)->verify($circle->id)->valid, 'the chain survives deletion intact');
    }

    public function test_a_deleted_circle_is_out_of_reach_for_every_member(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $staff = $this->makeUser('Staff', 'staff@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $this->addMember($circle, $staff, CircleRole::Approver);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/circles/{$circle->id}")->assertOk();

        $gate = app(AccessGate::class);
        $circle->refresh();

        $this->assertSame('circle_deleted', $gate->inspect($staff, Permission::CircleView, $circle)->reason);
        $this->assertSame('circle_deleted', $gate->inspect($owner, Permission::CircleView, $circle)->reason);
        $this->assertSame('circle_deleted', $gate->inspect($owner, Permission::ExportCreate, $circle)->reason);

        Sanctum::actingAs($staff);
        $this->getJson("/api/circles/{$circle->id}")->assertForbidden();

        // Someone who cannot restore it does not see it at all.
        $this->getJson('/api/circles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_the_holder_of_the_right_may_delete(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $staff = $this->makeUser('Staff', 'staff@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $this->addMember($circle, $staff, CircleRole::Approver);

        Sanctum::actingAs($staff);
        $this->deleteJson("/api/circles/{$circle->id}")->assertForbidden();

        $this->assertFalse($circle->fresh()->isDeleted());
    }

    public function test_an_archived_circle_can_be_deleted_and_is_not_closed_twice(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);
        $this->postJson("/api/circles/{$circle->id}/archive")->assertOk();
        $this->deleteJson("/api/circles/{$circle->id}")->assertOk()->assertJsonPath('data.is_deleted', true);

        $this->assertSame(1, AuditEvent::where('circle_id', $circle->id)->where('event_type', 'circle.closed')->count());

        $deleted = AuditEvent::where('circle_id', $circle->id)->where('event_type', 'circle.deleted')->sole();
        $this->assertFalse($deleted->metadata_json['closed_by_deletion']);
    }

    public function test_an_expired_circle_can_be_archived(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner, ['expires_at' => now()->subDay()]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/circles/{$circle->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_closed', true);
    }

    public function test_restoring_brings_back_the_closed_record(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $staff = $this->makeUser('Staff', 'staff@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $this->addMember($circle, $staff, CircleRole::Contributor);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/circles/{$circle->id}")->assertOk();
        $this->postJson("/api/circles/{$circle->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_deleted', false)
            ->assertJsonPath('data.is_closed', true);

        $this->assertSame(
            'circle.restored',
            AuditEvent::where('circle_id', $circle->id)->orderByDesc('sequence')->value('event_type')->value,
        );

        $gate = app(AccessGate::class);
        $circle->refresh();
        $this->assertTrue($gate->allows($staff, Permission::CircleView, $circle), 'internal read comes back');
        $this->assertSame('circle_closed', $gate->inspect($staff, Permission::ResourceUpload, $circle)->reason);

        $this->postJson("/api/circles/{$circle->id}/restore")->assertStatus(409);
    }

    public function test_deleting_revokes_open_invitations(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $invitation = Invitation::create([
            'circle_id'          => $circle->id,
            'email'              => 'late@northernrail.test',
            'circle_role'        => CircleRole::Viewer,
            'is_external'        => true,
            'token'              => Str::random(64),
            'invited_by_user_id' => $owner->id,
            'expires_at'         => now()->addDays(14),
        ]);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/circles/{$circle->id}")->assertOk();

        $this->assertFalse($invitation->fresh()->isRedeemable());
    }
}
