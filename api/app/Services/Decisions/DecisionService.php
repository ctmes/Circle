<?php

namespace App\Services\Decisions;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\CommitmentStatus;
use App\Enums\DecisionStatus;
use App\Models\Circle;
use App\Models\Commitment;
use App\Models\CommitmentUpdate;
use App\Models\Decision;
use App\Models\DecisionApproval;
use App\Models\User;
use App\Services\Audit\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Decisions, approvals and commitments (spec §8).
 *
 * The rule this class enforces: an approval binds to an exact
 * (subject_type, subject_id, subject_version) triple. When the subject gains a
 * new version, the old approval is marked superseded rather than silently
 * carrying over — "which exact version was approved" must always have one
 * answer.
 */
class DecisionService
{
    public function __construct(private readonly AuditChain $audit) {}

    public function create(
        Circle $circle,
        User $creator,
        string $title,
        ?string $description = null,
        ?User $approver = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $subjectVersion = null,
        ?\DateTimeInterface $expiresAt = null,
    ): Decision {
        $decision = Decision::create([
            'circle_id'          => $circle->id,
            'title'              => $title,
            'description'        => $description,
            // A decision only becomes actionable once someone is named to make
            // it; without an approver it stays a draft.
            'status'             => $approver !== null ? DecisionStatus::Pending : DecisionStatus::Draft,
            'created_by_user_id' => $creator->id,
            'approver_user_id'   => $approver?->id,
            'subject_type'       => $subjectType,
            'subject_id'         => $subjectId,
            'subject_version'    => $subjectVersion,
            'expires_at'         => $expiresAt,
        ]);

        $this->audit->record(
            AuditEventType::DecisionCreated, $circle, ActorType::User, $creator->id,
            'decision', $decision->id, $subjectVersion, metadata: [
                'title'        => $title,
                'approver'     => $approver?->id,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
            ],
        );

        return $decision;
    }

    public function resolve(Decision $decision, User $actor, string $outcome, ?string $comment = null): Decision
    {
        if (! in_array($outcome, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Unknown decision outcome: {$outcome}");
        }

        return DB::transaction(function () use ($decision, $actor, $outcome, $comment) {
            // Only the assigned approver may resolve it — role permission alone
            // is not enough (spec §8).
            if ($decision->approver_user_id !== $actor->id) {
                throw new \RuntimeException('Only the assigned approver can resolve this decision.');
            }

            if ($decision->status->isResolved()) {
                throw new \RuntimeException('This decision has already been resolved.');
            }

            if ($decision->isExpired()) {
                $decision->forceFill(['status' => DecisionStatus::Expired])->save();

                throw new \RuntimeException('This decision has expired and can no longer be resolved.');
            }

            DecisionApproval::create([
                'decision_id'     => $decision->id,
                'actor_user_id'   => $actor->id,
                'outcome'         => $outcome,
                // Snapshot the subject identity at the moment of approval, so
                // the record survives later edits to the decision row.
                'subject_type'    => $decision->subject_type,
                'subject_id'      => $decision->subject_id,
                'subject_version' => $decision->subject_version,
                'comment'         => $comment,
                'occurred_at'     => now(),
            ]);

            $decision->forceFill([
                'status'             => $outcome === 'approved' ? DecisionStatus::Approved : DecisionStatus::Rejected,
                'resolved_at'        => now(),
                'resolution_comment' => $comment,
            ])->save();

            $this->audit->record(
                $outcome === 'approved' ? AuditEventType::DecisionApproved : AuditEventType::DecisionRejected,
                $decision->circle, ActorType::User, $actor->id,
                'decision', $decision->id, $decision->subject_version, metadata: [
                    'outcome'         => $outcome,
                    'comment'         => $comment,
                    'subject_type'    => $decision->subject_type,
                    'subject_id'      => $decision->subject_id,
                    'subject_version' => $decision->subject_version,
                ],
            );

            return $decision;
        });
    }

    /**
     * Invalidates approvals that were made against an earlier version of a
     * subject. Called when a new evidence version supersedes the approved one.
     *
     * @return int how many decisions were superseded
     */
    public function supersedeApprovalsFor(string $subjectType, string $subjectId, string $newVersion): int
    {
        $decisions = Decision::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', DecisionStatus::Approved->value)
            ->where(fn ($q) => $q->whereNull('subject_version')->orWhere('subject_version', '!=', $newVersion))
            ->get();

        foreach ($decisions as $decision) {
            $decision->forceFill(['status' => DecisionStatus::Superseded])->save();

            $this->audit->record(
                AuditEventType::DecisionCreated, $decision->circle, ActorType::System, null,
                'decision', $decision->id, $decision->subject_version, metadata: [
                    'event'            => 'approval_superseded',
                    'approved_version' => $decision->subject_version,
                    'new_version'      => $newVersion,
                    'reason'           => 'A new version of the approved subject was created; '
                        . 'the previous approval does not carry over.',
                ],
            );
        }

        return $decisions->count();
    }

    // -------------------------------------------------------- commitments

    public function createCommitment(
        Circle $circle,
        User $creator,
        string $title,
        ?User $owner = null,
        ?\DateTimeInterface $dueAt = null,
        ?string $description = null,
        ?string $acceptanceCondition = null,
        CommitmentStatus $status = CommitmentStatus::Open,
    ): Commitment {
        $commitment = Commitment::create([
            'circle_id'            => $circle->id,
            'title'                => $title,
            'description'          => $description,
            'acceptance_condition' => $acceptanceCondition,
            'status'               => $status,
            'owner_user_id'        => $owner?->id,
            'created_by_user_id'   => $creator->id,
            'created_by_type'      => 'user',
            'due_at'               => $dueAt,
        ]);

        $this->audit->record(
            AuditEventType::CommitmentCreated, $circle, ActorType::User, $creator->id,
            'commitment', $commitment->id, metadata: [
                'title'  => $title,
                'owner'  => $owner?->id,
                'due_at' => $dueAt?->format(DATE_ATOM),
            ],
        );

        return $commitment;
    }

    public function updateCommitment(
        Commitment $commitment,
        User $actor,
        ?CommitmentStatus $status = null,
        ?string $note = null,
        ?\DateTimeInterface $dueAt = null,
    ): Commitment {
        return DB::transaction(function () use ($commitment, $actor, $status, $note, $dueAt) {
            $from = $commitment->status;

            $changes = [];

            if ($status !== null && $status !== $from) {
                $changes['status'] = $status;
                $changes['completed_at'] = $status === CommitmentStatus::Done ? now() : null;
            }

            if ($dueAt !== null) {
                $changes['due_at'] = $dueAt;
            }

            if ($changes !== []) {
                $commitment->forceFill($changes)->save();
            }

            CommitmentUpdate::create([
                'commitment_id' => $commitment->id,
                'actor_user_id' => $actor->id,
                'from_status'   => $from->value,
                'to_status'     => ($status ?? $from)->value,
                'note'          => $note,
            ]);

            $this->audit->record(
                AuditEventType::CommitmentUpdated, $commitment->circle, ActorType::User, $actor->id,
                'commitment', $commitment->id, metadata: [
                    'from' => $from->value,
                    'to'   => ($status ?? $from)->value,
                    'note' => $note,
                ],
            );

            return $commitment;
        });
    }
}
