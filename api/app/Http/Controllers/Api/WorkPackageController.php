<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkVisibility;
use App\Models\Circle;
use App\Models\Goal;
use App\Models\WorkPackage;
use App\Services\Work\DiscoveryService;
use App\Services\Work\WorkPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Reusable plans (spec §21.4).
 *
 * A package is a form rather than a copy, and nothing in these responses can
 * make it otherwise: WorkPackageNode holds titles, descriptions, acceptance
 * conditions and day offsets, and has no column for evidence, parties, dates
 * or ids. What a fork carries away is the shape somebody worked out, not the
 * project they worked it out on.
 */
class WorkPackageController extends Controller
{
    public function __construct(
        private readonly WorkPackageService $packages,
        private readonly DiscoveryService $discovery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $packages = $this->packages->readableBy($request->user(), $this->discovery)->limit(100)->get();

        return response()->json([
            'data' => $packages->map(fn (WorkPackage $p) => $this->present($p))->all(),
        ]);
    }

    public function show(Request $request, WorkPackage $package): JsonResponse
    {
        $readable = $this->packages->readableBy($request->user(), $this->discovery)
            ->where('work_packages.id', $package->id)
            ->exists();

        abort_unless($readable, 404, 'No such package.');

        return response()->json([
            'data' => array_merge($this->present($package), ['nodes' => $this->packages->tree($package)]),
        ]);
    }

    /** Take the shape of a Circle's plan. */
    public function capture(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:200'],
            'summary'    => ['nullable', 'string', 'max:4000'],
            'visibility' => ['nullable', 'string', 'in:party,circle,network,public'],
        ]);

        $package = $this->packages->capture(
            circle: $circle,
            actor: $request->user(),
            name: $data['name'],
            summary: $data['summary'] ?? null,
            visibility: WorkVisibility::from($data['visibility'] ?? 'party'),
        );

        return response()->json([
            'data' => array_merge($this->present($package), ['nodes' => $this->packages->tree($package)]),
        ], 201);
    }

    /** Write it into a Circle as goals. Nothing is assigned. */
    public function instantiate(Request $request, WorkPackage $package): JsonResponse
    {
        $data = $request->validate([
            'circle_id'      => ['required', 'string'],
            'anchor_date'    => ['nullable', 'date'],
            'under_goal_id'  => ['nullable', 'string'],
        ]);

        $result = $this->packages->instantiate(
            package: $package,
            circle: Circle::findOrFail($data['circle_id']),
            actor: $request->user(),
            anchor: isset($data['anchor_date']) ? new \DateTimeImmutable($data['anchor_date']) : null,
            under: isset($data['under_goal_id']) ? Goal::findOrFail($data['under_goal_id']) : null,
        );

        return response()->json(['data' => $result], 201);
    }

    public function fork(Request $request, WorkPackage $package): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:200']]);

        $fork = $this->packages->fork($package, $request->user(), $data['name'] ?? null);

        return response()->json([
            'data' => array_merge($this->present($fork), ['nodes' => $this->packages->tree($fork)]),
        ], 201);
    }

    public function publish(Request $request, WorkPackage $package): JsonResponse
    {
        $data = $request->validate([
            'visibility' => ['required', 'string', 'in:party,circle,network,public'],
        ]);

        return response()->json([
            'data' => $this->present(
                $this->packages->publish($package, $request->user(), WorkVisibility::from($data['visibility'])),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(WorkPackage $package): array
    {
        return [
            'id'            => $package->id,
            'name'          => $package->name,
            'summary'       => $package->summary,
            'organisation'  => $package->organisation?->name,
            'version'       => $package->version,
            'visibility'    => $package->visibility->value,
            'published'     => $package->isPublished(),
            'node_count'    => $package->nodes()->count(),
            // Where the shape came from, three copies later. The one thing a
            // template library needs and a folder of documents never has.
            'forked_from'   => $package->forkedFrom?->name,
            'lineage_depth' => $package->lineageDepth(),
            'created_at'    => $package->created_at?->toIso8601String(),
        ];
    }
}
