<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\GoalStatus;
use App\Models\CircleParty;
use App\Models\Goal;
use App\Models\GoalBranch;
use App\Models\GoalScheduleChange;
use App\Services\Goals\GoalBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Branching the plan (spec §20.7).
 *
 * The guarantees under test are all versions of one thing: a change to the plan
 * arrives with everybody's agreement or it does not arrive. Not the convener's
 * agreement — the agreement of the companies whose work it changes.
 */
class GoalBranchTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    /**
     * A client and a contractor, each with an owner, each with a goal.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\User, 2: \App\Models\Circle, 3: CircleParty, 4: CircleParty, 5: Goal, 6: Goal}
     */
    private function twoParties(): array
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

        $clientGoal = Goal::create([
            'circle_id' => $circle->id, 'title' => 'Bid package ready',
            'status' => GoalStatus::Active, 'position' => 1,
            'responsible_party_id' => $convener->id,
            'due_at' => now()->addDays(30),
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        $contractorGoal = Goal::create([
            'circle_id' => $circle->id, 'title' => 'Weld inspection complete',
            'status' => GoalStatus::Active, 'position' => 2,
            'responsible_party_id' => $contractor->id,
            'due_at' => now()->addDays(20),
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        return [$dana, $rae, $circle, $convener, $contractor, $clientGoal, $contractorGoal];
    }

    public function test_a_branch_changes_nothing_until_it_merges(): void
    {
        [$dana, , $circle, , , , $contractorGoal] = $this->twoParties();

        Sanctum::actingAs($dana);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name'   => 'Pull the weld date forward',
            'intent' => 'Freight landed early.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['due_at' => now()->addDays(10)->toISOString()],
            'reason'      => 'Freight landed a week early.',
        ])->assertCreated();

        $this->postJson("/api/branches/{$branchId}/propose")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        // The real goal has not moved. This is the entire point.
        $this->assertTrue(
            $contractorGoal->fresh()->due_at->isSameDay(now()->addDays(20)),
        );
    }

    public function test_the_party_whose_work_it_changes_must_agree(): void
    {
        [$dana, $rae, $circle, , $contractor, , $contractorGoal] = $this->twoParties();

        Sanctum::actingAs($dana);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Move the weld date',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['due_at' => now()->addDays(10)->toISOString()],
            'reason'      => 'Client wants it sooner.',
        ])->assertCreated();

        $proposed = $this->postJson("/api/branches/{$branchId}/propose")->assertOk();

        // It touches Beam Rail's goal, so Beam Rail is who it waits on.
        $proposed->assertJsonPath('data.awaiting.0.label', 'Beam Rail');

        // Dana convened this Circle and owns it. Dana still cannot sign for the
        // contractor — the signature is the only thing the contractor gets.
        $this->postJson("/api/branches/{$branchId}/approve")->assertStatus(403);
        $this->assertSame('open', GoalBranch::find($branchId)->status);
        $this->assertTrue($contractorGoal->fresh()->due_at->isSameDay(now()->addDays(20)));

        // Beam Rail signs, and only then does the plan move.
        Sanctum::actingAs($rae);
        $this->postJson("/api/branches/{$branchId}/approve", ['comment' => 'Fine, we can hit that.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'merged');

        $this->assertTrue($contractorGoal->fresh()->due_at->isSameDay(now()->addDays(10)));
    }

    public function test_a_branch_touching_both_parties_waits_for_both(): void
    {
        [$dana, $rae, $circle, , , $clientGoal, $contractorGoal] = $this->twoParties();

        Sanctum::actingAs($rae);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Retitle both deliverables',
        ])->assertCreated()->json('data.id');

        foreach ([$clientGoal, $contractorGoal] as $goal) {
            $this->postJson("/api/branches/{$branchId}/changes", [
                'change_type' => 'update',
                'goal_id'     => $goal->id,
                'attributes'  => ['title' => $goal->title . ' (rev B)'],
            ])->assertCreated();
        }

        $this->postJson("/api/branches/{$branchId}/propose")->assertOk()
            ->assertJsonCount(2, 'data.awaiting');

        // Rae signs for Beam Rail. One down, one to go.
        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonCount(1, 'data.awaiting');

        $this->assertSame('Bid package ready', $clientGoal->fresh()->title);

        Sanctum::actingAs($dana);
        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');

        $this->assertSame('Bid package ready (rev B)', $clientGoal->fresh()->title);
        $this->assertSame('Weld inspection complete (rev B)', $contractorGoal->fresh()->title);
    }

    public function test_a_plan_that_moved_underneath_is_a_conflict_not_an_overwrite(): void
    {
        [$dana, $rae, $circle, , , , $contractorGoal] = $this->twoParties();

        Sanctum::actingAs($rae);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Retitle the weld goal',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['title' => 'Weld inspection and NDT'],
        ])->assertCreated();

        $this->postJson("/api/branches/{$branchId}/propose")->assertOk()
            ->assertJsonPath('data.has_conflicts', false);

        // Somebody edits main while the branch is open.
        $contractorGoal->update(['title' => 'Weld inspection (superseded)']);

        $this->getJson("/api/branches/{$branchId}")
            ->assertOk()
            ->assertJsonPath('data.has_conflicts', true)
            ->assertJsonPath('data.conflicts.0.kind', 'moved_underneath');

        // Signing a diff that no longer describes reality is refused outright.
        $this->postJson("/api/branches/{$branchId}/approve")->assertStatus(422);
        $this->assertSame('Weld inspection (superseded)', $contractorGoal->fresh()->title);

        // Rebasing re-points the branch at the plan as it now stands. What the
        // branch *wants* is untouched; only what it was looking at moves.
        $this->postJson("/api/branches/{$branchId}/rebase")->assertOk()
            ->assertJsonPath('data.has_conflicts', false);

        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');

        $this->assertSame('Weld inspection and NDT', $contractorGoal->fresh()->title);
    }

    public function test_editing_a_branch_after_someone_signed_clears_their_signature(): void
    {
        [$dana, $rae, $circle, , , $clientGoal, $contractorGoal] = $this->twoParties();

        Sanctum::actingAs($rae);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Rename the weld goal',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['title' => 'Weld inspection v2'],
        ])->assertCreated();

        $this->postJson("/api/branches/{$branchId}/propose")->assertOk();
        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');

        // Now the same trick on a branch that also touches the client, so the
        // signature has somewhere to be lost from.
        $second = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Rename both',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$second}/changes", [
            'change_type' => 'update',
            'goal_id'     => $clientGoal->id,
            'attributes'  => ['title' => 'Bid package v2'],
        ])->assertCreated();

        $this->postJson("/api/branches/{$second}/propose")->assertOk();

        Sanctum::actingAs($dana);
        $this->postJson("/api/branches/{$second}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');

        // A third branch: sign it, then add a change and confirm the signature
        // did not survive the edit.
        Sanctum::actingAs($rae);
        $third = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Weld scope',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$third}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['description' => 'Includes NDT.'],
        ])->assertCreated();

        $this->postJson("/api/branches/{$third}/propose")->assertOk();

        // Rae's own party is the only one affected, so this would merge — add a
        // client-owned change first so it stays open after Dana signs.
        $this->postJson("/api/branches/{$third}/changes", [
            'change_type' => 'update',
            'goal_id'     => $clientGoal->id,
            'attributes'  => ['description' => 'Client scope note.'],
        ])->assertCreated();

        Sanctum::actingAs($dana);
        $this->postJson("/api/branches/{$third}/approve")->assertOk()
            ->assertJsonPath('data.status', 'open');

        // Dana has signed. Rae now slips in another change.
        Sanctum::actingAs($rae);
        $this->postJson("/api/branches/{$third}/changes", [
            'change_type' => 'update',
            'goal_id'     => $clientGoal->id,
            'attributes'  => ['title' => 'Bid package v3'],
        ])->assertCreated();

        // Dana's agreement did not carry across the edit. An author cannot add
        // a clause after the counterparty has said yes.
        $this->getJson("/api/branches/{$third}")
            ->assertOk()
            ->assertJsonCount(0, 'data.signatures')
            ->assertJsonCount(2, 'data.awaiting');

        // Nothing from the third branch reached the plan: the title is still
        // what the *second* branch left it as, not what the slipped-in change
        // asked for.
        $this->assertSame('Bid package v2', $clientGoal->fresh()->title);
        $this->assertSame('open', GoalBranch::find($third)->status);
    }

    public function test_a_date_moved_by_a_branch_still_lands_in_the_schedule_record(): void
    {
        [$dana, $rae, $circle, , , , $contractorGoal] = $this->twoParties();

        Sanctum::actingAs($rae);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Slip the weld date',
        ])->assertCreated()->json('data.id');

        // A date move owes a reason on a branch exactly as it does anywhere.
        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['due_at' => now()->addDays(40)->toISOString()],
        ])->assertStatus(422);

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['due_at' => now()->addDays(40)->toISOString()],
            'reason'      => 'Consumables held at the border.',
        ])->assertCreated();

        $this->postJson("/api/branches/{$branchId}/propose")->assertOk();
        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');

        // A branch must not become the one route by which a deadline moves with
        // no record of why.
        $change = GoalScheduleChange::where('goal_id', $contractorGoal->id)->latest()->first();

        $this->assertNotNull($change);
        $this->assertStringContainsString('Consumables held at the border', $change->reason);
        $this->assertStringContainsString('Slip the weld date', $change->reason);
    }

    public function test_a_branch_can_add_a_whole_subtree_at_once(): void
    {
        [$dana, , $circle, $convener, , $clientGoal] = $this->twoParties();

        Sanctum::actingAs($dana);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Break down the bid package',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type'    => 'add',
            'parent_goal_id' => $clientGoal->id,
            'temp_key'       => 'commercials',
            'attributes'     => ['title' => 'Commercial assumptions signed off'],
        ])->assertCreated();

        // Nests under something the same branch is creating.
        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type'     => 'add',
            'parent_temp_key' => 'commercials',
            'attributes'      => ['title' => 'Margin review'],
        ])->assertCreated();

        // The overlay shows the plan as it would stand, marked.
        $overlay = $this->getJson("/api/circles/{$circle->id}/goals?branch={$branchId}")->assertOk();
        $overlay->assertJsonPath('data.0.children.0.branch.state', 'added')
            ->assertJsonPath('data.0.children.0.title', 'Commercial assumptions signed off')
            ->assertJsonPath('data.0.children.0.children.0.title', 'Margin review');

        // Nothing exists yet.
        $this->assertSame(2, Goal::where('circle_id', $circle->id)->count());

        $this->postJson("/api/branches/{$branchId}/propose")->assertOk();
        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');

        $this->assertSame(4, Goal::where('circle_id', $circle->id)->count());

        $child = Goal::where('parent_goal_id', $clientGoal->id)->firstOrFail();
        $this->assertSame('Commercial assumptions signed off', $child->title);
        $this->assertSame('Margin review', Goal::where('parent_goal_id', $child->id)->firstOrFail()->title);
    }

    public function test_a_refusal_leaves_the_branch_open_to_be_narrowed(): void
    {
        [$dana, $rae, $circle, , , , $contractorGoal] = $this->twoParties();

        Sanctum::actingAs($dana);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Move the weld date',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['due_at' => now()->addDays(5)->toISOString()],
            'reason'      => 'Client would like it sooner.',
        ])->assertCreated();

        $this->postJson("/api/branches/{$branchId}/propose")->assertOk();

        Sanctum::actingAs($rae);
        $this->postJson("/api/branches/{$branchId}/refuse", ['reason' => 'Not with one crew.'])
            ->assertOk()
            // Still open. A refusal answers a proposal; it does not kill it.
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.signatures.0.outcome', 'rejected');

        $this->assertTrue($contractorGoal->fresh()->due_at->isSameDay(now()->addDays(20)));

        // And the author can come back with something narrower.
        Sanctum::actingAs($dana);
        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $contractorGoal->id,
            'attributes'  => ['due_at' => now()->addDays(15)->toISOString()],
            'reason'      => 'Revised: two weeks rather than five days.',
        ])->assertCreated();

        Sanctum::actingAs($rae);
        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');
    }

    public function test_a_contributor_may_propose_but_not_agree(): void
    {
        [$dana, , $circle, $convener, , $clientGoal] = $this->twoParties();

        $sam = $this->makeUser('Sam Iyer', 'sam@jwamats.test');
        $this->addMember($circle, $sam, CircleRole::Contributor)
            ->update(['circle_party_id' => $convener->id]);

        Sanctum::actingAs($sam);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Suggest a rename',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $clientGoal->id,
            'attributes'  => ['title' => 'Bid package (Sam)'],
        ])->assertCreated();

        $this->postJson("/api/branches/{$branchId}/propose")->assertOk();

        // Drafting is how somebody without authority still gets heard. Agreeing
        // binds a company, and a contributor cannot bind one.
        $this->postJson("/api/branches/{$branchId}/approve")->assertStatus(403);

        Sanctum::actingAs($dana);
        $this->postJson("/api/branches/{$branchId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'merged');

        $this->assertSame('Bid package (Sam)', $clientGoal->fresh()->title);
    }

    public function test_a_move_that_would_break_the_tree_is_refused_when_it_is_drafted(): void
    {
        [$dana, , $circle, , , $clientGoal] = $this->twoParties();

        $child = Goal::create([
            'circle_id' => $circle->id, 'parent_goal_id' => $clientGoal->id,
            'title' => 'Sub-goal', 'status' => GoalStatus::Active, 'position' => 1,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        Sanctum::actingAs($dana);

        $branchId = $this->postJson("/api/circles/{$circle->id}/branches", [
            'name' => 'Reorganise',
        ])->assertCreated()->json('data.id');

        // Moving a parent under its own child would detach the subtree from the
        // root entirely. Caught at drafting rather than at merge, so nobody is
        // taken through a review for a change that was never possible.
        $this->postJson("/api/branches/{$branchId}/changes", [
            'change_type' => 'update',
            'goal_id'     => $clientGoal->id,
            'attributes'  => ['parent_goal_id' => $child->id],
        ])->assertStatus(422);
    }
}
