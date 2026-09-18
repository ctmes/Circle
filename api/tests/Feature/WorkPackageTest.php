<?php

namespace Tests\Feature;

use App\Enums\GoalStatus;
use App\Models\CircleParty;
use App\Models\Goal;
use App\Models\OrganisationMembership;
use App\Models\WorkPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Reusable plans (spec §21.4).
 *
 * The guarantee under test is that a package is a *form*, not a copy. What
 * crosses the boundary is shape, wording and relative timing; what must not is
 * dates, parties, evidence and ids. A template that dragged the last client's
 * structure into the next project is a confidentiality incident rather than a
 * feature.
 */
class WorkPackageTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    /** A Circle with a two-level plan, dated, and a contractor holding a node. */
    private function planned(): array
    {
        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation('JWA Mats');
        $circle = $this->makeCircle($org, $dana, ['starts_at' => now()->subDays(10)]);

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
            'description' => 'Everything under the bogies.',
            'acceptance_condition' => 'Signed by the principal engineer.',
            'status' => GoalStatus::Active, 'position' => 1,
            'responsible_party_id' => $convener->id,
            'starts_at' => now(), 'due_at' => now()->addDays(30),
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        Goal::create([
            'circle_id' => $circle->id, 'parent_goal_id' => $package->id,
            'title' => 'Weld inspection complete',
            'status' => GoalStatus::Active, 'position' => 1,
            'responsible_party_id' => $beam->id,
            'starts_at' => now()->addDays(7), 'due_at' => now()->addDays(20),
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        // Work that was dropped. A template carrying every abandoned idea from
        // the last project would be edited down by hand once and then never
        // used again.
        Goal::create([
            'circle_id' => $circle->id, 'title' => 'Second supplier evaluation',
            'status' => GoalStatus::Abandoned, 'position' => 2,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        return ['client' => $dana, 'circle' => $circle, 'org' => $org, 'beam' => $beam];
    }

    public function test_capture_keeps_the_shape_and_drops_everything_identifying(): void
    {
        $scene = $this->planned();

        Sanctum::actingAs($scene['client']);

        $response = $this->postJson("/api/circles/{$scene['circle']->id}/packages", [
            'name'    => 'Rail access package',
            'summary' => 'How we run a bid review.',
        ])->assertCreated();

        $nodes = $response->json('data.nodes');

        // Two nodes, not three: the abandoned one did not come.
        $this->assertCount(1, $nodes);
        $this->assertSame('Bogie package', $nodes[0]['title']);
        $this->assertCount(1, $nodes[0]['children']);
        $this->assertSame('Weld inspection complete', $nodes[0]['children'][0]['title']);

        // The wording is the point of capturing at all.
        $this->assertSame('Signed by the principal engineer.', $nodes[0]['acceptance_condition']);

        // Offsets from the earliest date in the tree, not dates. A plan
        // captured three weeks in should instantiate as the plan, not as one
        // with three empty weeks at the front.
        $this->assertSame(0, $nodes[0]['starts_offset_days']);
        $this->assertSame(30, $nodes[0]['due_offset_days']);
        $this->assertSame(7, $nodes[0]['children'][0]['starts_offset_days']);

        // A role, never a company. Carrying the last client's identity into a
        // template is the incident this table is written to avoid.
        $this->assertSame('contractor', $nodes[0]['children'][0]['default_party_role']);
        $this->assertArrayNotHasKey('responsible_party_id', $nodes[0]['children'][0]);
    }

    public function test_instantiating_dates_the_plan_from_an_anchor_and_assigns_nobody(): void
    {
        $scene = $this->planned();

        Sanctum::actingAs($scene['client']);

        $packageId = $this->postJson("/api/circles/{$scene['circle']->id}/packages", [
            'name' => 'Rail access package',
        ])->json('data.id');

        // A fresh Circle for the next job.
        $next = $this->makeCircle($scene['org'], $scene['client'], [
            'name' => 'Southern sidings — Bid Review',
        ]);

        $anchor = now()->addMonth()->startOfDay();

        $this->postJson("/api/packages/{$packageId}/instantiate", [
            'circle_id'   => $next->id,
            'anchor_date' => $anchor->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.goals', 2);

        $root = Goal::where('circle_id', $next->id)->whereNull('parent_goal_id')->firstOrFail();

        $this->assertSame('Bogie package', $root->title);
        $this->assertSame($anchor->addDays(30)->toDateString(), $root->due_at->toDateString());

        // Nothing is assigned. Turning "this node is for a contractor" into an
        // actual company is a decision somebody makes with a name in front of
        // them — by posting it as an opening, or naming a party.
        $this->assertNull($root->responsible_party_id);
        $this->assertNull($root->children()->first()->responsible_party_id);
    }

    public function test_a_fork_records_where_the_shape_came_from(): void
    {
        $scene = $this->planned();

        Sanctum::actingAs($scene['client']);

        $packageId = $this->postJson("/api/circles/{$scene['circle']->id}/packages", [
            'name' => 'Rail access package',
        ])->json('data.id');

        $this->postJson("/api/packages/{$packageId}/publish", ['visibility' => 'public'])->assertOk();

        // Somebody at another company entirely.
        $otherOrg = $this->makeOrganisation('Southern Rail');
        $sam      = $this->makeUser('Sam Rivera', 'sam@southernrail.test');
        OrganisationMembership::create([
            'organisation_id' => $otherOrg->id, 'user_id' => $sam->id, 'org_role' => 'member',
        ]);

        Sanctum::actingAs($sam);

        $fork = $this->postJson("/api/packages/{$packageId}/fork", ['name' => 'Our bid review'])
            ->assertCreated()
            ->assertJsonPath('data.forked_from', 'Rail access package')
            ->assertJsonPath('data.lineage_depth', 1)
            ->json('data');

        $this->assertSame(2, $fork['node_count']);

        // The fork does not tell the forking company which of the original's
        // projects the shape was captured from.
        $this->assertNull(WorkPackage::find($fork['id'])->source_circle_id);

        // And it starts private. A fork inheriting a public listing would
        // republish somebody's work under a new name by default.
        $this->assertSame('party', $fork['visibility']);
    }

    public function test_an_unpublished_package_is_invisible_to_another_company(): void
    {
        $scene = $this->planned();

        Sanctum::actingAs($scene['client']);
        $packageId = $this->postJson("/api/circles/{$scene['circle']->id}/packages", [
            'name' => 'Rail access package',
        ])->json('data.id');

        $otherOrg = $this->makeOrganisation('Southern Rail');
        $sam      = $this->makeUser('Sam Rivera', 'sam@southernrail.test');
        OrganisationMembership::create([
            'organisation_id' => $otherOrg->id, 'user_id' => $sam->id, 'org_role' => 'member',
        ]);

        Sanctum::actingAs($sam);

        $this->getJson('/api/packages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/packages/{$packageId}")->assertNotFound();
        $this->postJson("/api/packages/{$packageId}/fork")->assertForbidden();
    }

    public function test_a_circle_with_no_plan_has_nothing_to_capture(): void
    {
        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation('JWA Mats'), $dana);

        Sanctum::actingAs($dana);

        $this->postJson("/api/circles/{$circle->id}/packages", ['name' => 'Empty'])
            ->assertStatus(422);
    }
}
