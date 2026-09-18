<?php

namespace App\Services\Work;

use App\Enums\ActorType;
use App\Enums\AgentActionStatus;
use App\Enums\AuditEventType;
use App\Enums\CommitmentStatus;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\WorkOutcome;
use App\Enums\WorkVisibility;
use App\Models\AgentAction;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\Comment;
use App\Models\Commitment;
use App\Models\Engagement;
use App\Models\GoalBranch;
use App\Models\User;
use App\Models\WorkRecord;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/**
 * What a principal carries away (spec §21.3).
 *
 * The durable layer. Everything else in this product is scoped to a Circle and
 * ends at the export packet; this is the one thing that outlives it, and every
 * decision here follows from that.
 *
 * Three parties, three jobs, and none of them can do another's:
 *
 *   the platform compiles the numbers, from the audit chain and the ledgers
 *   the counterparty attests them, and cannot be the principal's own side
 *   the principal publishes, and cannot edit what a published record says
 *
 * That separation is the entire value. A record signed by the company that was
 * paying — and that is not the worker's employer — is a materially different
 * claim from a self-declared skill list, and it is the one thing a cold-start
 * marketplace cannot manufacture.
 */
class WorkRecordService
{
    /** The seed of every principal's chain, matching AuditChain's construction. */
    private const GENESIS = 'GENESIS';

    public function __construct(
        private readonly \App\Services\Authorisation\AccessGate $gate,
        private readonly \App\Services\Audit\AuditChain $audit,
    ) {}

    /**
     * Compile the record for a finished engagement.
     *
     * Idempotent by the unique index on (engagement, principal): re-running it
     * updates the figures rather than writing a second record, because an
     * engagement that ended once did not end twice. Re-compiling after
     * attestation is refused — see below.
     */
    public function compile(Engagement $engagement, ?User $actor = null): ?WorkRecord
    {
        if (! $engagement->status->isEnded()) {
            return null;
        }

        [$principalType, $principalId] = $engagement->recordPrincipal();

        if ($principalId === null) {
            return null;
        }

        $existing = WorkRecord::where('engagement_id', $engagement->id)
            ->where('principal_type', $principalType->value)
            ->where('principal_id', $principalId)
            ->first();

        // A signed record is frozen. Recompiling one after the counterparty
        // put their name to it would let the numbers move underneath a
        // signature, which is the single thing this chain exists to detect.
        if ($existing !== null && $existing->isAttested()) {
            return $existing;
        }

        $metrics  = $this->metricsFor($engagement, $principalType, $principalId);
        $refusals = $this->refusalsFor($engagement, $principalType, $principalId);

        return DB::transaction(function () use ($engagement, $principalType, $principalId, $metrics, $refusals, $existing, $actor) {
            $record = $existing ?? new WorkRecord();

            $record->fill([
                'principal_type'               => $principalType,
                'principal_id'                 => $principalId,
                'principal_organisation_id'    => $engagement->contractorParty?->organisation_id,
                'counterparty_organisation_id' => $engagement->engagingParty?->organisation_id,
                // Denormalised on purpose. The whole point of this row is that
                // it keeps reading correctly after the Circle is gone, and a
                // name resolved through three foreign keys does not.
                'counterparty_name'            => $engagement->engagingParty?->label() ?? 'a client',
                'circle_id'                    => $engagement->circle_id,
                'circle_name'                  => $engagement->circle?->name ?? 'a Circle',
                'engagement_id'                => $engagement->id,
                'title'                        => $engagement->title,
                'summary'                      => $engagement->terms,
                'party_role'                   => $engagement->contractorParty?->party_role?->value,
                'fee_basis'                    => $engagement->fee_basis->value,
                'started_at'                   => $engagement->activated_at ?? $engagement->starts_at,
                'ended_at'                     => $engagement->ended_at ?? $engagement->ends_at,
                'outcome'                      => $engagement->status->outcome() ?? WorkOutcome::Abandoned,
                'metrics_json'                 => $metrics,
                'refusals_json'                => $refusals,
            ]);

            $this->seal($record);

            $this->audit->record(
                AuditEventType::RecordCompiled, $engagement->circle,
                $actor === null ? ActorType::System : ActorType::User, $actor?->id,
                'work_record', $record->id, metadata: [
                    'principal' => $principalType->value,
                    'outcome'   => $record->outcome->value,
                    'metrics'   => $metrics,
                ],
            );

            return $record;
        });
    }

    /**
     * Compile records for everyone still engaged when a Circle closes.
     *
     * Closure ends the Circle, and an engagement nobody formally ended is
     * still work somebody did. Compiled at `abandoned` where the engagement is
     * still open, because that is the honest word for it — neither side said
     * how it finished, and inventing "completed" on their behalf would be
     * writing somebody's history for them.
     *
     * @return int how many records were written
     */
    public function compileForClosure(Circle $circle, ?User $actor = null): int
    {
        $written = 0;

        foreach (Engagement::where('circle_id', $circle->id)->get() as $engagement) {
            if (! $engagement->status->isEnded()) {
                $engagement->forceFill([
                    'status'     => \App\Enums\EngagementStatus::Expired,
                    'ended_at'   => now(),
                    'end_reason' => 'The Circle was closed.',
                ])->save();
            }

            if ($this->compile($engagement->fresh(), $actor) !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * The counterparty signs.
     *
     * The engaging party, and only them. A principal cannot attest their own
     * record — the check is on the party rather than the person, because "not
     * yourself" is not the rule. The rule is "not your side", and a colleague
     * signing for you is exactly as worthless as signing for yourself.
     */
    public function attest(WorkRecord $record, User $actor, ?string $note = null): WorkRecord
    {
        $engagement = $record->engagement;

        abort_if($engagement === null, 422, 'This record has no engagement to attest against.');
        abort_if($record->isAttested(), 422, 'This record has already been attested.');

        $this->gate->authorise($actor, Permission::RecordAttest, $engagement->circle);

        $membership = CircleMembership::where('circle_id', $engagement->circle_id)
            ->where('user_id', $actor->id)
            ->first();

        $partyId = $membership?->effectivePartyId();

        abort_unless(
            $partyId === $engagement->engaging_party_id,
            403,
            'A record is signed by the company that was paying for the work, not by the company that did it.',
        );

        return DB::transaction(function () use ($record, $actor, $partyId, $note, $engagement) {
            $record->fill([
                'attested_by_party_id' => $partyId,
                'attested_by_user_id'  => $actor->id,
                'attested_at'          => now(),
                'attestation_note'     => $note,
            ]);

            // Re-sealed, because the attestation is part of what the hash
            // covers. A signature that sat outside the digest could be moved
            // to a different record.
            $this->seal($record);

            $this->audit->record(
                AuditEventType::RecordAttested, $engagement->circle, ActorType::User, $actor->id,
                'work_record', $record->id, metadata: [
                    'outcome' => $record->outcome->value,
                    'note'    => $note,
                    'party'   => $engagement->engagingParty?->label(),
                ],
            );

            return $record->fresh();
        });
    }

    /**
     * The principal chooses who sees it.
     *
     * Publication is not hashed (see WorkRecord::hashableAttributes), so
     * publishing and hiding never disturb a signature. Hiding a bad engagement
     * is allowed and editing one is not — and a gap in an otherwise-published
     * run is itself legible, which is the right trade against the alternative
     * where nobody agrees to be measured at all.
     */
    public function publish(WorkRecord $record, User $actor, WorkVisibility $visibility): WorkRecord
    {
        $this->assertIsPrincipal($record, $actor);

        abort_unless(
            $record->isAttested(),
            422,
            'A record is publishable once the counterparty has signed it. Until then it is only our arithmetic.',
        );

        $record->forceFill([
            'visibility'   => $visibility,
            'published_at' => now(),
        ])->save();

        $this->audit->record(
            AuditEventType::RecordPublished, $record->circle, ActorType::User, $actor->id,
            'work_record', $record->id, metadata: ['visibility' => $visibility->value],
        );

        return $record->fresh();
    }

    public function hide(WorkRecord $record, User $actor): WorkRecord
    {
        $this->assertIsPrincipal($record, $actor);

        $record->forceFill(['visibility' => null, 'published_at' => null])->save();

        $this->audit->record(
            AuditEventType::RecordHidden, $record->circle, ActorType::User, $actor->id,
            'work_record', $record->id,
        );

        return $record->fresh();
    }

    // ------------------------------------------------------------- the chain

    /**
     * Hash a record onto the end of its principal's chain.
     *
     * Chained per principal rather than per Circle, and that is the whole
     * design: a record has to verify without the Circle it came from, which
     * may be closed, exported and deleted. Same construction as §11 — and the
     * same honesty applies: this is tamper evidence for the application's own
     * stream, not an independently anchored ledger.
     *
     * The lock is not decoration. Two engagements ending in the same second
     * for the same contractor would otherwise read the same head and fork the
     * chain, and a forked chain fails verification for every record after it.
     */
    private function seal(WorkRecord $record): void
    {
        DB::transaction(function () use ($record) {
            $this->lockChain($record->principal_type->value, $record->principal_id);

            $head = WorkRecord::query()
                ->where('principal_type', $record->principal_type->value)
                ->where('principal_id', $record->principal_id)
                ->when($record->exists, fn ($q) => $q->where('id', '!=', $record->id))
                ->orderByDesc('sequence')
                ->first();

            $record->id ??= (string) \Illuminate\Support\Str::ulid();

            // A re-seal keeps its place in the chain. Only a record that has
            // never been sealed takes a new sequence — otherwise attesting a
            // record would move it to the end and break every link after it.
            if (! $record->exists || $record->sequence === null) {
                $record->sequence      = ($head?->sequence ?? 0) + 1;
                $record->previous_hash = $head?->record_hash;
            }

            $record->record_hash = $this->hashOf($record);
            $record->save();
        });
    }

    public function hashOf(WorkRecord $record): string
    {
        return hash(
            'sha256',
            CanonicalJson::encode($record->hashableAttributes()) . ($record->previous_hash ?? self::GENESIS),
        );
    }

    /**
     * Re-compute a principal's whole chain and report the first break.
     *
     * @return array{ok: bool, checked: int, broken_at: ?string, reason: ?string}
     */
    public function verify(PrincipalType $principalType, string $principalId): array
    {
        $expected = null;
        $checked  = 0;

        $records = WorkRecord::query()
            ->where('principal_type', $principalType->value)
            ->where('principal_id', $principalId)
            ->orderBy('sequence')
            ->get();

        foreach ($records as $record) {
            $checked++;

            if (($record->previous_hash ?? self::GENESIS) !== ($expected ?? self::GENESIS)) {
                return [
                    'ok'        => false,
                    'checked'   => $checked,
                    'broken_at' => $record->id,
                    'reason'    => 'This record does not follow the one before it.',
                ];
            }

            if ($this->hashOf($record) !== $record->record_hash) {
                return [
                    'ok'        => false,
                    'checked'   => $checked,
                    'broken_at' => $record->id,
                    'reason'    => 'This record has been altered since it was sealed.',
                ];
            }

            $expected = $record->record_hash;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    /**
     * Records a viewer may see for a principal.
     *
     * The principal sees everything, attested or not, published or not — it is
     * their history. Everyone else sees what has been published to a
     * visibility that reaches them.
     *
     * @return \Illuminate\Support\Collection<int, WorkRecord>
     */
    public function visibleTo(PrincipalType $principalType, string $principalId, User $viewer, DiscoveryService $discovery): \Illuminate\Support\Collection
    {
        $query = WorkRecord::query()
            ->where('principal_type', $principalType->value)
            ->where('principal_id', $principalId)
            ->orderBy('sequence');

        if ($this->isPrincipal($principalType, $principalId, $viewer)) {
            return $query->get();
        }

        $network = $discovery->networkIdsFor($viewer);

        return $query
            ->whereNotNull('published_at')
            ->where(function ($q) use ($network) {
                $q->where('visibility', WorkVisibility::Public->value);
                $q->orWhere(fn ($w) => $w
                    ->where('visibility', WorkVisibility::Network->value)
                    ->whereIn('counterparty_organisation_id', $network));
            })
            ->get();
    }

    // -------------------------------------------------------------- metrics

    /**
     * The figures, compiled rather than reported.
     *
     * Every one of these is a count over rows somebody else wrote: commitments
     * a counterparty accepted, branches a counterparty merged, actions a
     * counterparty approved. Nothing here can be set by its subject, which is
     * what makes the total worth reading.
     *
     * @return array<string, int>
     */
    private function metricsFor(Engagement $engagement, PrincipalType $principalType, string $principalId): array
    {
        $deliverables = Commitment::where('engagement_id', $engagement->id);

        $met   = (clone $deliverables)->where('status', CommitmentStatus::Done->value)->get();
        $total = (clone $deliverables)->count();

        // Late is measured against the date that was agreed, and a date only
        // moves through reschedule() with a reason (§20.2). So "met late" here
        // means late against the last agreed date, not against the first one —
        // which is the number both sides would recognise.
        $late = $met->filter(fn (Commitment $c) => $c->due_at !== null
            && $c->completed_at !== null
            && $c->completed_at->greaterThan($c->due_at))->count();

        $metrics = [
            'deliverables'          => $total,
            'deliverables_accepted' => $met->count(),
            'deliverables_late'     => $late,
            'deliverables_open'     => $total - $met->count(),
            'units_metered'         => (int) round($engagement->unitsUsed()),
            'days_engaged'          => $this->daysEngaged($engagement),
            'was_suspended'         => $engagement->suspended_at !== null ? 1 : 0,
        ];

        if ($principalType === PrincipalType::User) {
            $branches = GoalBranch::where('circle_id', $engagement->circle_id)
                ->where('created_by_user_id', $principalId);

            $metrics['branches_proposed'] = (clone $branches)->whereNotNull('proposed_at')->count();
            $metrics['branches_merged']   = (clone $branches)->where('status', 'merged')->count();

            $metrics['commitments_owned'] = Commitment::where('circle_id', $engagement->circle_id)
                ->where('owner_user_id', $principalId)
                ->count();

            $metrics['comments_posted'] = Comment::query()
                ->whereHas('thread', fn ($q) => $q->where('circle_id', $engagement->circle_id))
                ->where('author_type', 'user')
                ->where('author_id', $principalId)
                ->count();

            return $metrics;
        }

        // An agent's unit of work is an action, and the ledger has all of it.
        $actions = AgentAction::where('circle_id', $engagement->circle_id)
            ->where('agent_instance_id', $engagement->principal_id);

        $metrics['actions_proposed'] = (clone $actions)->count();
        $metrics['actions_executed'] = (clone $actions)->where('status', AgentActionStatus::Executed->value)->count();
        $metrics['actions_failed']   = (clone $actions)->where('status', AgentActionStatus::Failed->value)->count();

        return $metrics;
    }

    /**
     * The unflattering half, kept where a renderer cannot drop it by accident.
     *
     * An agent that keeps proposing actions its counterparty refuses is the
     * single thing a prospective hirer most needs to see, and it is already in
     * the ledger. A record that reported only what went well would be a
     * brochure — and everybody reading one knows it, which is why they are
     * worth nothing.
     *
     * @return array<string, mixed>
     */
    private function refusalsFor(Engagement $engagement, PrincipalType $principalType, string $principalId): array
    {
        if ($principalType === PrincipalType::User) {
            $refused = GoalBranch::where('circle_id', $engagement->circle_id)
                ->where('created_by_user_id', $principalId)
                ->where('status', 'open')
                ->whereNotNull('proposed_at')
                ->count();

            return [
                'branches_unresolved' => $refused,
                'end_reason'          => $engagement->end_reason,
                'ended_by'            => $engagement->endedByParty?->label(),
            ];
        }

        $actions = AgentAction::where('circle_id', $engagement->circle_id)
            ->where('agent_instance_id', $engagement->principal_id);

        $rejected = (clone $actions)->where('status', AgentActionStatus::Rejected->value)->get();

        return [
            'actions_rejected' => $rejected->count(),
            'actions_expired'  => (clone $actions)->where('status', AgentActionStatus::Expired->value)->count(),
            // Which tools were refused, not just how many times. "It kept
            // asking to write to your finance system" and "it kept asking to
            // post a comment" are not the same warning.
            'rejected_tools'   => $rejected->pluck('tool_key')->countBy()->toArray(),
            'end_reason'       => $engagement->end_reason,
            'ended_by'         => $engagement->endedByParty?->label(),
        ];
    }

    private function daysEngaged(Engagement $engagement): int
    {
        $from = $engagement->activated_at ?? $engagement->starts_at;
        $to   = $engagement->ended_at ?? $engagement->ends_at ?? now();

        return $from === null ? 0 : max(0, (int) $from->diffInDays($to));
    }

    // -------------------------------------------------------------- helpers

    private function isPrincipal(PrincipalType $principalType, string $principalId, User $viewer): bool
    {
        return match ($principalType) {
            PrincipalType::User         => $principalId === $viewer->id,
            PrincipalType::Organisation => $viewer->organisationMemberships()
                ->where('organisation_id', $principalId)->exists(),
            // An agent's record belongs to the company that authored it — the
            // same company that carries what the agent does (§20.4).
            default => \App\Models\AgentBlueprint::where('id', $principalId)
                ->whereIn('organisation_id', $viewer->organisationMemberships()->select('organisation_id'))
                ->exists(),
        };
    }

    private function assertIsPrincipal(WorkRecord $record, User $actor): void
    {
        abort_unless(
            $this->isPrincipal($record->principal_type, $record->principal_id, $actor),
            403,
            'Only the subject of a record decides who sees it.',
        );
    }

    /**
     * Serialise appends per principal chain.
     *
     * Postgres advisory lock, keyed on the principal, matching AuditChain's
     * approach. Skipped outside Postgres so the test suite can run on SQLite.
     */
    private function lockChain(string $principalType, string $principalId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["work_record:{$principalType}:{$principalId}"]);
    }
}
