<?php

namespace App\Services\Audit;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Circle;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only, tamper-evident audit log (spec §11).
 *
 *   event_hash = SHA256(canonical_json(event_without_event_hash) + previous_hash)
 *
 * The chain is scoped per Circle so that an exported Circle packet can be
 * verified on its own, without access to any other Circle's events. Events with
 * no Circle (system-level) share one global chain.
 *
 * This gives tamper evidence for the application's own stream. It is explicitly
 * NOT a legal-grade, independently anchored ledger in the MVP.
 */
class AuditChain
{
    /** Sentinel used as previous_hash for the first event in a chain. */
    public const GENESIS = 'GENESIS';

    public function record(
        AuditEventType $eventType,
        ?Circle $circle,
        ActorType $actorType,
        ?string $actorId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $resourceVersion = null,
        array $metadata = [],
    ): AuditEvent {
        $circleId = $circle?->id;

        return DB::transaction(function () use (
            $eventType, $circleId, $actorType, $actorId,
            $resourceType, $resourceId, $resourceVersion, $metadata
        ) {
            // Serialise appends per chain. Without this, two concurrent writers
            // can read the same head and fork the chain.
            $this->lockChain($circleId);

            $previousHash = $this->headHash($circleId);

            $event = new AuditEvent([
                'circle_id'        => $circleId,
                'actor_type'       => $actorType,
                'actor_id'         => $actorId,
                'event_type'       => $eventType,
                'resource_type'    => $resourceType,
                'resource_id'      => $resourceId,
                'resource_version' => $resourceVersion,
                'metadata_json'    => $metadata,
                'occurred_at'      => now(),
                'previous_hash'    => $previousHash,
            ]);

            // The id is part of the hashed payload, so it must exist first.
            $event->id = (string) Str::ulid();
            $event->event_hash = $this->computeHash($event, $previousHash);
            $event->save();

            // `sequence` is assigned by the database, and Eloquent only reads
            // back the primary key after an insert. Callers (and the History
            // API) rely on it, so fetch it rather than return a half-populated
            // record.
            $event->sequence = DB::table('audit_events')->where('id', $event->id)->value('sequence');

            return $event;
        });
    }

    public function computeHash(AuditEvent $event, ?string $previousHash): string
    {
        return hash('sha256', CanonicalJson::encode($event->hashableAttributes()) . ($previousHash ?? self::GENESIS));
    }

    /**
     * Re-computes every hash in a chain and confirms each link points at its
     * predecessor. Returns the first break found, if any.
     */
    public function verify(?string $circleId): ChainVerification
    {
        $expectedPrevious = self::GENESIS;
        $checked = 0;

        $query = AuditEvent::query()
            ->when($circleId === null,
                fn ($q) => $q->whereNull('circle_id'),
                fn ($q) => $q->where('circle_id', $circleId))
            ->orderBy('sequence');

        foreach ($query->cursor() as $event) {
            $checked++;

            if (($event->previous_hash ?? self::GENESIS) !== $expectedPrevious) {
                return ChainVerification::broken(
                    $checked, $event->id,
                    'previous_hash does not match the preceding event\'s hash (an event was removed, reordered or inserted)',
                );
            }

            $recomputed = $this->computeHash($event, $event->previous_hash);

            if (! hash_equals($event->event_hash, $recomputed)) {
                return ChainVerification::broken(
                    $checked, $event->id,
                    'event_hash does not match the event contents (the record was modified after it was written)',
                );
            }

            $expectedPrevious = $event->event_hash;
        }

        return ChainVerification::valid($checked);
    }

    private function headHash(?string $circleId): string
    {
        $head = AuditEvent::query()
            ->when($circleId === null,
                fn ($q) => $q->whereNull('circle_id'),
                fn ($q) => $q->where('circle_id', $circleId))
            ->orderByDesc('sequence')
            ->first(['event_hash']);

        return $head?->event_hash ?? self::GENESIS;
    }

    private function lockChain(?string $circleId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // sqlite test runs are single-writer already
        }

        // Transaction-scoped advisory lock, released automatically on commit.
        DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['audit_chain:' . ($circleId ?? 'global')]);
    }
}
