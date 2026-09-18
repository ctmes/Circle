<?php

namespace Tests\Feature;

use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Getting in without an operator.
 *
 * An organisation could previously only be created by running
 * `circle:provision-org` on a server, which made the first step of every
 * cross-company workflow in the product something the product could not do.
 * A counterparty who cannot be brought on board is a counterparty who works by
 * email, and then the record is half true — which is worse than no record.
 */
class SelfServeOnboardingTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
        Mail::fake();
    }

    public function test_a_registered_user_can_stand_up_a_company_and_open_a_circle(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'     => 'Nadia Osman',
            'email'    => 'nadia@specialist.test',
            'password' => 'a-long-enough-password',
        ])->assertCreated();

        $user = \App\Models\User::where('email', 'nadia@specialist.test')->firstOrFail();

        Sanctum::actingAs($user);

        // Before: this was a shell command on somebody else's server.
        $organisation = $this->postJson('/api/organisations', ['name' => 'Specialist Civil'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Specialist Civil')
            ->json('data');

        $this->assertDatabaseHas('organisation_memberships', [
            'organisation_id' => $organisation['id'],
            'user_id'         => $user->id,
            'org_role'        => 'owner',
        ]);

        // And the whole point: they can now open a Circle unaided.
        $this->postJson('/api/circles', [
            'organisation_id' => $organisation['id'],
            'name'            => 'Rail Access Package — Bid Review',
            'purpose'         => 'Price and submit the access package.',
        ])->assertCreated();
    }

    public function test_two_companies_may_share_a_name_and_still_get_distinct_slugs(): void
    {
        $first  = $this->makeUser('One', 'one@a.test');
        $second = $this->makeUser('Two', 'two@b.test');

        Sanctum::actingAs($first);
        $a = $this->postJson('/api/organisations', ['name' => 'Northern Rail'])->assertCreated()->json('data');

        Sanctum::actingAs($second);
        $b = $this->postJson('/api/organisations', ['name' => 'Northern Rail'])->assertCreated()->json('data');

        // Nothing here verifies who anybody is (spec §21.7), so refusing the
        // second would be enforcing a uniqueness the product cannot check.
        $this->assertNotSame($a['slug'], $b['slug']);
        $this->assertSame(2, Organisation::where('name', 'Northern Rail')->count());
    }

    public function test_creating_a_company_reaches_nothing_that_already_exists(): void
    {
        $incumbent = $this->makeUser('Dana', 'gm@jwamats.test');
        $existing  = $this->makeOrganisation('JWA Mats');
        $circle    = $this->makeCircle($existing, $incumbent);

        $stranger = $this->makeUser('Stranger', 'stranger@elsewhere.test');
        Sanctum::actingAs($stranger);

        $this->postJson('/api/organisations', ['name' => 'Elsewhere Pty'])->assertCreated();

        // Organisation membership conveys no Circle access and the gate never
        // reads it, so standing up a company reaches nobody else's work.
        $this->getJson("/api/circles/{$circle->id}")->assertForbidden();
        $this->getJson('/api/circles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_company_needs_a_name(): void
    {
        Sanctum::actingAs($this->makeUser('Someone', 'someone@a.test'));

        $this->postJson('/api/organisations', ['name' => ''])->assertStatus(422);
        $this->postJson('/api/organisations', ['name' => 'x'])->assertStatus(422);
    }

    public function test_an_anonymous_caller_cannot_create_a_company(): void
    {
        $this->postJson('/api/organisations', ['name' => 'Anonymous Co'])->assertUnauthorized();
    }
}
