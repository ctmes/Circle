<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CircleRole;
use App\Enums\EngagementStatus;
use App\Enums\GoalStatus;
use App\Enums\OpeningStatus;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Engagement;
use App\Models\Goal;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\User;
use App\Models\WorkApplication;
use App\Models\WorkOpening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Finding a counterparty who is not already in the room (spec §21.1).
 *
 * The guarantee under test is the admission sequence: applying grants nothing,
 * shortlisting grants a bounded seat, and awarding is a merge under §20.7's
 * unchanged rule. A company that receives forty applications must expose its
 * Circle to none of them, and that is what most of these assertions are about.
 */
class OpenWorkTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    /**
     * A client with a Circle and an unassigned goal, and a contractor who is
     * nowhere near it.
     *
     * @return array{client: User, circle: \App\Models\Circle, convener: CircleParty, goal: Goal, rae: User, beam: Organisation}
     */
    private function scene(): array
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

        // Work with nobody answerable for it. That is not a gap in the plan —
        // it is the thing an opening exists to fill.
        $goal = Goal::create([
            'circle_id' => $circle->id, 'title' => 'Weld inspection complete',
            'status' => GoalStatus::Active, 'position' => 1,
            'due_at' => now()->addDays(30),
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        $beam = $this->makeOrganisation('Beam Rail');
        $rae  = $this->makeUser('Rae Nkomo', 'rae@beamrail.test');

        OrganisationMembership::create([
            'organisation_id' => $beam->id, 'user_id' => $rae->id, 'org_role' => 'member',
        ]);

        return compact('circle', 'convener', 'goal', 'rae', 'beam') + ['client' => $dana];
    }

    private function postOpening(array $scene, array $overrides = []): WorkOpening
    {
        Sanctum::actingAs($scene['client']);

        $response = $this->postJson("/api/circles/{$scene['circle']->id}/openings", array_merge([
            'title'          => 'Weld inspection, 40 joints',
            'goal_id'        => $scene['goal']->id,
            'brief'          => 'Third-party inspection against AS/NZS 1554.',
            'visibility'     => 'public',
            'fee_basis'      => 'fixed',
            'fee_amount_minor' => 1_200_000,
        ], $overrides))->assertCreated();

        $opening = WorkOpening::findOrFail($response->json('data.id'));

        $this->postJson("/api/openings/{$opening->id}/publish")->assertOk();

        return $opening->fresh();
    }

    // ------------------------------------------------------------ the board

    public function test_an_opening_is_a_draft_until_it_is_published(): void
    {
        $scene = $this->scene();

        Sanctum::actingAs($scene['client']);

        $response = $this->postJson("/api/circles/{$scene['circle']->id}/openings", [
            'title'      => 'Weld inspection, 40 joints',
            'goal_id'    => $scene['goal']->id,
            'visibility' => 'public',
        ])->assertCreated();

        $this->assertSame(OpeningStatus::Draft->value, $response->json('data.status'));

        // Nobody outside the posting party can see a draft, whatever its
        // visibility says. An opening others can read before it is offered is
        // an offer, and the status column does not change that.
        Sanctum::actingAs($scene['rae']);
        $this->getJson('/api/work')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_published_opening_reaches_a_stranger_and_says_why(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        Sanctum::actingAs($scene['rae']);

        $this->getJson('/api/work')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Weld inspection, 40 joints')
            ->assertJsonPath('data.0.can_apply', true)
            ->assertJsonPath('data.0.why_visible', 'This opening is open to anyone signed in.');
    }

    public function test_a_network_opening_stays_invisible_to_a_company_with_no_history(): void
    {
        $scene = $this->scene();
        $this->postOpening($scene, ['visibility' => 'network']);

        // Beam Rail has never been engaged by JWA Mats, so the network is
        // empty and there is nothing to see. This is the default visibility,
        // and it being empty on day one is the cold-start problem stated
        // honestly rather than hidden behind a public board.
        Sanctum::actingAs($scene['rae']);

        $this->getJson('/api/work')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.network_size', 0);
    }

    // ------------------------------------------------------- applying

    public function test_applying_grants_no_access_to_the_circle(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        Sanctum::actingAs($scene['rae']);

        $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['beam']->id,
            'statement'       => 'Two AS/NZS 1554 inspectors, available from the 14th.',
        ])->assertCreated()->assertJsonPath('data.status', 'submitted');

        // The whole point of the sequence. An applicant is not a member, and
        // nothing about applying makes them one.
        $this->assertDatabaseMissing('circle_memberships', [
            'circle_id' => $scene['circle']->id,
            'user_id'   => $scene['rae']->id,
        ]);

        $this->getJson("/api/circles/{$scene['circle']->id}")->assertForbidden();
        $this->getJson("/api/circles/{$scene['circle']->id}/goals")->assertForbidden();
    }

    public function test_an_applicant_cannot_apply_on_behalf_of_a_company_they_do_not_belong_to(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);
        $other   = $this->makeOrganisation('Someone Else Pty');

        Sanctum::actingAs($scene['rae']);

        $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $other->id,
        ])->assertForbidden();
    }

    public function test_the_posting_party_cannot_apply_to_its_own_opening(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        Sanctum::actingAs($scene['client']);

        $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['circle']->organisation_id,
        ])->assertStatus(422);
    }

    public function test_visibility_cannot_be_widened_once_applications_are_in(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene, ['visibility' => 'circle']);

        // Put an application in by hand: `circle` visibility does not reach
        // Rae, and the rule under test is about the openings that do.
        WorkApplication::create([
            'work_opening_id'   => $opening->id,
            'organisation_id'   => $scene['beam']->id,
            'applicant_user_id' => $scene['rae']->id,
            'status'            => ApplicationStatus::Submitted,
            'submitted_at'      => now(),
        ]);

        Sanctum::actingAs($scene['client']);

        // Narrowing is fine. Widening is not: somebody bid on the
        // understanding that a handful of companies could see this, and their
        // bid is already in.
        $this->patchJson("/api/openings/{$opening->id}", ['visibility' => 'party'])->assertOk();
        $this->patchJson("/api/openings/{$opening->id}", ['visibility' => 'public'])->assertStatus(422);
    }

    // ---------------------------------------------------- admission

    public function test_shortlisting_admits_the_company_and_bounds_the_seat(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        Sanctum::actingAs($scene['rae']);
        $applicationId = $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['beam']->id,
        ])->json('data.id');

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$applicationId}/shortlist")
            ->assertOk()
            ->assertJsonPath('data.status', 'shortlisted');

        $application = WorkApplication::findOrFail($applicationId);

        // A party, a seat, and a contract that has not been agreed to.
        $this->assertNotNull($application->admitted_party_id);
        $this->assertNotNull($application->goal_branch_id);

        $engagement = Engagement::where('work_opening_id', $opening->id)->firstOrFail();
        $this->assertSame(EngagementStatus::Proposed, $engagement->status);
        $this->assertSame($scene['goal']->id, $engagement->scope_goal_id);

        $seat = CircleMembership::where('circle_id', $scene['circle']->id)
            ->where('user_id', $scene['rae']->id)
            ->firstOrFail();

        $this->assertSame($engagement->id, $seat->engagement_id);
        $this->assertTrue($seat->is_external);
    }

    public function test_a_shortlisted_applicant_may_propose_but_not_change_anything(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        Sanctum::actingAs($scene['rae']);
        $applicationId = $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['beam']->id,
        ])->json('data.id');

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$applicationId}/shortlist")->assertOk();

        Sanctum::actingAs($scene['rae']);

        // In, and able to read the work they are bidding on.
        $this->getJson("/api/circles/{$scene['circle']->id}")->assertOk();

        // The negotiation window is exactly two verbs wide. Editing the plan
        // outright is not one of them, however ordinary a Contributor's
        // `goal.update` normally is — check (7) refuses it because the
        // engagement is still only proposed.
        $this->patchJson("/api/goals/{$scene['goal']->id}", ['title' => 'Renamed by the bidder'])
            ->assertForbidden();

        $this->assertSame('Weld inspection complete', $scene['goal']->fresh()->title);
    }

    // -------------------------------------------------------- the award

    public function test_awarding_merges_the_assignment_and_activates_the_contract(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        Sanctum::actingAs($scene['rae']);
        $applicationId = $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['beam']->id,
            'statement'       => 'Two inspectors, from the 14th.',
        ])->json('data.id');

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$applicationId}/shortlist")->assertOk();

        $application = WorkApplication::findOrFail($applicationId);

        // The applicant offers it. Until they do, there is nothing to award —
        // a draft branch is a proposal somebody is still writing.
        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$applicationId}/award")->assertStatus(422);

        Sanctum::actingAs($scene['rae']);
        $this->postJson("/api/branches/{$application->goal_branch_id}/propose")->assertOk();

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$applicationId}/award", ['comment' => 'Rates agreed.'])
            ->assertOk()
            ->assertJsonPath('data.application.status', 'awarded')
            ->assertJsonPath('data.engagement.status', 'active');

        // The plan changed because a branch merged, not because anybody wrote
        // to the goal — which is what makes the assignment a thing both
        // companies signed.
        $application->refresh();
        $this->assertSame('merged', $application->branch->status);
        $this->assertSame(
            $application->admitted_party_id,
            $scene['goal']->fresh()->responsible_party_id,
        );

        $this->assertSame(OpeningStatus::Filled, $opening->fresh()->status);
    }

    public function test_awarding_declines_the_others_and_takes_their_access_back(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        $rival    = $this->makeOrganisation('Rival Welding');
        $rivalRep = $this->makeUser('Sam Rivera', 'sam@rivalwelding.test');
        OrganisationMembership::create([
            'organisation_id' => $rival->id, 'user_id' => $rivalRep->id, 'org_role' => 'member',
        ]);

        Sanctum::actingAs($scene['rae']);
        $winner = $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['beam']->id,
        ])->json('data.id');

        Sanctum::actingAs($rivalRep);
        $loser = $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $rival->id,
        ])->json('data.id');

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$winner}/shortlist")->assertOk();
        $this->postJson("/api/applications/{$loser}/shortlist")->assertOk();

        Sanctum::actingAs($scene['rae']);
        $this->postJson("/api/branches/" . WorkApplication::find($winner)->goal_branch_id . '/propose')->assertOk();

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$winner}/award")->assertOk();

        $this->assertSame(ApplicationStatus::Declined, WorkApplication::find($loser)->status);

        // The runner-up was let in to talk about the work and is now out
        // again. Leaving them with a seat would mean every tender permanently
        // widened the Circle.
        Sanctum::actingAs($rivalRep);
        $this->getJson("/api/circles/{$scene['circle']->id}")->assertForbidden();
    }

    public function test_a_declined_applicant_cannot_simply_apply_again(): void
    {
        $scene   = $this->scene();
        $opening = $this->postOpening($scene);

        Sanctum::actingAs($scene['rae']);
        $applicationId = $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['beam']->id,
        ])->json('data.id');

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/applications/{$applicationId}/decline", ['reason' => 'Rate too high.'])->assertOk();

        // Re-applying to get around a refusal turns a negotiation into a
        // queue. The same rule §20.7 applies to a refused branch.
        Sanctum::actingAs($scene['rae']);
        $this->postJson("/api/work/{$opening->id}/applications", [
            'organisation_id' => $scene['beam']->id,
        ])->assertStatus(422);
    }

    public function test_work_that_already_has_a_responsible_party_cannot_be_posted(): void
    {
        $scene = $this->scene();

        $scene['goal']->forceFill(['responsible_party_id' => $scene['convener']->id])->save();

        Sanctum::actingAs($scene['client']);

        // Either a mistake or a re-tender, and the two need different
        // conversations. Refusing here forces the second one to start by
        // releasing whoever currently holds the work.
        $this->postJson("/api/circles/{$scene['circle']->id}/openings", [
            'title'   => 'Weld inspection, 40 joints',
            'goal_id' => $scene['goal']->id,
        ])->assertStatus(422);
    }
}
