<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Models\AuditEvent;
use App\Models\Export;
use App\Services\Audit\AuditChain;
use App\Services\Circles\CircleService;
use App\Services\Exports\ExportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * The mission statement: the Circle's name and its purpose, after it opens.
 *
 * Both were writable before this and neither was recorded, which made the one
 * line every other screen frames itself with the only assertion in the product
 * that could be changed silently. What matters here is that a restatement is
 * possible, that it lands on the chain with both wordings, and that the packet
 * carries the history rather than only the latest wording.
 */
class CircleDetailsTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
    }

    public function test_the_owner_can_rename_the_circle_and_restate_its_purpose(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", [
            'name'    => 'Rail Access Package — Stage 2',
            'purpose' => 'Assemble, review and submit the stage 2 bid package.',
            'reason'  => 'Scope moved to stage 2 after the client meeting.',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rail Access Package — Stage 2')
            ->assertJsonPath('data.purpose', 'Assemble, review and submit the stage 2 bid package.');

        $this->assertSame('Rail Access Package — Stage 2', $circle->fresh()->name);
    }

    public function test_a_restatement_lands_on_the_chain_with_both_wordings(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", [
            'name'   => 'Rail Access Package — Stage 2',
            'reason' => 'Scope moved to stage 2.',
        ])->assertOk();

        $event = AuditEvent::where('circle_id', $circle->id)
            ->where('event_type', 'circle.details_changed')
            ->sole();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame('circle', $event->resource_type);
        $this->assertSame($circle->id, $event->resource_id);
        $this->assertSame(['name'], $event->metadata_json['fields']);
        $this->assertSame(
            'Rail Access Package — Bid Review',
            $event->metadata_json['changes']['name']['from'],
            'the previous wording is what makes the entry evidence rather than a notification',
        );
        $this->assertSame('Rail Access Package — Stage 2', $event->metadata_json['changes']['name']['to']);
        $this->assertSame('Scope moved to stage 2.', $event->metadata_json['reason']);

        // The purpose was not touched, so it must not appear as having moved.
        $this->assertArrayNotHasKey('purpose', $event->metadata_json['changes']);

        $this->assertTrue(app(AuditChain::class)->verify($circle->id)->valid);
    }

    public function test_the_history_reads_the_change_back_in_words(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", [
            'name'    => 'Rail Access Package — Stage 2',
            'purpose' => 'Assemble, review and submit the stage 2 bid package.',
            'reason'  => 'Scope moved to stage 2.',
        ])->assertOk();

        $response = $this->getJson("/api/circles/{$circle->id}/history?event_type=circle.details_changed")
            ->assertOk();

        $summary = $response->json('data.0.summary');

        $this->assertStringContainsString('renamed to "Rail Access Package — Stage 2"', $summary);
        $this->assertStringContainsString('purpose restated', $summary);
        $this->assertStringContainsString('Scope moved to stage 2.', $summary);
        $this->assertTrue($response->json('meta.chain.valid'));
    }

    public function test_writing_the_same_wording_back_does_not_append_an_event(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", [
            'name'    => $circle->name,
            'purpose' => $circle->purpose,
        ])->assertOk();

        $this->assertSame(
            0,
            AuditEvent::where('circle_id', $circle->id)
                ->where('event_type', 'circle.details_changed')
                ->count(),
            'a no-op write should not add to the record',
        );
    }

    public function test_a_contributor_cannot_restate_the_mission(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $contributor = $this->makeUser('Contributor', 'contributor@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $this->addMember($circle, $contributor, CircleRole::Contributor);

        Sanctum::actingAs($contributor);

        $this->patchJson("/api/circles/{$circle->id}", ['name' => 'Something else'])
            ->assertForbidden();

        $this->assertSame('Rail Access Package — Bid Review', $circle->fresh()->name);
    }

    public function test_a_closed_circle_keeps_the_wording_it_closed_with(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/close", ['reason' => 'Bid submitted.'])->assertOk();

        $this->patchJson("/api/circles/{$circle->id}", ['name' => 'Rewritten after the fact'])
            ->assertForbidden();

        $this->assertSame('Rail Access Package — Bid Review', $circle->fresh()->name);
    }

    public function test_an_empty_name_or_an_oversized_purpose_is_refused(): void
    {
        $owner = $this->makeUser('Owner', 'owner@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", ['name' => ''])->assertStatus(422);
        $this->patchJson("/api/circles/{$circle->id}", ['purpose' => str_repeat('a', 5001)])
            ->assertStatus(422);

        $this->assertSame('Rail Access Package — Bid Review', $circle->fresh()->name);
    }

    public function test_the_packet_carries_every_wording_the_mission_has_had(): void
    {
        Storage::fake('evidence');

        $owner = $this->makeUser('Owner', 'owner@jwamats.test');

        // Opened through the service rather than the fixture helper, because
        // the first revision in the packet *is* the circle.created event and a
        // Circle conjured straight into the table never wrote one.
        $circle = app(CircleService::class)->create(
            $this->makeOrganisation(),
            $owner,
            'Rail Access Package — Bid Review',
            'Assemble and review the bid package.',
        );

        Sanctum::actingAs($owner);

        $this->patchJson("/api/circles/{$circle->id}", [
            'name'   => 'Rail Access Package — Stage 2',
            'reason' => 'Scope moved to stage 2.',
        ])->assertOk();

        $export = app(ExportBuilder::class)->build(Export::create([
            'circle_id'            => $circle->id,
            'requested_by_user_id' => $owner->id,
            'status'               => 'pending',
        ]));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('evidence')->path($export->storage_key)) === true);
        $document = json_decode($zip->getFromName('circle.json'), true);
        $zip->close();

        $revisions = $document['revisions'];

        $this->assertCount(2, $revisions, 'the Circle as opened, and the one restatement since');
        $this->assertSame('circle.created', $revisions[0]['event']);
        $this->assertSame('Rail Access Package — Bid Review', $revisions[0]['changes']['name']['to']);
        $this->assertSame('circle.details_changed', $revisions[1]['event']);
        $this->assertSame('Rail Access Package — Stage 2', $revisions[1]['changes']['name']['to']);
        $this->assertSame('Scope moved to stage 2.', $revisions[1]['reason']);

        // The revision list is a convenience over the chain, not a second copy
        // of it: each entry names the event a reader can check it against.
        $events = json_decode($this->auditEventsIn($export), true);

        $this->assertContains(
            $revisions[1]['event_hash'],
            array_column($events['events'] ?? $events, 'event_hash'),
        );
    }

    /** The packet's audit_events.json, read back out of the archive. */
    private function auditEventsIn(Export $export): string
    {
        $zip = new \ZipArchive();
        $zip->open(Storage::disk('evidence')->path($export->storage_key));
        $json = $zip->getFromName('audit_events.json');
        $zip->close();

        return $json;
    }
}
