<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Commitments, decisions and claims hang off a node of the plan (spec §20.2).
 *
 * The columns and the tree's count query have existed since the goal tree
 * landed, but nothing a person could do ever filled them: the commitment
 * endpoint validated `goal_id`, used it to pick an authorisation subject, and
 * then dropped it before the insert, while the decision and claim endpoints did
 * not accept one at all. The counts on every tree were therefore structurally
 * zero unless an agent had written the row.
 *
 * These tests pin the wiring end to end — attach over HTTP, read it back on the
 * record, and see it counted on the node — because that is the whole claim the
 * spine makes.
 */
class GoalAttachmentTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    public function test_the_record_a_person_files_against_a_goal_is_counted_on_it(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title'  => 'Bid package ready to submit',
            'due_at' => now()->addDays(30)->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/circles/{$circle->id}/commitments", [
            'title'   => 'Confirm freight rates',
            'goal_id' => $goal,
        ])->assertCreated()->assertJsonPath('data.goal.id', $goal);

        $this->postJson("/api/circles/{$circle->id}/decisions", [
            'title'            => 'Accept the geotechnical assumptions',
            'goal_id'          => $goal,
            'approver_user_id' => $owner->id,
        ])->assertCreated()->assertJsonPath('data.goal.id', $goal);

        $this->postJson("/api/circles/{$circle->id}/claims", [
            'statement'  => 'Ground bearing pressure is sufficient for the proposed mats.',
            'claim_type' => 'technical_assessment',
            'goal_id'    => $goal,
        ])->assertCreated()->assertJsonPath('data.goal.id', $goal);

        // The pips the tree draws. Before this wiring they could only ever be
        // zero for anything a person filed.
        $this->getJson("/api/circles/{$circle->id}/goals")
            ->assertOk()
            ->assertJsonPath('data.0.counts.commitments', 1)
            ->assertJsonPath('data.0.counts.decisions', 1)
            ->assertJsonPath('data.0.counts.claims', 1);
    }

    public function test_a_record_filed_against_nothing_is_still_accepted(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        // Null is a real answer: plenty of what a Circle records is about the
        // mission rather than one node, and a Circle with no tree at all has to
        // keep working exactly as it did.
        $this->postJson("/api/circles/{$circle->id}/commitments", [
            'title' => 'Keep the client updated weekly',
        ])->assertCreated()->assertJsonPath('data.goal', null);

        $this->postJson("/api/circles/{$circle->id}/claims", [
            'statement'  => 'The client prefers fortnightly reporting.',
            'claim_type' => 'factual',
        ])->assertCreated()->assertJsonPath('data.goal', null);
    }

    public function test_a_goal_from_another_circle_is_refused(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $mine   = $this->makeCircle($org, $owner);
        $theirs = $this->makeCircle($org, $owner, ['name' => 'A different mission']);

        Sanctum::actingAs($owner);

        $elsewhere = $this->postJson("/api/circles/{$theirs->id}/goals", [
            'title' => 'Not part of the first mission',
        ])->assertCreated()->json('data.id');

        // `exists:goals,id` would have waved this through and filed the row
        // under somebody else's plan, where it would then be counted.
        $this->postJson("/api/circles/{$mine->id}/commitments", [
            'title'   => 'Confirm freight rates',
            'goal_id' => $elsewhere,
        ])->assertStatus(422);

        $this->postJson("/api/circles/{$mine->id}/decisions", [
            'title'            => 'Accept the geotechnical assumptions',
            'goal_id'          => $elsewhere,
            'approver_user_id' => $owner->id,
        ])->assertStatus(422);

        $this->postJson("/api/circles/{$mine->id}/claims", [
            'statement'  => 'Ground bearing pressure is sufficient.',
            'claim_type' => 'technical_assessment',
            'goal_id'    => $elsewhere,
        ])->assertStatus(422);

        $this->getJson("/api/circles/{$theirs->id}/goals")
            ->assertOk()
            ->assertJsonPath('data.0.counts.commitments', 0)
            ->assertJsonPath('data.0.counts.decisions', 0)
            ->assertJsonPath('data.0.counts.claims', 0);
    }

    public function test_a_collection_can_be_read_for_one_node_of_the_plan(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $first = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Freight'])
            ->assertCreated()->json('data.id');
        $second = $this->postJson("/api/circles/{$circle->id}/goals", ['title' => 'Geotech'])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/circles/{$circle->id}/commitments", [
            'title' => 'Confirm rates', 'goal_id' => $first,
        ])->assertCreated();

        $this->postJson("/api/circles/{$circle->id}/commitments", [
            'title' => 'Book the survey', 'goal_id' => $second,
        ])->assertCreated();

        $this->getJson("/api/circles/{$circle->id}/commitments?goal={$first}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Confirm rates');
    }
}
