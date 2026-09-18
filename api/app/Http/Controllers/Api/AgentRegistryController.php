<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\AgentBlueprint;
use App\Models\AgentBlueprintVersion;
use App\Models\Circle;
use App\Services\Agent\BlueprintRegistry;
use App\Services\Authorisation\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Agents somebody else can hire (spec §21.6).
 *
 * A separate controller from the studio on purpose. The studio is where a
 * company authors its own agents and admits other people's; this is the
 * register of frozen mandates, which is addressable without a Circle and
 * therefore has a different boundary — the same one openings and records have,
 * and the third and last place in the product that crosses §15's line.
 */
class AgentRegistryController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly BlueprintRegistry $registry,
    ) {}

    /** Mandates this person may hire. */
    public function index(Request $request): JsonResponse
    {
        $versions = $this->registry->listedFor($request->user())->limit(100)->get();

        return response()->json([
            'data' => $versions->map(fn (AgentBlueprintVersion $v) => $this->registry->prospectus($v))->all(),
        ]);
    }

    public function show(Request $request, AgentBlueprintVersion $version): JsonResponse
    {
        $visible = $this->registry->listedFor($request->user())
            ->where('agent_blueprint_versions.agent_blueprint_id', $version->agent_blueprint_id)
            ->exists();

        abort_unless($visible, 404, 'No such agent.');

        return response()->json(['data' => $this->registry->prospectus($version)]);
    }

    /** Every version of one blueprint, for its author. */
    public function versions(Request $request, Circle $circle, AgentBlueprint $blueprint): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentAuthor, $circle);

        abort_unless(
            $request->user()->organisationMemberships()
                ->where('organisation_id', $blueprint->organisation_id)->exists(),
            403,
            'Only the authoring company sees an agent\'s release history.',
        );

        return response()->json([
            'data' => $blueprint->versions()->get()
                ->map(fn (AgentBlueprintVersion $v) => $this->registry->prospectus($v))->all(),
        ]);
    }

    /**
     * Freeze the blueprint as it stands, and say who may hire it.
     *
     * Gated on `agent.author` rather than a new permission: publishing a
     * version is an authoring act, and the thing that keeps a hirer safe is
     * not who pressed the button but that the version cannot change afterwards.
     */
    public function publish(Request $request, Circle $circle, AgentBlueprint $blueprint): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentAuthor, $circle);

        $data = $request->validate([
            'listing_status' => ['nullable', 'string', 'in:private,network,public'],
            'release_note'   => ['nullable', 'string', 'max:2000'],
        ]);

        $version = $this->registry->publishVersion(
            blueprint: $blueprint,
            actor: $request->user(),
            listingStatus: $data['listing_status'] ?? 'private',
            releaseNote: $data['release_note'] ?? null,
        );

        return response()->json(['data' => $this->registry->prospectus($version)], 201);
    }
}
