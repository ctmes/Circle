<?php

namespace App\Services\Work;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Enums\WorkVisibility;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\Goal;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageNode;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use App\Services\Goals\GoalService;
use Illuminate\Support\Facades\DB;

/**
 * Capturing, forking and instantiating the shape of a plan (spec §21.4).
 *
 * The one rule that governs every method: a package is a *form*, not a copy.
 *
 * What crosses the boundary is titles, descriptions, acceptance conditions,
 * shape and relative timing. What does not is evidence, claims, decisions,
 * threads, parties, dates and every id. A template that dragged the last
 * client's structure into the next project is a confidentiality incident
 * rather than a feature, and the only reliable defence is a capture that
 * cannot express those things at all — which is why WorkPackageNode has no
 * column for them.
 */
class WorkPackageService
{
    /** Matches the goal tree's own cap (spec §20.7). */
    private const MAX_DEPTH = 4;

    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly GoalService $goals,
    ) {}

    /**
     * Take the shape of a Circle's plan.
     *
     * Dates become offsets from the earliest date in the tree, rather than
     * from the Circle's start: a plan captured three weeks into a project
     * should instantiate as the plan, not as a plan with three empty weeks at
     * the front. Where nothing has a date, the offsets are null and
     * instantiating produces an undated tree — which is honest, and is what
     * the source looked like.
     */
    public function capture(
        Circle $circle,
        User $actor,
        string $name,
        ?string $summary = null,
        WorkVisibility $visibility = WorkVisibility::Party,
    ): WorkPackage {
        $this->gate->authorise($actor, Permission::PackagePublish, $circle);

        $organisationId = $this->organisationFor($circle, $actor);

        $goals = Goal::where('circle_id', $circle->id)->orderBy('position')->get();

        abort_if($goals->isEmpty(), 422, 'This Circle has no plan to capture.');

        $anchor = $goals->pluck('starts_at')->filter()
            ->merge($goals->pluck('due_at')->filter())
            ->sort()
            ->first();

        return DB::transaction(function () use ($circle, $actor, $name, $summary, $visibility, $organisationId, $goals, $anchor) {
            $package = WorkPackage::create([
                'organisation_id'    => $organisationId,
                'name'               => $name,
                'summary'            => $summary,
                'source_circle_id'   => $circle->id,
                'version'            => 1,
                'visibility'         => $visibility,
                'created_by_user_id' => $actor->id,
            ]);

            $map = [];

            // Ordered by depth so a parent node always exists before its child
            // needs it. The tree is at most four deep (§20.7), so this is four
            // passes rather than a recursive walk that could loop on a cycle.
            $byParent = $goals->groupBy('parent_goal_id');

            $write = function (?string $parentGoalId, ?string $parentNodeId, int $depth) use (&$write, &$map, $byParent, $package, $anchor) {
                if ($depth > self::MAX_DEPTH) {
                    return;
                }

                foreach ($byParent->get($parentGoalId, collect()) as $goal) {
                    // Abandoned work is not part of the shape. A package that
                    // carried every dropped idea from the last project would
                    // be edited down by hand once and then never used again.
                    if ($goal->status->value === 'abandoned') {
                        continue;
                    }

                    $node = WorkPackageNode::create([
                        'work_package_id'      => $package->id,
                        'parent_node_id'       => $parentNodeId,
                        'title'                => $goal->title,
                        'description'          => $goal->description,
                        'acceptance_condition' => $goal->acceptance_condition,
                        'starts_offset_days'   => $this->offset($anchor, $goal->starts_at),
                        'due_offset_days'      => $this->offset($anchor, $goal->due_at),
                        // The *role* the work was done by, never the company.
                        // Carrying the last client's identity into a template
                        // is the incident this whole class is written to avoid.
                        'default_party_role'   => $goal->responsibleParty?->party_role,
                        'position'             => $goal->position,
                    ]);

                    $map[$goal->id] = $node->id;

                    $write($goal->id, $node->id, $depth + 1);
                }
            };

            $write(null, null, 1);

            $this->audit->record(
                AuditEventType::PackageCaptured, $circle, ActorType::User, $actor->id,
                'work_package', $package->id, metadata: [
                    'name'  => $name,
                    'nodes' => count($map),
                    'from'  => $circle->name,
                ],
            );

            return $package->fresh();
        });
    }

    /**
     * Write a package into a Circle as goals.
     *
     * Nothing is assigned. `default_party_role` says what kind of company a
     * node is for, and turning that into an actual party is a decision
     * somebody has to make with a name in front of them — which, in this
     * product, means posting it as an opening (§21.1) or naming a party
     * directly. A template that guessed would be assigning work on the
     * strength of a coincidence of role names.
     *
     * @return array{goals: int, openings: int}
     */
    public function instantiate(
        WorkPackage $package,
        Circle $circle,
        User $actor,
        ?\DateTimeInterface $anchor = null,
        ?Goal $under = null,
    ): array {
        $this->gate->authorise($actor, Permission::GoalCreate, $circle, subject: $under);

        abort_unless($this->canRead($package, $actor), 403, 'This package belongs to another company.');
        abort_unless($circle->acceptsContributions(), 422, 'This Circle is not accepting contributions.');

        $anchor ??= $circle->starts_at ?? now();

        if ($under !== null) {
            abort_unless($under->circle_id === $circle->id, 422, 'That work is in another Circle.');
        }

        return DB::transaction(function () use ($package, $circle, $actor, $anchor, $under) {
            $nodes    = $package->nodes()->get()->groupBy('parent_node_id');
            $created  = 0;

            // The absolute cap is GoalService::create()'s, which counts from
            // the root and aborts. Instantiating a four-deep package under an
            // existing node therefore fails loudly rather than silently losing
            // a level — a plan that quietly flattened is worse than one that
            // would not import, because nobody notices until the missing work
            // is somebody's problem.
            $write = function (?string $parentNodeId, ?Goal $parent, int $depth) use (&$write, &$created, $nodes, $circle, $actor, $anchor) {
                if ($depth > self::MAX_DEPTH) {
                    return;
                }

                foreach ($nodes->get($parentNodeId, collect()) as $node) {
                    $dates = $node->datesFrom($anchor);

                    $goal = $this->goals->create(
                        circle: $circle,
                        creator: $actor,
                        title: $node->title,
                        description: $node->description,
                        parent: $parent,
                        owner: null,
                        responsibleParty: null,
                        acceptanceCondition: $node->acceptance_condition,
                        startsAt: $dates['starts_at'],
                        dueAt: $dates['due_at'],
                    );

                    $created++;

                    $write($node->id, $goal, $depth + 1);
                }
            };

            $write(null, $under, 1);

            $this->audit->record(
                AuditEventType::PackageInstantiated, $circle, ActorType::User, $actor->id,
                'work_package', $package->id, metadata: [
                    'name'   => $package->name,
                    'goals'  => $created,
                    'anchor' => $anchor->format(DATE_ATOM),
                    'under'  => $under?->title,
                ],
            );

            return ['goals' => $created, 'openings' => 0];
        });
    }

    /**
     * Copy a package to another company, and record where it came from.
     *
     * The lineage link is the point. "Where did this shape come from" stays
     * answerable three copies later, which is the one thing a template library
     * needs and a folder of documents never has.
     *
     * The source Circle is deliberately *not* carried across: a fork should not
     * tell the forking company which of the original's projects it was captured
     * from.
     */
    public function fork(WorkPackage $package, User $actor, ?string $name = null): WorkPackage
    {
        abort_unless($this->canRead($package, $actor), 403, 'This package is not shared with you.');

        $organisationId = $actor->organisationMemberships()->value('organisation_id');

        abort_if($organisationId === null, 422, 'You do not belong to a company a package could be forked into.');

        return DB::transaction(function () use ($package, $actor, $name, $organisationId) {
            $fork = WorkPackage::create([
                'organisation_id'    => $organisationId,
                'name'               => $name ?? $package->name,
                'summary'            => $package->summary,
                'source_circle_id'   => null,
                'forked_from_id'     => $package->id,
                'version'            => 1,
                'visibility'         => WorkVisibility::Party,
                'created_by_user_id' => $actor->id,
            ]);

            $map = [];

            foreach ($package->nodes()->get() as $node) {
                $copy = WorkPackageNode::create([
                    'work_package_id'      => $fork->id,
                    'parent_node_id'       => $node->parent_node_id === null ? null : ($map[$node->parent_node_id] ?? null),
                    'title'                => $node->title,
                    'description'          => $node->description,
                    'acceptance_condition' => $node->acceptance_condition,
                    'starts_offset_days'   => $node->starts_offset_days,
                    'due_offset_days'      => $node->due_offset_days,
                    'default_party_role'   => $node->default_party_role,
                    'post_as_opening'      => $node->post_as_opening,
                    'position'             => $node->position,
                ]);

                $map[$node->id] = $copy->id;
            }

            // Recorded against the *source* Circle where there is one, so the
            // original owner can see that their shape travelled. A fork nobody
            // can see is indistinguishable from a copy taken in secret.
            $this->audit->record(
                AuditEventType::PackageForked, $package->sourceCircle, ActorType::User, $actor->id,
                'work_package', $fork->id, metadata: [
                    'from'    => $package->id,
                    'name'    => $fork->name,
                    'nodes'   => count($map),
                    'lineage' => $fork->lineageDepth(),
                ],
            );

            return $fork->fresh();
        });
    }

    public function publish(WorkPackage $package, User $actor, WorkVisibility $visibility): WorkPackage
    {
        abort_unless($this->owns($package, $actor), 403, 'This package belongs to another company.');

        $package->forceFill([
            'visibility'   => $visibility,
            'published_at' => $visibility === WorkVisibility::Party ? null : now(),
        ])->save();

        return $package->fresh();
    }

    /**
     * Packages a user may read.
     *
     * @return \Illuminate\Database\Eloquent\Builder<WorkPackage>
     */
    public function readableBy(User $user, DiscoveryService $discovery)
    {
        $mine    = $discovery->organisationIdsFor($user);
        $network = $discovery->networkIdsFor($user);

        return WorkPackage::query()
            ->where(function ($q) use ($mine, $network) {
                $q->whereIn('organisation_id', $mine);
                $q->orWhere(fn ($w) => $w
                    ->where('visibility', WorkVisibility::Public->value)
                    ->whereNotNull('published_at'));
                $q->orWhere(fn ($w) => $w
                    ->where('visibility', WorkVisibility::Network->value)
                    ->whereNotNull('published_at')
                    ->whereIn('organisation_id', $network));
            })
            ->with(['organisation', 'forkedFrom'])
            ->latest('updated_at');
    }

    /**
     * The tree, assembled for rendering.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(WorkPackage $package): array
    {
        $byParent = $package->nodes()->get()->groupBy('parent_node_id');

        $build = function (?string $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())->map(fn (WorkPackageNode $node) => [
                'id'                   => $node->id,
                'title'                => $node->title,
                'description'          => $node->description,
                'acceptance_condition' => $node->acceptance_condition,
                'starts_offset_days'   => $node->starts_offset_days,
                'due_offset_days'      => $node->due_offset_days,
                'default_party_role'   => $node->default_party_role?->value,
                'children'             => $build($node->id),
            ])->values()->all();
        };

        return $build(null);
    }

    // -------------------------------------------------------------- helpers

    private function offset(?\DateTimeInterface $anchor, ?\DateTimeInterface $date): ?int
    {
        if ($anchor === null || $date === null) {
            return null;
        }

        return (int) \Carbon\CarbonImmutable::instance($anchor)
            ->startOfDay()
            ->diffInDays(\Carbon\CarbonImmutable::instance($date)->startOfDay(), false);
    }

    private function organisationFor(Circle $circle, User $actor): string
    {
        $membership = CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $actor->id)
            ->first();

        $organisationId = $membership?->party?->organisation_id
            ?? $circle->organisation_id;

        abort_if($organisationId === null, 422, 'There is no company to capture this package for.');

        return $organisationId;
    }

    private function owns(WorkPackage $package, User $actor): bool
    {
        return $actor->organisationMemberships()
            ->where('organisation_id', $package->organisation_id)
            ->exists();
    }

    private function canRead(WorkPackage $package, User $actor): bool
    {
        if ($this->owns($package, $actor)) {
            return true;
        }

        if (! $package->isPublished()) {
            return false;
        }

        if ($package->visibility === WorkVisibility::Public) {
            return true;
        }

        return $package->visibility === WorkVisibility::Network
            && $actor->organisationMemberships()
                ->whereIn('organisation_id', \App\Models\Organisation::find($package->organisation_id)?->networkIds() ?? [])
                ->exists();
    }
}
