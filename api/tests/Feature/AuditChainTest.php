<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Services\Audit\AuditChain;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/** Tamper evidence (spec §11). */
class AuditChainTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    private AuditChain $chain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chain = app(AuditChain::class);
    }

    private function circle()
    {
        $owner = $this->makeUser('Owner', 'chain-owner@jwamats.test');

        return [$owner, $this->makeCircle($this->makeOrganisation(), $owner)];
    }

    public function test_a_chain_of_events_verifies(): void
    {
        [$owner, $circle] = $this->circle();

        for ($i = 0; $i < 10; $i++) {
            $this->chain->record(
                AuditEventType::ResourceViewed, $circle, ActorType::User, $owner->id,
                'evidence_item', (string) \Illuminate\Support\Str::ulid(), metadata: ['n' => $i],
            );
        }

        $result = $this->chain->verify($circle->id);
        $this->assertTrue($result->valid);
        $this->assertSame(10, $result->eventsChecked);
    }

    public function test_the_first_event_chains_to_genesis(): void
    {
        [$owner, $circle] = $this->circle();

        $event = $this->chain->record(
            AuditEventType::CircleCreated, $circle, ActorType::User, $owner->id,
        );

        $this->assertSame(AuditChain::GENESIS, $event->previous_hash);
    }

    public function test_each_event_links_to_its_predecessor(): void
    {
        [$owner, $circle] = $this->circle();

        $first  = $this->chain->record(AuditEventType::CircleCreated, $circle, ActorType::User, $owner->id);
        $second = $this->chain->record(AuditEventType::ResourceUploaded, $circle, ActorType::User, $owner->id);

        $this->assertSame($first->event_hash, $second->previous_hash);
    }

    public function test_editing_a_stored_event_breaks_verification(): void
    {
        [$owner, $circle] = $this->circle();

        $this->chain->record(AuditEventType::CircleCreated, $circle, ActorType::User, $owner->id);
        $target = $this->chain->record(AuditEventType::ResourceUploaded, $circle, ActorType::User, $owner->id, metadata: ['filename' => 'rfq.pdf']);
        $this->chain->record(AuditEventType::DecisionApproved, $circle, ActorType::User, $owner->id);

        $this->assertTrue($this->chain->verify($circle->id)->valid);

        // Rewrite history directly in the database, bypassing the application.
        DB::table('audit_events')->where('id', $target->id)
            ->update(['metadata_json' => json_encode(['filename' => 'forged.pdf'])]);

        $result = $this->chain->verify($circle->id);
        $this->assertFalse($result->valid);
        $this->assertSame($target->id, $result->brokenAtEventId);
        $this->assertStringContainsString('modified after it was written', $result->reason);
    }

    public function test_deleting_an_event_breaks_verification(): void
    {
        [$owner, $circle] = $this->circle();

        $this->chain->record(AuditEventType::CircleCreated, $circle, ActorType::User, $owner->id);
        $middle = $this->chain->record(AuditEventType::ResourceUploaded, $circle, ActorType::User, $owner->id);
        $this->chain->record(AuditEventType::DecisionApproved, $circle, ActorType::User, $owner->id);

        DB::table('audit_events')->where('id', $middle->id)->delete();

        $result = $this->chain->verify($circle->id);
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('removed, reordered or inserted', $result->reason);
    }

    public function test_changing_an_actor_breaks_verification(): void
    {
        [$owner, $circle] = $this->circle();

        $this->chain->record(AuditEventType::DecisionApproved, $circle, ActorType::User, $owner->id);
        $target = $this->chain->record(AuditEventType::DecisionApproved, $circle, ActorType::User, $owner->id);

        // The classic repudiation attack: reattribute an approval.
        DB::table('audit_events')->where('id', $target->id)
            ->update(['actor_id' => 'someone-else']);

        $this->assertFalse($this->chain->verify($circle->id)->valid);
    }

    public function test_chains_are_scoped_per_circle_so_a_packet_verifies_alone(): void
    {
        $owner = $this->makeUser('Owner', 'scoped@jwamats.test');
        $org = $this->makeOrganisation();
        $a = $this->makeCircle($org, $owner, ['name' => 'Circle A']);
        $b = $this->makeCircle($org, $owner, ['name' => 'Circle B']);

        $this->chain->record(AuditEventType::CircleCreated, $a, ActorType::User, $owner->id);
        $this->chain->record(AuditEventType::CircleCreated, $b, ActorType::User, $owner->id);
        $this->chain->record(AuditEventType::ResourceUploaded, $a, ActorType::User, $owner->id);

        $this->assertTrue($this->chain->verify($a->id)->valid);
        $this->assertTrue($this->chain->verify($b->id)->valid);
        $this->assertSame(2, $this->chain->verify($a->id)->eventsChecked);
        $this->assertSame(1, $this->chain->verify($b->id)->eventsChecked);

        // Corrupting one Circle must not invalidate another's packet.
        DB::table('audit_events')->where('circle_id', $a->id)->limit(1)
            ->update(['metadata_json' => json_encode(['tampered' => true])]);

        $this->assertFalse($this->chain->verify($a->id)->valid);
        $this->assertTrue($this->chain->verify($b->id)->valid, "Circle B's chain stands on its own");
    }

    public function test_a_non_ulid_resource_id_survives_the_round_trip_intact(): void
    {
        [$owner, $circle] = $this->circle();

        // audit_events references heterogeneous subjects. If the column padded
        // or truncated the value, the chain would break on read — so this
        // guards the column type, not just the hashing.
        $reference = 'ext-system/PO-4417';

        $event = $this->chain->record(
            AuditEventType::ResourceUploaded, $circle, ActorType::User, $owner->id,
            'external_record', $reference,
        );

        $this->assertSame($reference, $event->fresh()->resource_id);
        $this->assertTrue($this->chain->verify($circle->id)->valid);
    }

    public function test_canonical_json_is_order_independent(): void
    {
        $a = CanonicalJson::encode(['b' => 1, 'a' => ['z' => 1, 'y' => 2]]);
        $b = CanonicalJson::encode(['a' => ['y' => 2, 'z' => 1], 'b' => 1]);

        $this->assertSame($a, $b, 'key order must not change the hash input');

        // Lists must keep their order — reordering is a semantic change.
        $this->assertNotSame(
            CanonicalJson::encode(['x' => [1, 2, 3]]),
            CanonicalJson::encode(['x' => [3, 2, 1]]),
        );
    }

    public function test_recorded_hash_matches_a_fresh_recomputation(): void
    {
        [$owner, $circle] = $this->circle();

        $event = $this->chain->record(
            AuditEventType::DecisionApproved, $circle, ActorType::User, $owner->id,
            'decision', 'dec-1', '2', ['outcome' => 'approved'],
        );

        $recomputed = $this->chain->computeHash($event->fresh(), $event->previous_hash);
        $this->assertSame($event->event_hash, $recomputed);
        $this->assertSame(64, strlen($event->event_hash));
    }

    public function test_sequence_is_monotonic_within_a_circle(): void
    {
        [$owner, $circle] = $this->circle();

        $sequences = [];
        for ($i = 0; $i < 5; $i++) {
            $sequences[] = $this->chain->record(
                AuditEventType::ResourceViewed, $circle, ActorType::User, $owner->id,
            )->sequence;
        }

        $sorted = $sequences;
        sort($sorted);
        $this->assertSame($sorted, $sequences);
        $this->assertSame(5, count(array_unique($sequences)));
    }

    public function test_system_events_without_a_circle_form_their_own_chain(): void
    {
        $this->chain->record(AuditEventType::ResourceProcessingFailed, null, ActorType::System, null);
        $this->chain->record(AuditEventType::ResourceProcessingFailed, null, ActorType::System, null);

        $result = $this->chain->verify(null);
        $this->assertTrue($result->valid);
        $this->assertSame(2, $result->eventsChecked);
        $this->assertSame(2, AuditEvent::whereNull('circle_id')->count());
    }
}
