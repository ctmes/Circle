<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\EvidenceItem;
use App\Models\Goal;
use App\Models\User;
use App\Services\Authorisation\AccessGate;
use App\Services\Evidence\GoalFiling;
use App\Support\EvidencePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The documents filed against one piece of work.
 *
 * Separate from EvidenceController because the subject is different. That one
 * answers for the vault — every file in the Circle, with its trust axes and its
 * versions. This one answers for a job: what is attached to *this* node, filed
 * by whom, and taken off by whom.
 *
 * Authorisation follows the subject rather than the file. Filing something
 * against a job changes that job's record, so it is gated on `goal.update`
 * with the goal named as the subject — which is what confines a contractor
 * working under a scoped engagement to the package they were engaged for
 * (spec §21.2). Reading needs only `resource.view`, filtered per item.
 */
class GoalEvidenceController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly GoalFiling $filing,
    ) {}

    public function index(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ResourceView, $goal->circle);

        return response()->json(['data' => $this->filedOn($request, $goal)]);
    }

    /**
     * File one or more documents already in the vault against this job.
     *
     * A list rather than a single id, because the gesture that produces this
     * is a drop: somebody drags four drawings onto a package, and four round
     * trips would give four chances to half-succeed.
     */
    public function attach(Request $request, Goal $goal): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle, subject: $goal);

        abort_unless(
            $goal->circle->acceptsContributions(),
            422,
            'This Circle is closed, so nothing more can be filed against it.',
        );

        $data = $request->validate([
            'evidence_item_ids'   => ['required', 'array', 'min:1', 'max:250'],
            'evidence_item_ids.*' => ['required', 'string', 'exists:evidence_items,id'],
        ]);

        $partyId    = $this->viewerPartyId($request, $goal);
        $convenerId = CircleParty::convenerIdFor($goal->circle_id);

        $attached = 0;

        foreach (array_unique($data['evidence_item_ids']) as $id) {
            $item = EvidenceItem::with(['resource', 'versions', 'restrictedToParty'])->find($id);

            // Two ways this fails, both answered the same way. A file from
            // another Circle is not ours to file, and a file scoped away from
            // the caller is one they cannot see — refusing those two
            // differently would confirm the existence of the second, which is
            // most of what the scope was protecting.
            abort_if(
                $item === null
                    || $item->resource?->circle_id !== $goal->circle_id
                    || ! $item->isVisibleToParty($partyId, $convenerId),
                422,
                'That document is not in this Circle.',
            );

            if ($this->filing->attach($goal, $item, $request->user())) {
                $attached++;
            }
        }

        return response()->json([
            'data' => $this->filedOn($request, $goal),
            'meta' => ['attached' => $attached],
        ], 201);
    }

    /**
     * Take a document off this job.
     *
     * It is unfiled, not deleted: the item stays in the vault with its
     * versions and its history, and the audit trail keeps both the filing and
     * its removal. Nothing in this product destroys evidence.
     */
    public function detach(Request $request, Goal $goal, EvidenceItem $evidenceItem): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::GoalUpdate, $goal->circle, subject: $goal);

        abort_unless(
            $evidenceItem->resource?->circle_id === $goal->circle_id,
            422,
            'That document is not in this Circle.',
        );

        $this->filing->detach($goal, $evidenceItem, $request->user());

        return response()->json(['data' => $this->filedOn($request, $goal)]);
    }

    // ------------------------------------------------------------ internals

    /**
     * This job's file list, as whoever is asking sees it.
     *
     * One method rather than three, because attach and detach both answer with
     * the list afterwards: a drop that filed three of four documents has to
     * leave the caller looking at what is actually filed rather than at what
     * the browser assumed would be.
     *
     * @return list<array<string, mixed>>
     */
    private function filedOn(Request $request, Goal $goal): array
    {
        $items = $this->filing->visibleTo(
            $goal,
            $this->viewerPartyId($request, $goal),
            CircleParty::convenerIdFor($goal->circle_id),
        );

        // One query for the names rather than one per row. The filer is often
        // the uploader, and often the same person down the whole list.
        $names = User::whereIn(
            'id',
            $items->map(fn (EvidenceItem $i) => $i->pivot?->attached_by_user_id)->filter()->unique(),
        )->pluck('name', 'id');

        return $items->map(fn (EvidenceItem $i) => $this->present($i, $names))->all();
    }

    private function viewerPartyId(Request $request, Goal $goal): ?string
    {
        return CircleMembership::query()
            ->where('circle_id', $goal->circle_id)
            ->where('user_id', $request->user()->id)
            ->first()
            ?->effectivePartyId();
    }

    /**
     * A vault row, plus who filed it here.
     *
     * The pivot is carried rather than left out: "Sam attached this on the
     * 14th" is a different fact from "Sam uploaded it in March", and on a job
     * that has pulled in a document from elsewhere in the Circle it is the one
     * that explains why it is on the screen at all.
     *
     * @param  Collection<string, string|null>  $names
     * @return array<string, mixed>
     */
    private function present(EvidenceItem $item, Collection $names): array
    {
        $by = $item->pivot?->attached_by_user_id;

        return EvidencePresenter::item($item) + [
            'filed' => [
                'by'   => $by,
                'name' => $by === null ? null : $names->get($by),
                'at'   => $item->pivot?->attached_at
                    ? Carbon::parse($item->pivot->attached_at)->toISOString()
                    : null,
            ],
        ];
    }
}
