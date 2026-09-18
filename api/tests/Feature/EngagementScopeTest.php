<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\EngagementStatus;
use App\Enums\FeeBasis;
use App\Enums\GoalStatus;
use App\Enums\PrincipalType;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Engagement;
use App\Models\Goal;
use App\Models\User;
use App\Services\Work\EngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * A temp contract that bounds the gate (spec §21.2).
 *
 * The claim being tested is the one that separates this from a CRM field:
 * "temp" is a property of the authorisation decision. A contract that has
 * ended, been suspended, or never started refuses writes on the very next
 * request — not when a sweep gets round to it — and a contract scoped to one
 * package refuses everything outside that package.
 */
class EngagementScopeTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    /**
     * A client, a contractor engaged for one package, and a second package
     * that has nothing to do with them.
     *
     * @return array{client: User, rae: User, circle: \App\Models\Circle, engagement: Engagement, inScope: Goal, outOfScope: Goal}
     */
    private function engaged(): array
    {
        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation('JWA Mats');
        $circle = $this->makeCircle($org, $dana);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $circle->memberships()->where('user_id', $dana->id)
            ->update(['circle_party_id' => $convener->id]);

        $beamOrg = $this->makeOrganisation('Beam Rail');
        $beam    = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $beamOrg->id,
            'display_name' => 'Beam Rail', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $package = Goal::create([
            'circle_id' => $circle->id, 'title' => 'Bogie package',
            'status' => GoalStatus::Active, 'position' => 1,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        $inScope = Goal::create([
            'circle_id' => $circle->id, 'parent_goal_id' => $package->id,
            'title' => 'Weld inspection complete',
            'status' => GoalStatus::Active, 'position' => 1,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        $outOfScope = Goal::create([
            'circle_id' => $circle->id, 'title' => 'Commercial assumptions signed off',
            'status' => GoalStatus::Active, 'position' => 2,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        $rae = $this->makeUser('Rae Nkomo', 'rae@beamrail.test');

        $engagements = app(EngagementService::class);

        $engagement = $engagements->propose(
            circle: $circle,
            actor: $dana,
            engaging: $convener,
            contractor: $beam,
            principalType: PrincipalType::User,
            principalId: $rae->id,
            title: 'Bogie package inspection',
            scope: $package,
            feeBasis: FeeBasis::Fixed,
        );

        $engagements->issueSeat($engagement, $rae, CircleRole::Contributor);

        // Straight to active. The signature path has its own test; what this
        // fixture needs is a live contract to narrow.
        $engagement->forceFill([
            'status'       => EngagementStatus::Active,
            'activated_at' => now(),
            'starts_at'    => now()->subDay(),
        ])->save();

        return [
            'client'     => $dana,
            'rae'        => $rae,
            'circle'     => $circle,
            'engagement' => $engagement->fresh(),
            'inScope'    => $inScope,
            'outOfScope' => $outOfScope,
        ];
    }

    public function test_a_scoped_engagement_permits_work_inside_the_package(): void
    {
        $scene = $this->engaged();

        Sanctum::actingAs($scene['rae']);

        $this->patchJson("/api/goals/{$scene['inScope']->id}", [
            'description' => 'Forty joints, AS/NZS 1554.',
        ])->assertOk();
    }

    public function test_a_scoped_engagement_refuses_work_outside_it(): void
    {
        $scene = $this->engaged();

        Sanctum::actingAs($scene['rae']);

        // Contributor carries `goal.update` and the Circle is wide open to
        // them by role. Check (7) is the only thing refusing this, and it is
        // what makes "you were hired for the bogie package" a fact rather than
        // an understanding.
        $this->patchJson("/api/goals/{$scene['outOfScope']->id}", [
            'description' => 'Rewritten by the contractor.',
        ])->assertForbidden();

        $this->assertNull($scene['outOfScope']->fresh()->description);
    }

    public function test_an_ended_engagement_stops_work_on_the_very_next_request(): void
    {
        $scene = $this->engaged();

        Sanctum::actingAs($scene['rae']);
        $this->patchJson("/api/goals/{$scene['inScope']->id}", ['description' => 'Before.'])->assertOk();

        // The term runs out. Nothing sweeps, nothing revokes a membership —
        // the row still says `active` and the seat is still there.
        $scene['engagement']->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->patchJson("/api/goals/{$scene['inScope']->id}", ['description' => 'After.'])
            ->assertForbidden();

        $this->assertSame('Before.', $scene['inScope']->fresh()->description);
    }

    public function test_an_engagement_that_has_not_started_permits_nothing_yet(): void
    {
        $scene = $this->engaged();

        $scene['engagement']->forceFill(['starts_at' => now()->addWeek()])->save();

        Sanctum::actingAs($scene['rae']);

        $this->patchJson("/api/goals/{$scene['inScope']->id}", ['description' => 'Early.'])
            ->assertForbidden();
    }

    public function test_suspension_stops_the_work_and_resuming_restores_it(): void
    {
        $scene = $this->engaged();

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/engagements/{$scene['engagement']->id}/suspend", [
            'reason' => 'Insurance certificate lapsed.',
        ])->assertOk()->assertJsonPath('data.permits_work', false);

        Sanctum::actingAs($scene['rae']);
        $this->patchJson("/api/goals/{$scene['inScope']->id}", ['description' => 'While suspended.'])
            ->assertForbidden();

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/engagements/{$scene['engagement']->id}/resume")->assertOk();

        Sanctum::actingAs($scene['rae']);
        $this->patchJson("/api/goals/{$scene['inScope']->id}", ['description' => 'After resuming.'])->assertOk();

        // The reason it was stopped is not cleared by resuming. "Was this
        // stopped and restarted" is one of the few things a prospective hirer
        // would actually want to know.
        $this->assertSame('Insurance certificate lapsed.', $scene['engagement']->fresh()->end_reason);
    }

    public function test_reading_is_never_narrowed_by_an_engagement(): void
    {
        $scene = $this->engaged();

        $scene['engagement']->forceFill(['status' => EngagementStatus::Suspended])->save();

        Sanctum::actingAs($scene['rae']);

        // Check (7) runs only on writes. A suspended contractor who cannot
        // read the project they are suspended from cannot find out why, and
        // the record they are part of stops being reviewable by them.
        $this->getJson("/api/circles/{$scene['circle']->id}")->assertOk();
        $this->getJson("/api/circles/{$scene['circle']->id}/goals")->assertOk();
    }

    public function test_a_contract_cannot_be_deleted_out_from_under_a_live_seat(): void
    {
        $scene = $this->engaged();

        // The constraint on `circle_memberships.engagement_id` restricts
        // rather than nulls, and that choice is the guarantee. Nulling would
        // take a contractor confined to one package and quietly hand them the
        // run of the whole Circle the moment somebody removed the contract
        // confining them — a deletion that widens access is the worst kind.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $scene['engagement']->delete();
    }

    public function test_the_scope_check_fails_closed_when_nobody_says_which_work(): void
    {
        $scene = $this->engaged();

        Sanctum::actingAs($scene['rae']);

        // A commitment with no goal names no work, and this engagement is
        // scoped to particular work. Passing it would mean the check reads in
        // the audit log as though it ran.
        $this->postJson("/api/circles/{$scene['circle']->id}/commitments", [
            'title' => 'Something, somewhere in this Circle',
        ])->assertForbidden();
    }

    public function test_a_metered_engagement_refuses_to_go_past_its_cap(): void
    {
        $scene = $this->engaged();

        $scene['engagement']->forceFill([
            'fee_basis' => FeeBasis::Hourly,
            'unit_cap'  => 2,
        ])->save();

        Sanctum::actingAs($scene['client']);

        $url = "/api/engagements/{$scene['engagement']->id}/meter";

        $this->postJson($url, ['quantity' => 1.5])->assertCreated();
        $this->postJson($url, ['quantity' => 0.5, 'note' => 'second'])->assertCreated();

        // An hourly contract with a ceiling that can be exceeded has no
        // ceiling. Enforced in the meter rather than trusted to whoever is
        // watching the total.
        $this->postJson($url, ['quantity' => 1, 'note' => 'over'])->assertStatus(422);

        $this->assertSame(2.0, $scene['engagement']->fresh()->unitsUsed());
    }

    public function test_neither_side_can_sign_for_the_other(): void
    {
        $scene = $this->engaged();

        $scene['engagement']->forceFill(['status' => EngagementStatus::Proposed])->save();

        // A third company entirely — an advisor sitting in the same Circle.
        // An owner with no party row reads as the convener, which in this
        // Circle *is* the engaging party, so the test would prove nothing.
        $advisorOrg = $this->makeOrganisation('Third Party Advisory');
        $advisor    = CircleParty::create([
            'circle_id' => $scene['circle']->id, 'organisation_id' => $advisorOrg->id,
            'display_name' => 'Third Party Advisory', 'party_role' => 'advisor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $stranger = $this->makeUser('Unrelated', 'nobody@elsewhere.test');
        $this->addMember($scene['circle'], $stranger, CircleRole::Owner, external: true)
            ->update(['circle_party_id' => $advisor->id]);

        Sanctum::actingAs($stranger);

        // An owner holds every permission there is — and still cannot agree a
        // contract between two other companies on their behalf.
        $this->postJson("/api/engagements/{$scene['engagement']->id}/agree")->assertForbidden();

        $this->assertSame(EngagementStatus::Proposed, $scene['engagement']->fresh()->status);
    }
}
