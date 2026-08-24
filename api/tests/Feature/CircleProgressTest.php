<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Mission progress: the figure the header ring reports.
 *
 * It is an assertion by someone who runs the Circle, so the rules that matter
 * are who may make it, that it is bounded, and that it is recorded — not how
 * it is drawn.
 */
class CircleProgressTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
    }

    public function test_a_new_circle_starts_at_zero(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->getJson("/api/circles/{$circle->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.progress_set_at', null);
    }

    public function test_the_owner_can_set_progress_and_it_is_audited(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", ['progress' => 60])
            ->assertOk()
            ->assertJsonPath('data.progress', 60);

        $this->assertSame(60, (int) $circle->fresh()->progress);
        $this->assertNotNull($circle->fresh()->progress_set_at);

        $event = AuditEvent::where('circle_id', $circle->id)
            ->where('event_type', 'circle.progress_set')
            ->sole();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame(0, $event->metadata_json['from']);
        $this->assertSame(60, $event->metadata_json['to']);
    }

    public function test_setting_the_same_figure_does_not_append_an_event(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner, ['progress' => 40]);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", ['progress' => 40])->assertOk();

        $this->assertSame(
            0,
            AuditEvent::where('circle_id', $circle->id)
                ->where('event_type', 'circle.progress_set')
                ->count(),
            'a no-op write should not add to the record',
        );
    }

    public function test_a_member_who_cannot_manage_the_circle_cannot_set_progress(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $reviewer = $this->makeUser('Reviewer', 'reviewer@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $this->addMember($circle, $reviewer, CircleRole::Reviewer);

        Sanctum::actingAs($reviewer);

        $this->patchJson("/api/circles/{$circle->id}", ['progress' => 90])->assertForbidden();
        $this->assertSame(0, (int) $circle->fresh()->progress);
    }

    public function test_progress_outside_zero_to_one_hundred_is_refused(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        foreach ([-1, 101, 1000] as $invalid) {
            $this->patchJson("/api/circles/{$circle->id}", ['progress' => $invalid])
                ->assertStatus(422);
        }

        $this->assertSame(0, (int) $circle->fresh()->progress);
    }

    public function test_a_closed_circle_keeps_the_figure_it_closed_at(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner, ['progress' => 70]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/close", ['reason' => 'Bid submitted.'])
            ->assertOk()
            ->assertJsonPath('data.progress', 70)
            ->assertJsonPath('data.is_closed', true);

        // Closing at 70% is a real outcome; the gate refuses every mutation on
        // a closed Circle, so nothing may round it up afterwards.
        $this->patchJson("/api/circles/{$circle->id}", ['progress' => 100])
            ->assertForbidden();

        $this->assertSame(70, (int) $circle->fresh()->progress);
    }
}
