<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeeBasis;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Engagement;
use App\Models\EngagementMeterEntry;
use App\Models\Goal;
use App\Services\Authorisation\AccessGate;
use App\Services\Work\DiscoveryService;
use App\Services\Work\EngagementService;
use App\Services\Work\WorkRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Temp contracts (spec §21.2).
 *
 * Every response carries `permits_work` and, where it is false, the reason —
 * because that is the answer AccessGate is giving on every request the
 * principal makes, and a contractor who cannot see why their writes are being
 * refused will conclude the product is broken rather than that their contract
 * ended on Friday.
 */
class EngagementController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly EngagementService $engagements,
        private readonly WorkRecordService $records,
        private readonly DiscoveryService $discovery,
    ) {}

    /** Everything this person's companies are engaged on, across every Circle. */
    public function mine(Request $request): JsonResponse
    {
        $engagements = $this->discovery->engagementsFor($request->user())->limit(100)->get();

        return response()->json([
            'data' => $engagements->map(fn (Engagement $e) => $this->present($e))->all(),
        ]);
    }

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $engagements = Engagement::where('circle_id', $circle->id)
            ->with(['engagingParty.organisation', 'contractorParty.organisation', 'scopeGoal', 'opening'])
            ->orderByRaw("case status when 'active' then 0 when 'proposed' then 1 when 'suspended' then 2 else 3 end")
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $engagements->map(fn (Engagement $e) => $this->present($e))->all(),
        ]);
    }

    public function show(Request $request, Engagement $engagement): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $engagement->circle);

        return response()->json([
            'data' => $this->present($engagement, withMeter: true, withDeliverables: true),
        ]);
    }

    /**
     * Engage a company directly, without an opening.
     *
     * The ordinary case where the counterparty is already known — §21 exists
     * because that is not always true, not because it stopped being the common
     * path.
     */
    public function store(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'contractor_party_id' => ['required', 'string'],
            'principal_type'      => ['required', 'string', 'in:user,agent_instance'],
            'principal_id'        => ['required', 'string'],
            'title'               => ['required', 'string', 'max:200'],
            'terms'               => ['nullable', 'string', 'max:8000'],
            'scope_goal_id'       => ['nullable', 'string'],
            'fee_basis'           => ['nullable', 'string', 'in:fixed,hourly,daily,per_deliverable,per_action'],
            'fee_amount_minor'    => ['nullable', 'integer', 'min:0'],
            'currency'            => ['nullable', 'string', 'size:3'],
            'unit_cap'            => ['nullable', 'integer', 'min:1'],
            'starts_at'           => ['nullable', 'date'],
            'ends_at'             => ['nullable', 'date'],
        ]);

        $engaging = $this->engagements->partyFor($circle, $request->user());

        abort_if($engaging === null, 422, 'You are not in this Circle as a party, so there is nobody to engage on behalf of.');

        $engagement = $this->engagements->propose(
            circle: $circle,
            actor: $request->user(),
            engaging: $engaging,
            contractor: CircleParty::findOrFail($data['contractor_party_id']),
            principalType: PrincipalType::from($data['principal_type']),
            principalId: $data['principal_id'],
            title: $data['title'],
            scope: isset($data['scope_goal_id']) ? Goal::findOrFail($data['scope_goal_id']) : null,
            feeBasis: FeeBasis::from($data['fee_basis'] ?? 'fixed'),
            feeAmountMinor: $data['fee_amount_minor'] ?? null,
            currency: $data['currency'] ?? 'AUD',
            unitCap: $data['unit_cap'] ?? null,
            startsAt: isset($data['starts_at']) ? new \DateTimeImmutable($data['starts_at']) : null,
            endsAt: isset($data['ends_at']) ? new \DateTimeImmutable($data['ends_at']) : null,
            terms: $data['terms'] ?? null,
        );

        return response()->json(['data' => $this->present($engagement)], 201);
    }

    public function agree(Request $request, Engagement $engagement): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->present($this->engagements->agree($engagement, $request->user(), $data['comment'] ?? null)),
        ]);
    }

    public function suspend(Request $request, Engagement $engagement): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->present($this->engagements->suspend($engagement, $request->user(), $data['reason'])),
        ]);
    }

    public function resume(Request $request, Engagement $engagement): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->present($this->engagements->resume($engagement, $request->user(), $data['note'] ?? null)),
        ]);
    }

    /**
     * Finish, and compile the record.
     *
     * The record is written in the same request as the ending, not by a job
     * afterwards. An engagement that ended and whose record has not been
     * compiled is a window in which the contractor has lost their access and
     * gained nothing for it.
     */
    public function complete(Request $request, Engagement $engagement): JsonResponse
    {
        $data = $request->validate([
            'note'  => ['nullable', 'string', 'max:2000'],
            'force' => ['nullable', 'boolean'],
        ]);

        $engagement = $this->engagements->complete(
            $engagement,
            $request->user(),
            $data['note'] ?? null,
            (bool) ($data['force'] ?? false),
        );

        $record = $this->records->compile($engagement, $request->user());

        return response()->json([
            'data' => array_merge($this->present($engagement), ['record_id' => $record?->id]),
        ]);
    }

    public function terminate(Request $request, Engagement $engagement): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $engagement = $this->engagements->terminate($engagement, $request->user(), $data['reason']);
        $record     = $this->records->compile($engagement, $request->user());

        return response()->json([
            'data' => array_merge($this->present($engagement), ['record_id' => $record?->id]),
        ]);
    }

    /**
     * Add to the meter by hand.
     *
     * For the bases a person reports — hours, days. An agent's actions are
     * metered from the ledger when they execute (§21.6), and a deliverable
     * when it is accepted; neither goes through here, because a number
     * somebody types is a different kind of claim from one the system watched
     * happen, and `source_type` is what keeps them apart.
     */
    public function meter(Request $request, Engagement $engagement): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::EngagementManage, $engagement->circle);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'note'     => ['nullable', 'string', 'max:1000'],
        ]);

        $entry = $this->engagements->meter(
            engagement: $engagement,
            quantity: (float) $data['quantity'],
            note: $data['note'] ?? null,
            recordedBy: $request->user(),
        );

        return response()->json([
            'data' => [
                'entry'      => ['id' => $entry?->id, 'quantity' => $entry?->quantity()],
                'engagement' => $this->present($engagement->fresh(), withMeter: true),
            ],
        ], 201);
    }

    // -------------------------------------------------------- presentation

    /** @return array<string, mixed> */
    private function present(Engagement $engagement, bool $withMeter = false, bool $withDeliverables = false): array
    {
        $data = [
            'id'               => $engagement->id,
            'circle_id'        => $engagement->circle_id,
            'circle_name'      => $engagement->circle?->name,
            'title'            => $engagement->title,
            'terms'            => $engagement->terms,
            'status'           => $engagement->status->value,
            'status_label'     => $engagement->status->label(),
            'engaging_party'   => $engagement->engagingParty?->label(),
            'contractor_party' => $engagement->contractorParty?->label(),
            'principal_type'   => $engagement->principal_type->value,
            'principal_name'   => $engagement->principalName(),
            'scope_goal_id'    => $engagement->scope_goal_id,
            'scope'            => $engagement->scopeGoal?->title,
            'fee_basis'        => $engagement->fee_basis->value,
            'fee_basis_label'  => $engagement->fee_basis->label(),
            'fee_amount_minor' => $engagement->fee_amount_minor,
            'currency'         => $engagement->currency,
            'unit_cap'         => $engagement->unit_cap,
            'units_used'       => $engagement->unitsUsed(),
            'is_over_cap'      => $engagement->isOverCap(),
            'starts_at'        => $engagement->starts_at?->toIso8601String(),
            'ends_at'          => $engagement->ends_at?->toIso8601String(),
            'ended_at'         => $engagement->ended_at?->toIso8601String(),
            'end_reason'       => $engagement->end_reason,
            'ended_by'         => $engagement->endedByParty?->label(),
            // The answer AccessGate is giving right now, said out loud. A
            // contractor whose writes are being refused should be able to read
            // why here rather than guess from a 403.
            'permits_work'     => $engagement->permitsWork(),
            'refusal_reason'   => $engagement->refusalReason(),
        ];

        if ($withMeter) {
            $data['meter'] = $engagement->meterEntries()
                ->latest('occurred_at')
                ->limit(100)
                ->get()
                ->map(fn (EngagementMeterEntry $e) => [
                    'id'          => $e->id,
                    'unit'        => $e->unit,
                    'quantity'    => $e->quantity(),
                    'note'        => $e->note,
                    // Whether this is a thing that happened or somebody's word
                    // for it. The distinction is the reason the column exists.
                    'derived'     => $e->isDerived(),
                    'source_type' => $e->source_type,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                ])->all();
        }

        if ($withDeliverables) {
            $data['deliverables'] = $engagement->deliverables()->get()->map(fn ($c) => [
                'id'           => $c->id,
                'title'        => $c->title,
                'status'       => $c->status->value,
                'due_at'       => $c->due_at?->toIso8601String(),
                'completed_at' => $c->completed_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }
}
