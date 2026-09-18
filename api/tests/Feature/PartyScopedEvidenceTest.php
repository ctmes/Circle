<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Models\CircleParty;
use App\Models\EvidenceItem;
use App\Models\ResourceAccessOverride;
use App\Services\Agent\CircleSteward;
use App\Services\Authorisation\AccessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Party-scoped evidence (the narrow half of spec §20.5).
 *
 * The situation throughout: a convener running a package with two
 * subcontractors bidding against each other. Both put their rates in the same
 * Circle. If either can read the other's, neither puts real rates in, and the
 * Circle stops being where the work happens.
 */
class PartyScopedEvidenceTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    private AccessGate $gate;

    /** @var array<string, mixed> */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
        $this->gate  = app(AccessGate::class);
        $this->world = $this->buildTenderCircle();
    }

    /**
     * Convener plus two rival subcontractors, each with one person.
     *
     * @return array<string, mixed>
     */
    private function buildTenderCircle(): array
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

        $pile = $this->makeUser('Piling Lead', 'lead@piling.test');
        $this->addMember($circle, $pile, CircleRole::Contributor, external: true)
            ->update(['circle_party_id' => $piling->id]);

        // Groundworks' rate card, scoped to Groundworks.
        $rates = $this->makeEvidence($circle, $ground, 'Groundworks rates 2026');
        $rates->update(['restricted_to_party_id' => $groundworks->id]);

        // A drawing everyone works from, scoped to nobody.
        $shared = $this->makeEvidence($circle, $gm, 'Site layout rev C');

        return compact('circle', 'gm', 'convener', 'groundworks', 'piling', 'ground', 'pile', 'rates', 'shared');
    }

    // ------------------------------------------------------------- the gate

    public function test_a_rival_subcontractor_cannot_read_a_scoped_rate_card(): void
    {
        $decision = $this->gate->inspect(
            $this->world['pile'],
            Permission::ResourceView,
            $this->world['circle'],
            $this->world['rates']->resource,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('party_restricted', $decision->reason);
    }

    public function test_the_party_that_owns_it_reads_its_own_scoped_item(): void
    {
        $this->assertTrue($this->gate->allows(
            $this->world['ground'],
            Permission::ResourceView,
            $this->world['circle'],
            $this->world['rates']->resource,
        ));
    }

    /**
     * The one asymmetry with a party-scoped comment thread, which hides from
     * the convener too. A rate card is submitted *to* the convener, and the
     * convener receives the packet — hiding it there would be a promise the
     * product cannot keep.
     */
    public function test_the_convener_reads_a_scoped_item(): void
    {
        $this->assertTrue($this->gate->allows(
            $this->world['gm'],
            Permission::ResourceView,
            $this->world['circle'],
            $this->world['rates']->resource,
        ));
    }

    public function test_an_unscoped_item_stays_visible_to_every_party(): void
    {
        foreach (['gm', 'ground', 'pile'] as $who) {
            $this->assertTrue(
                $this->gate->allows(
                    $this->world[$who],
                    Permission::ResourceView,
                    $this->world['circle'],
                    $this->world['shared']->resource,
                ),
                "{$who} should read an item scoped to nobody",
            );
        }
    }

    public function test_the_scope_covers_download_not_just_view(): void
    {
        // Download is a distinct right, and a scope that only covered view
        // would leave the file itself reachable by its own URL.
        //
        // The rival is given the download right outright for this one check:
        // an external collaborator is refused download by an earlier rule, so
        // testing it through that member would prove nothing about the scope.
        $this->world['circle']->memberships()
            ->where('user_id', $this->world['pile']->id)
            ->update(['is_external' => false, 'circle_role' => CircleRole::Reviewer->value]);

        $decision = $this->gate->inspect(
            $this->world['pile'],
            Permission::ResourceDownload,
            $this->world['circle'],
            $this->world['rates']->resource,
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('party_restricted', $decision->reason);
    }

    /**
     * The documented way to let one named person in from outside — an assessor
     * at the principal, say — without widening the item to their whole company.
     */
    public function test_a_named_grant_outranks_the_scope(): void
    {
        ResourceAccessOverride::create([
            'resource_id'        => $this->world['rates']->resource_id,
            'user_id'            => $this->world['pile']->id,
            'permission'         => Permission::ResourceView->value,
            'allow'              => true,
            'granted_by_user_id' => $this->world['gm']->id,
        ]);

        $this->assertTrue($this->gate->allows(
            $this->world['pile'],
            Permission::ResourceView,
            $this->world['circle'],
            $this->world['rates']->resource,
        ));
    }

    /**
     * A member with no party row sits with the convener, the same fallback
     * private threads use. Without it, the convener's own staff would be the
     * one group a scope silently failed to describe.
     */
    public function test_a_member_with_no_party_row_reads_as_the_convener(): void
    {
        $analyst = $this->makeUser('Analyst', 'analyst@jwamats.test');
        $this->addMember($this->world['circle'], $analyst, CircleRole::Reviewer);

        $this->assertNull(
            $this->world['circle']->memberships()->where('user_id', $analyst->id)->value('circle_party_id'),
        );

        $this->assertTrue($this->gate->allows(
            $analyst,
            Permission::ResourceView,
            $this->world['circle'],
            $this->world['rates']->resource,
        ));
    }

    // ------------------------------------------------------------ the agent

    /**
     * The Steward is bound to the Circle, not to a party, so it holds nobody's
     * material. A Circle-level agent summarising every bidder's rates into one
     * shared answer is the leak this exists to stop, and it would not look like
     * a leak in the output.
     */
    public function test_the_steward_is_refused_a_scoped_item(): void
    {
        $this->world['rates']->update(['agent_read' => true]);

        $agent = app(CircleSteward::class)->instanceFor($this->world['circle']);

        $decision = $this->gate->inspect(
            $agent,
            Permission::ResourceAgentRead,
            $this->world['circle'],
            $this->world['rates']->resource->fresh(),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('party_restricted', $decision->reason);
    }

    // ------------------------------------------------------------- the list

    /**
     * The register is where the leak would actually happen. `show` runs the
     * gate; a list that returned every row would hand over the file name, the
     * uploader and the date — most of what a rival wanted.
     */
    public function test_the_register_hides_a_rival_partys_item(): void
    {
        Sanctum::actingAs($this->world['pile']);

        $names = collect(
            $this->getJson("/api/circles/{$this->world['circle']->id}/evidence")
                ->assertOk()
                ->json('data'),
        )->pluck('name');

        $this->assertContains('Site layout rev C', $names);
        $this->assertNotContains('Groundworks rates 2026', $names);
    }

    public function test_the_register_shows_the_owning_party_its_own_item(): void
    {
        Sanctum::actingAs($this->world['ground']);

        $rows = collect(
            $this->getJson("/api/circles/{$this->world['circle']->id}/evidence")
                ->assertOk()
                ->json('data'),
        );

        $this->assertContains('Groundworks rates 2026', $rows->pluck('name'));

        $scoped = $rows->firstWhere('name', 'Groundworks rates 2026');
        $this->assertSame('Groundworks Co', $scoped['restricted_to_party']['label']);
    }

    // ------------------------------------------------------------ authoring

    /**
     * You may scope an item to your own party. Pinning one to somebody else's
     * would be a way to hide a document from the people it belongs to.
     */
    public function test_a_member_cannot_scope_an_item_to_another_party(): void
    {
        Sanctum::actingAs($this->world['ground']);

        $this->patchJson("/api/evidence/{$this->world['shared']->id}/access", [
            'restricted_to_party_id' => $this->world['piling']->id,
        ])->assertForbidden();
    }

    public function test_the_scope_must_name_a_party_in_this_circle(): void
    {
        $elsewhere = CircleParty::create([
            'circle_id' => $this->makeCircle(
                $this->makeOrganisation('Other'),
                $this->makeUser('X', 'x@other.test'),
            )->id,
            'display_name' => 'Someone Else',
            'party_role'   => 'contractor',
            'status'       => 'active',
        ]);

        Sanctum::actingAs($this->world['gm']);

        $this->patchJson("/api/evidence/{$this->world['shared']->id}/access", [
            'restricted_to_party_id' => $elsewhere->id,
        ])->assertStatus(422);
    }

    public function test_the_convener_can_widen_a_scoped_item_back_to_the_circle(): void
    {
        Sanctum::actingAs($this->world['gm']);

        $this->patchJson("/api/evidence/{$this->world['rates']->id}/access", [
            'restricted_to_party_id' => null,
        ])->assertOk();

        $this->assertNull(EvidenceItem::find($this->world['rates']->id)->restricted_to_party_id);

        $this->assertTrue($this->gate->allows(
            $this->world['pile'],
            Permission::ResourceView,
            $this->world['circle'],
            $this->world['rates']->resource,
        ));
    }
}
