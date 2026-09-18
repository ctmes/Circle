<?php

namespace App\Http\Controllers\Api;

use App\Enums\PrincipalType;
use App\Enums\WorkVisibility;
use App\Models\WorkRecord;
use App\Services\Work\DiscoveryService;
use App\Services\Work\WorkRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The portable record (spec §21.3).
 *
 * The second surface in the product addressable without a Circle, and unlike
 * an opening it is addressed by *principal* — a person, a company or an agent
 * — because that is the whole point: the history follows the worker rather
 * than the employer.
 *
 * What bounds it is the same as everywhere else. The principal sees their own
 * records whatever state they are in. Everyone else sees what has been
 * published to a visibility that reaches them, and a published record cannot
 * be edited by its subject, only hidden.
 */
class WorkRecordController extends Controller
{
    public function __construct(
        private readonly WorkRecordService $records,
        private readonly DiscoveryService $discovery,
    ) {}

    /**
     * A principal's history.
     *
     * The chain is verified on every read rather than on request. A record
     * whose chain does not verify is worth less than no record at all, and a
     * verification somebody has to remember to ask for is a verification
     * nobody runs.
     */
    public function show(Request $request, string $principalType, string $principalId): JsonResponse
    {
        $type = PrincipalType::tryFrom($principalType);

        abort_if($type === null || ! $type->canHoldARecord(), 404, 'No such principal.');

        $records = $this->records->visibleTo($type, $principalId, $request->user(), $this->discovery);

        return response()->json([
            'data' => $records->map(fn (WorkRecord $r) => $this->present($r))->values()->all(),
            'meta' => [
                'principal_type' => $type->value,
                'principal_id'   => $principalId,
                'chain'          => $this->records->verify($type, $principalId),
                'summary'        => $this->summarise($records),
            ],
        ]);
    }

    /** My own record, whatever state it is in. */
    public function mine(Request $request): JsonResponse
    {
        return $this->show($request, PrincipalType::User->value, $request->user()->id);
    }

    public function attest(Request $request, WorkRecord $record): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->present($this->records->attest($record, $request->user(), $data['note'] ?? null)),
        ]);
    }

    public function publish(Request $request, WorkRecord $record): JsonResponse
    {
        $data = $request->validate([
            'visibility' => ['required', 'string', 'in:party,circle,network,public'],
        ]);

        return response()->json([
            'data' => $this->present(
                $this->records->publish($record, $request->user(), WorkVisibility::from($data['visibility'])),
            ),
        ]);
    }

    public function hide(Request $request, WorkRecord $record): JsonResponse
    {
        return response()->json(['data' => $this->present($this->records->hide($record, $request->user()))]);
    }

    // -------------------------------------------------------- presentation

    /** @return array<string, mixed> */
    private function present(WorkRecord $record): array
    {
        return [
            'id'                => $record->id,
            'sequence'          => $record->sequence,
            'title'             => $record->title,
            'summary'           => $record->summary,
            // Stored strings, not joins. A record has to keep reading
            // correctly after its Circle has been closed and deleted.
            'counterparty'      => $record->counterparty_name,
            'circle_name'       => $record->circle_name,
            'party_role'        => $record->party_role,
            'fee_basis'         => $record->fee_basis,
            'started_at'        => $record->started_at?->toIso8601String(),
            'ended_at'          => $record->ended_at?->toIso8601String(),
            'outcome'           => $record->outcome->value,
            'outcome_label'     => $record->outcome->label(),
            'metrics'           => $record->metrics_json,
            // Never folded into `metrics`. An agent that keeps proposing
            // actions its counterparty refuses is the thing a hirer most needs
            // to see, and a renderer should not be able to drop it by
            // accident.
            'refusals'          => $record->refusals_json,
            'attested'          => $record->isAttested(),
            'attested_at'       => $record->attested_at?->toIso8601String(),
            'attestation_note'  => $record->attestation_note,
            'attested_by'       => $record->attestedByParty?->label(),
            'published'         => $record->isPublished(),
            'visibility'        => $record->visibility?->value,
            'record_hash'       => $record->record_hash,
            'previous_hash'     => $record->previous_hash,
        ];
    }

    /**
     * The numbers a reader actually wants first.
     *
     * Summed across the visible records only, so a principal's own view and a
     * stranger's do not silently disagree about the totals — a summary
     * computed over hidden records would leak exactly what hiding them was for.
     *
     * @param  \Illuminate\Support\Collection<int, WorkRecord>  $records
     * @return array<string, mixed>
     */
    private function summarise(\Illuminate\Support\Collection $records): array
    {
        return [
            'engagements'           => $records->count(),
            'completed'             => $records->where('outcome.value', 'completed')->count(),
            'terminated'            => $records->filter(fn (WorkRecord $r) => $r->outcome->value === 'terminated')->count(),
            'attested'              => $records->filter(fn (WorkRecord $r) => $r->isAttested())->count(),
            'counterparties'        => $records->pluck('counterparty_name')->unique()->count(),
            'deliverables_accepted' => $records->sum(fn (WorkRecord $r) => $r->metric('deliverables_accepted')),
            'deliverables_late'     => $records->sum(fn (WorkRecord $r) => $r->metric('deliverables_late')),
            'actions_executed'      => $records->sum(fn (WorkRecord $r) => $r->metric('actions_executed')),
        ];
    }
}
