<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Models\CircleParty;
use App\Models\EvidenceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Documents filed against a node of the plan.
 *
 * Evidence carried no goal until now: it reached the tree only through the
 * claims that cited it. That is the right rule for what a claim *rests on* and
 * the wrong one for the ordinary filing everybody does — the signed drawing,
 * the delivery note — which had nowhere to live but the Circle-wide vault.
 *
 * What these pin is the boundary rather than the plumbing. The link is an
 * index onto the vault, so it must not become a way to widen who can read a
 * file: the party-scope test below is the one that matters, because a job file
 * list answering straight from the join table would leak exactly the rate card
 * the scope exists to protect.
 */
class JobEvidenceTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    public function test_a_document_filed_against_a_job_is_listed_on_it(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $item   = $this->makeEvidence($circle, $owner, 'Geotechnical report');

        Sanctum::actingAs($owner);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title' => 'Piling design signed off',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/goals/{$goal}/evidence")->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/goals/{$goal}/evidence", [
            'evidence_item_ids' => [$item->id],
        ])
            ->assertCreated()
            ->assertJsonPath('meta.attached', 1)
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.name', 'Geotechnical report')
            // Who filed it here, which is not the same fact as who uploaded it.
            ->assertJsonPath('data.0.filed.name', 'Dana');

        // The document is still the vault's, not the job's.
        $this->getJson("/api/circles/{$circle->id}/evidence")
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id);
    }

    public function test_filing_the_same_document_twice_is_a_no_op(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $item   = $this->makeEvidence($circle, $owner, 'Site photograph');

        Sanctum::actingAs($owner);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title' => 'Pour complete',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/goals/{$goal}/evidence", ['evidence_item_ids' => [$item->id]])
            ->assertCreated()
            ->assertJsonPath('meta.attached', 1);

        // A second drop of a folder somebody already filed is an ordinary
        // accident, not an error and not a duplicate row.
        $this->postJson("/api/goals/{$goal}/evidence", ['evidence_item_ids' => [$item->id]])
            ->assertCreated()
            ->assertJsonPath('meta.attached', 0)
            ->assertJsonCount(1, 'data');
    }

    public function test_unfiling_leaves_the_document_in_the_vault(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $item   = $this->makeEvidence($circle, $owner, 'Superseded drawing');

        Sanctum::actingAs($owner);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title' => 'Steel package issued',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/goals/{$goal}/evidence", ['evidence_item_ids' => [$item->id]])
            ->assertCreated();

        $this->deleteJson("/api/goals/{$goal}/evidence/{$item->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Nothing in this product destroys evidence.
        $this->assertNotNull(EvidenceItem::find($item->id));
        $this->getJson("/api/circles/{$circle->id}/evidence")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_both_directions_are_on_the_record(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $item   = $this->makeEvidence($circle, $owner, 'Method statement', filename: 'method.pdf');

        Sanctum::actingAs($owner);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title' => 'Permit issued',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/goals/{$goal}/evidence", ['evidence_item_ids' => [$item->id]])->assertCreated();
        $this->deleteJson("/api/goals/{$goal}/evidence/{$item->id}")->assertOk();

        // Filed against the goal, so the job's own history can answer for it.
        $events = collect($this->getJson("/api/circles/{$circle->id}/history?resource_id={$goal}")
            ->assertOk()
            ->json('data'))
            ->pluck('event_type');

        $this->assertContains('goal.evidence_attached', $events);
        $this->assertContains('goal.evidence_detached', $events);
    }

    /**
     * The one that matters.
     *
     * Two subcontractors bidding against each other, both with rates in the
     * same Circle. The convener can see both — a rate card is submitted *to*
     * the convener — and may file either against the package it belongs to.
     * A rival reading that same package must not learn the other's rate card
     * exists, and a file list answering straight from the join table would
     * hand over the name, the uploader and the date, which is most of what the
     * scope was protecting.
     */
    public function test_a_party_scoped_document_stays_invisible_to_a_rival_on_the_job_it_is_filed_against(): void
    {
        $org    = $this->makeOrganisation();
        $gm     = $this->makeUser('GM', 'gm@jwamats.test');
        $circle = $this->makeCircle($org, $gm);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);
        $groundworks = CircleParty::create([
            'circle_id' => $circle->id, 'display_name' => 'Groundworks Co',
            'party_role' => 'subcontractor', 'status' => 'active',
        ]);
        $piling = CircleParty::create([
            'circle_id' => $circle->id, 'display_name' => 'Piling Ltd',
            'party_role' => 'subcontractor', 'status' => 'active',
        ]);

        $circle->memberships()->where('user_id', $gm->id)->update(['circle_party_id' => $convener->id]);

        $ground = $this->makeUser('Ground Lead', 'lead@groundworks.test');
        $this->addMember($circle, $ground, CircleRole::Contributor, external: true)
            ->update(['circle_party_id' => $groundworks->id]);

        $rival = $this->makeUser('Piling Lead', 'lead@piling.test');
        $this->addMember($circle, $rival, CircleRole::Contributor, external: true)
            ->update(['circle_party_id' => $piling->id]);

        $rates = $this->makeEvidence($circle, $ground, 'Groundworks rates 2026');
        $rates->update(['restricted_to_party_id' => $groundworks->id]);

        $shared = $this->makeEvidence($circle, $gm, 'Site layout');

        Sanctum::actingAs($gm);

        $goal = $this->postJson("/api/circles/{$circle->id}/goals", [
            'title' => 'Groundworks package let',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/goals/{$goal}/evidence", [
            'evidence_item_ids' => [$rates->id, $shared->id],
        ])->assertCreated()->assertJsonCount(2, 'data');

        // The rival sees the job, and sees only the document that is theirs to see.
        Sanctum::actingAs($rival);

        $listed = collect($this->getJson("/api/goals/{$goal}/evidence")->assertOk()->json('data'));

        $this->assertSame(['Site layout'], $listed->pluck('name')->all());

        // And cannot file it there themselves to find out what it is called.
        $this->postJson("/api/goals/{$goal}/evidence", ['evidence_item_ids' => [$rates->id]])
            ->assertStatus(422);
    }
}
