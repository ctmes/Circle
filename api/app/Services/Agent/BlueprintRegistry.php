<?php

namespace App\Services\Agent;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AgentBlueprint;
use App\Models\AgentBlueprintVersion;
use App\Models\AgentTool;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Work\DiscoveryService;
use Illuminate\Support\Facades\DB;

/**
 * Publishing a mandate somebody else can hire (spec §21.6).
 *
 * §20.6 established that only a blueprint's author may edit it: the client can
 * refuse a contractor's agent or throw it out, but cannot quietly widen its
 * mandate and leave the contractor carrying what it does.
 *
 * Hiring inverts the risk. Once a counterparty is *paying* for an agent, the
 * author editing it silently is worth money — the hirer approved a mandate, a
 * tool list and an execution mode, and every one of those can be changed
 * underneath them by the party being paid.
 *
 * A version is the answer: an immutable snapshot, hashed, that an engagement
 * names instead of the blueprint. The blueprint stays the living thing its
 * author edits.
 */
class BlueprintRegistry
{
    public function __construct(
        private readonly AuditChain $audit,
        private readonly DiscoveryService $discovery,
    ) {}

    /**
     * Freeze the blueprint as it stands.
     *
     * The tools are copied rather than referenced, and that is the half people
     * forget: a tool added after the hire is exactly the same problem as a
     * mandate widened after it. §20.4 classifies tools by consequence, so the
     * frozen list is what the hirer's approval bar was set from.
     */
    public function publishVersion(
        AgentBlueprint $blueprint,
        User $actor,
        string $listingStatus = 'private',
        ?string $releaseNote = null,
    ): AgentBlueprintVersion {
        abort_if($blueprint->is_system, 422, 'The Circle Steward ships with the product and is not for hire.');

        abort_unless(
            $actor->organisationMemberships()->where('organisation_id', $blueprint->organisation_id)->exists(),
            403,
            'An agent is published by the company that authored it.',
        );

        abort_unless(
            in_array($listingStatus, ['private', 'network', 'public'], true),
            422,
            'A listing is private, network or public.',
        );

        // A Circle-scoped blueprint cannot be offered anywhere: it was authored
        // for one Circle and AccessGate refuses to instance it in another
        // (§20.4). Listing it would advertise something unhirable.
        abort_if(
            $blueprint->isCircleScoped() && $listingStatus !== 'private',
            422,
            'This agent was authored for one Circle, so it cannot be offered outside it.',
        );

        $tools = AgentTool::where('agent_blueprint_id', $blueprint->id)
            ->orderBy('key')
            ->get()
            ->map(fn (AgentTool $tool) => [
                'key'              => $tool->key,
                'name'             => $tool->name,
                'description'      => $tool->description,
                'side_effect'      => $tool->side_effect instanceof \BackedEnum ? $tool->side_effect->value : $tool->side_effect,
                'approval_role'    => $tool->approval_role instanceof \BackedEnum ? $tool->approval_role->value : $tool->approval_role,
                'requires_approval' => (bool) $tool->requires_approval,
                'enabled'          => (bool) $tool->enabled,
            ])
            ->values()
            ->all();

        $snapshot = [
            'name'               => $blueprint->name,
            'mandate'            => $blueprint->mandate,
            'instructions'       => $blueprint->instructions,
            'execution_mode'     => $blueprint->execution_mode->value,
            'prompt_version'     => $blueprint->prompt_version,
            'allowed_actions'    => $blueprint->allowed_actions,
            'prohibited_actions' => $blueprint->prohibited_actions,
            'tools_json'         => $tools,
        ];

        return DB::transaction(function () use ($blueprint, $actor, $listingStatus, $releaseNote, $snapshot, $tools) {
            $next = (int) AgentBlueprintVersion::where('agent_blueprint_id', $blueprint->id)
                ->max('version_number') + 1;

            // An identical republish is refused rather than silently making a
            // second version. Two versions with the same hash would give a
            // hirer two things to choose between that are the same thing, and
            // "which one did we agree to" would stop having an answer.
            $hash     = AgentBlueprintVersion::hashOf($snapshot);
            $previous = AgentBlueprintVersion::where('agent_blueprint_id', $blueprint->id)
                ->where('content_hash', $hash)
                ->first();

            if ($previous !== null) {
                // The listing is a property of the offer rather than of the
                // mandate, so changing only that updates in place.
                if ($previous->listing_status !== $listingStatus) {
                    $previous->forceFill(['listing_status' => $listingStatus])->save();
                }

                return $previous->fresh();
            }

            $version = AgentBlueprintVersion::create(array_merge($snapshot, [
                'agent_blueprint_id'   => $blueprint->id,
                'organisation_id'      => $blueprint->organisation_id,
                'version_number'       => $next,
                'content_hash'         => $hash,
                'listing_status'       => $listingStatus,
                'release_note'         => $releaseNote,
                'published_by_user_id' => $actor->id,
                'published_at'         => now(),
            ]));

            $this->audit->record(
                AuditEventType::BlueprintVersionPublished,
                $blueprint->circle_id === null ? null : $blueprint->circle,
                ActorType::User, $actor->id,
                'agent_blueprint_version', $version->id, metadata: [
                    'blueprint'      => $blueprint->key,
                    'version'        => $next,
                    'hash'           => $hash,
                    'listing'        => $listingStatus,
                    'execution_mode' => $blueprint->execution_mode->value,
                    'tools'          => count($tools),
                    'highest_effect' => $version->highestSideEffect(),
                ],
            );

            return $version;
        });
    }

    /**
     * Versions this user may hire.
     *
     * Same visibility ladder as an opening (§21.5) and for the same reason:
     * a public register of every agent anybody has written is a directory of
     * attack surface, and the companies worth hiring from are the ones you
     * have already worked with.
     *
     * @return \Illuminate\Database\Eloquent\Builder<AgentBlueprintVersion>
     */
    public function listedFor(User $user)
    {
        $mine    = $this->discovery->organisationIdsFor($user);
        $network = $this->discovery->networkIdsFor($user);

        return AgentBlueprintVersion::query()
            ->where(function ($q) use ($mine, $network) {
                $q->whereIn('organisation_id', $mine);
                $q->orWhere('listing_status', 'public');
                $q->orWhere(fn ($w) => $w
                    ->where('listing_status', 'network')
                    ->whereIn('organisation_id', $network));
            })
            // One row per blueprint: the newest listed version. A register
            // showing every version anybody ever published is a changelog, and
            // nobody hires from a changelog.
            ->whereIn('id', function ($q) {
                $q->selectRaw('max(id)')
                    ->from('agent_blueprint_versions')
                    ->groupBy('agent_blueprint_id');
            })
            ->with(['blueprint', 'organisation'])
            ->orderByDesc('published_at');
    }

    /**
     * What a prospective hirer is shown before they agree.
     *
     * The hash is included deliberately. "Is this the same mandate we approved
     * in March" should be answerable mechanically rather than by reading two
     * pages of JSON, and the whole point of freezing a version is lost if the
     * only way to compare two is by eye.
     *
     * @return array<string, mixed>
     */
    public function prospectus(AgentBlueprintVersion $version): array
    {
        return [
            'id'               => $version->id,
            'name'             => $version->name,
            'version'          => $version->version_number,
            'author'           => $version->organisation?->name,
            'mandate'          => $version->mandate,
            'execution_mode'   => $version->execution_mode->value,
            'release_note'     => $version->release_note,
            'content_hash'     => $version->content_hash,
            'published_at'     => $version->published_at?->toIso8601String(),
            'listing_status'   => $version->listing_status,
            'highest_effect'   => $version->highestSideEffect(),
            // Named individually rather than counted. A hirer approving an
            // agent is approving these, and a number tells them nothing about
            // which ones.
            'tools'            => array_map(fn (array $tool) => [
                'key'         => $tool['key'] ?? null,
                'name'        => $tool['name'] ?? null,
                'side_effect' => $tool['side_effect'] ?? 'none',
            ], $version->tools()),
            // Stated rather than implied. A hirer who believes they are buying
            // software somebody else operates has bought the wrong thing
            // (spec §21.7).
            'runs_where'       => 'Circle, under the authoring company\'s authority',
        ];
    }
}
