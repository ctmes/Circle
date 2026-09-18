<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeeBasis;
use App\Enums\Permission;
use App\Enums\PrincipalKind;
use App\Enums\WorkVisibility;
use App\Models\AgentBlueprintVersion;
use App\Models\Circle;
use App\Models\Goal;
use App\Models\Organisation;
use App\Models\WorkApplication;
use App\Models\WorkOpening;
use App\Services\Authorisation\AccessGate;
use App\Services\Work\DiscoveryService;
use App\Services\Work\WorkApplicationService;
use App\Services\Work\WorkOpeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Open work, and the offers to do it (spec §21.1).
 *
 * Two surfaces that look similar and are not. The Circle-scoped routes are
 * ordinary: they authorise through AccessGate like everything else in §15.
 *
 * `/work` is the exception the whole product has exactly one of. It answers
 * for a caller who is not in the Circle, so it cannot gate on membership — it
 * gates on the opening's *visibility*, through DiscoveryService, and it renders
 * WorkOpening::publicView(), which is a fixed list of the opening's own columns
 * plus the title of its goal. No relation on that array reaches the tree, the
 * evidence or the threads, so there is no widening of this endpoint that does
 * not require editing the model.
 */
class WorkOpeningController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly WorkOpeningService $openings,
        private readonly WorkApplicationService $applications,
        private readonly DiscoveryService $discovery,
    ) {}

    // ------------------------------------------------------- the open board

    /** Work this person may see, across every Circle they can reach into. */
    public function board(Request $request): JsonResponse
    {
        $openings = $this->discovery
            ->openingsFor($request->user(), includeFilled: $request->boolean('include_filled'))
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $openings->map(fn (WorkOpening $o) => $this->openings->viewFor($o, $request->user()))->all(),
            'meta' => [
                // Shown so a person can tell an empty board from an empty
                // network. "Nobody is hiring" and "you have not worked with
                // anybody yet" need different answers from the product.
                //
                // Counterparties rather than the raw network, which includes
                // the caller's own companies so that a company posting to
                // itself is not the one case visibility fails on. That is the
                // right set to *query* with and the wrong one to show.
                'network_size' => $this->discovery->counterpartyIdsFor($request->user())->count(),
            ],
        ]);
    }

    public function showPublic(Request $request, WorkOpening $opening): JsonResponse
    {
        abort_unless(
            $this->discovery->canSee($request->user(), $opening),
            404,
            'No such opening.',
        );

        return response()->json(['data' => $this->openings->viewFor($opening, $request->user())]);
    }

    /**
     * Offer to do the work.
     *
     * Grants nothing. The applicant is not a member of this Circle and does
     * not become one — see WorkApplicationService::shortlist(), which is the
     * separate act that lets them in.
     */
    public function apply(Request $request, WorkOpening $opening): JsonResponse
    {
        $data = $request->validate([
            'organisation_id'            => ['required', 'string'],
            'statement'                  => ['nullable', 'string', 'max:4000'],
            'availability'               => ['nullable', 'string', 'max:1000'],
            'fee_basis'                  => ['nullable', 'string', 'in:fixed,hourly,daily,per_deliverable,per_action'],
            'fee_amount_minor'           => ['nullable', 'integer', 'min:0'],
            'currency'                   => ['nullable', 'string', 'size:3'],
            'agent_blueprint_version_id' => ['nullable', 'string'],
        ]);

        $application = $this->applications->apply(
            opening: $opening,
            applicant: $request->user(),
            organisation: Organisation::findOrFail($data['organisation_id']),
            statement: $data['statement'] ?? null,
            feeBasis: isset($data['fee_basis']) ? FeeBasis::from($data['fee_basis']) : null,
            feeAmountMinor: $data['fee_amount_minor'] ?? null,
            currency: $data['currency'] ?? null,
            availability: $data['availability'] ?? null,
            offeredAgent: isset($data['agent_blueprint_version_id'])
                ? AgentBlueprintVersion::findOrFail($data['agent_blueprint_version_id'])
                : null,
        );

        return response()->json(['data' => $this->presentApplication($application)], 201);
    }

    /** Applications this person's companies have made, across every Circle. */
    public function myApplications(Request $request): JsonResponse
    {
        $organisationIds = $this->discovery->organisationIdsFor($request->user());

        $applications = WorkApplication::whereIn('organisation_id', $organisationIds)
            ->with(['opening.postedByParty.organisation', 'organisation', 'branch'])
            ->latest('submitted_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $applications->map(fn (WorkApplication $a) => $this->presentApplication($a))->all(),
        ]);
    }

    public function withdrawApplication(Request $request, WorkApplication $application): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->presentApplication(
                $this->applications->withdraw($application, $request->user(), $data['reason'] ?? null),
            ),
        ]);
    }

    // ------------------------------------------------- inside a Circle

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $openings = WorkOpening::where('circle_id', $circle->id)
            ->with(['postedByParty.organisation', 'goal', 'applications.organisation'])
            ->orderByRaw("case status when 'open' then 0 when 'draft' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->get()
            // A draft is invisible to everyone but the company writing it. An
            // opening the other parties can read before it is offered is an
            // offer, whatever its status column says.
            ->filter(fn (WorkOpening $o) => $o->status->value !== 'draft'
                || $this->discovery->isOnPostingSide($request->user(), $o));

        return response()->json([
            'data' => $openings->map(fn (WorkOpening $o) => $this->present($o, $request))->values()->all(),
        ]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'title'                => ['required', 'string', 'max:200'],
            'goal_id'              => ['nullable', 'string'],
            'brief'                => ['nullable', 'string', 'max:8000'],
            'acceptance_condition' => ['nullable', 'string', 'max:4000'],
            'principal_kind'       => ['nullable', 'string', 'in:human,agent,either'],
            'visibility'           => ['nullable', 'string', 'in:party,circle,network,public'],
            'fee_basis'            => ['nullable', 'string', 'in:fixed,hourly,daily,per_deliverable,per_action'],
            'fee_amount_minor'     => ['nullable', 'integer', 'min:0'],
            'currency'             => ['nullable', 'string', 'size:3'],
            'estimated_units'      => ['nullable', 'integer', 'min:1'],
            'term_starts_at'       => ['nullable', 'date'],
            'term_ends_at'         => ['nullable', 'date', 'after:term_starts_at'],
            'closes_at'            => ['nullable', 'date'],
        ]);

        $opening = $this->openings->post(
            circle: $circle,
            author: $request->user(),
            title: $data['title'],
            goal: isset($data['goal_id']) ? Goal::findOrFail($data['goal_id']) : null,
            brief: $data['brief'] ?? null,
            acceptanceCondition: $data['acceptance_condition'] ?? null,
            principalKind: PrincipalKind::from($data['principal_kind'] ?? 'either'),
            visibility: WorkVisibility::from($data['visibility'] ?? 'network'),
            feeBasis: FeeBasis::from($data['fee_basis'] ?? 'fixed'),
            feeAmountMinor: $data['fee_amount_minor'] ?? null,
            currency: $data['currency'] ?? 'AUD',
            estimatedUnits: $data['estimated_units'] ?? null,
            termStartsAt: isset($data['term_starts_at']) ? new \DateTimeImmutable($data['term_starts_at']) : null,
            termEndsAt: isset($data['term_ends_at']) ? new \DateTimeImmutable($data['term_ends_at']) : null,
            closesAt: isset($data['closes_at']) ? new \DateTimeImmutable($data['closes_at']) : null,
        );

        return response()->json(['data' => $this->present($opening, $request)], 201);
    }

    public function update(Request $request, WorkOpening $opening): JsonResponse
    {
        $data = $request->validate([
            'title'                => ['sometimes', 'string', 'max:200'],
            'brief'                => ['sometimes', 'nullable', 'string', 'max:8000'],
            'acceptance_condition' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'principal_kind'       => ['sometimes', 'string', 'in:human,agent,either'],
            'visibility'           => ['sometimes', 'string', 'in:party,circle,network,public'],
            'fee_basis'            => ['sometimes', 'string', 'in:fixed,hourly,daily,per_deliverable,per_action'],
            'fee_amount_minor'     => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency'             => ['sometimes', 'string', 'size:3'],
            'estimated_units'      => ['sometimes', 'nullable', 'integer', 'min:1'],
            'term_starts_at'       => ['sometimes', 'nullable', 'date'],
            'term_ends_at'         => ['sometimes', 'nullable', 'date'],
            'closes_at'            => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->present($this->openings->update($opening, $request->user(), $data), $request),
        ]);
    }

    public function publish(Request $request, WorkOpening $opening): JsonResponse
    {
        return response()->json([
            'data' => $this->present($this->openings->publish($opening, $request->user()), $request),
        ]);
    }

    public function close(Request $request, WorkOpening $opening): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->present($this->openings->close($opening, $request->user(), $data['reason'] ?? null), $request),
        ]);
    }

    public function shortlist(Request $request, WorkApplication $application): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->presentApplication(
                $this->applications->shortlist($application, $request->user(), $data['note'] ?? null),
            ),
        ]);
    }

    public function decline(Request $request, WorkApplication $application): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->presentApplication(
                $this->applications->decline($application, $request->user(), $data['reason'] ?? null),
            ),
        ]);
    }

    public function award(Request $request, WorkApplication $application): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $engagement = $this->applications->award($application, $request->user(), $data['comment'] ?? null);

        return response()->json([
            'data' => [
                'application' => $this->presentApplication($application->fresh()),
                'engagement'  => [
                    'id'     => $engagement->id,
                    'status' => $engagement->status->value,
                    'title'  => $engagement->title,
                ],
            ],
        ]);
    }

    // -------------------------------------------------------- presentation

    /** @return array<string, mixed> */
    private function present(WorkOpening $opening, Request $request): array
    {
        $mine = $this->discovery->isOnPostingSide($request->user(), $opening);

        return array_merge($opening->publicView(), [
            'visibility'    => $opening->visibility->value,
            'status_label'  => $opening->status->label(),
            'goal_id'       => $opening->goal_id,
            'is_mine'       => $mine,
            'reach'         => $mine ? $this->openings->reachSummary($opening) : null,
            // The applicant list is the posting party's alone. Publishing who
            // else bid would tell every applicant who their competition is,
            // and the bids would stop being honest within a week.
            'applications'  => $mine
                ? $opening->applications->map(fn (WorkApplication $a) => $this->presentApplication($a))->values()->all()
                : null,
            'application_count' => $mine ? $opening->applications->count() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function presentApplication(WorkApplication $application): array
    {
        return [
            'id'               => $application->id,
            'opening_id'       => $application->work_opening_id,
            'opening_title'    => $application->opening?->title,
            'organisation'     => $application->organisation?->name,
            'organisation_id'  => $application->organisation_id,
            'applicant'        => $application->applicant?->name,
            'status'           => $application->status->value,
            'status_label'     => $application->status->label(),
            'is_agent'         => $application->isAgentApplication(),
            'agent'            => $application->blueprintVersion?->name,
            'agent_version'    => $application->blueprintVersion?->version_number,
            'fee_basis'        => $application->fee_basis?->value,
            'fee_amount_minor' => $application->fee_amount_minor,
            'currency'         => $application->currency,
            'statement'        => $application->statement,
            'availability'     => $application->availability,
            'branch_id'        => $application->goal_branch_id,
            'branch_status'    => $application->branch?->status,
            'submitted_at'     => $application->submitted_at?->toIso8601String(),
            'shortlisted_at'   => $application->shortlisted_at?->toIso8601String(),
            'decided_at'       => $application->decided_at?->toIso8601String(),
            'decision_reason'  => $application->decision_reason,
        ];
    }
}
