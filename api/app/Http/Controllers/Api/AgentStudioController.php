<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActorType;
use App\Enums\AgentExecutionMode;
use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\AgentBlueprint;
use App\Models\AgentConnection;
use App\Models\AgentInstance;
use App\Models\AgentTool;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Organisation;
use App\Services\Agent\AgentActionService;
use App\Services\Agent\AgentConnectionService;
use App\Services\Agent\AgentExecutor;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Authoring agents, admitting other parties' agents, and the action queue
 * (spec §20.4).
 *
 * The controller's job here is mostly refusal. Anyone who can author an agent
 * can describe something dangerous, so the interesting code is the part that
 * declines to let a blueprint grant itself more than its execution mode allows,
 * and declines to let a tool name a weaker approver than its side effect
 * demands.
 */
class AgentStudioController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AgentActionService $actions,
        private readonly AgentExecutor $executor,
        private readonly AgentConnectionService $connections,
        private readonly ToolRegistry $registry,
        private readonly AuditChain $audit,
    ) {}

    // ------------------------------------------------------------ blueprints

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        // What this Circle can actually run: the system agents, the agents of
        // *every* party in it, and anything authored for this Circle.
        //
        // Previously scoped to the convener's organisation, which quietly meant
        // a contractor's own agents were invisible in a Circle they were a
        // party to — so "we use our AI and they use theirs" was true of the
        // schema and false of the screen.
        $blueprints = AgentBlueprint::query()
            ->where(fn ($q) => $q
                ->where('is_system', true)
                ->orWhereIn('organisation_id', $this->partyOrganisationIds($circle))
                ->orWhere('circle_id', $circle->id))
            ->with(['tools', 'instances' => fn ($q) => $q->where('circle_id', $circle->id)])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $blueprints->map(fn (AgentBlueprint $b) => $this->presentBlueprint($b, $circle))->all(),
        ]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentAuthor, $circle);

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:120'],
            'mandate'        => ['required', 'string', 'max:2000'],
            'instructions'   => ['nullable', 'string', 'max:20000'],
            'execution_mode' => ['required', 'string', 'in:read_only,propose,execute'],
            'circle_scoped'  => ['nullable', 'boolean'],
            'allowed_actions' => ['nullable', 'array'],
            'allowed_actions.*' => ['string'],
        ]);

        $mode = AgentExecutionMode::from($data['execution_mode']);

        // Requested permissions are intersected with the mode's ceiling here as
        // well as in the model, so what is stored is already honest. A
        // blueprint that reads back wider than it behaves is a trap for whoever
        // reviews it later.
        $ceiling  = array_map(fn (Permission $p) => $p->value, $mode->ceiling());
        $declared = array_values(array_intersect($data['allowed_actions'] ?? $ceiling, $ceiling));

        // Authored under the author's own organisation, not the convener's. A
        // contractor writing an agent in someone else's Circle owns it, takes
        // it to their next Circle, and carries the consequence of what it does.
        $organisation = $this->authorOrganisation($request, $circle);

        $blueprint = AgentBlueprint::create([
            'key'                => Str::slug($organisation?->slug ?? 'org') . '.' . Str::slug($data['name']) . '.' . Str::lower(Str::random(4)),
            'organisation_id'    => $organisation?->id ?? $circle->organisation_id,
            'circle_id'          => ($data['circle_scoped'] ?? false) ? $circle->id : null,
            'created_by_user_id' => $request->user()->id,
            'is_system'          => false,
            'execution_mode'     => $mode->value,
            'provider'           => 'internal',
            'status'             => 'active',
            'name'               => $data['name'],
            'mandate'            => $data['mandate'],
            'instructions'       => $data['instructions'] ?? null,
            'version'            => '1.0.0',
            'allowed_actions'    => $declared,
            'prohibited_actions' => [
                'external_communication', 'invite_users', 'change_permissions',
                'delete_resources', 'approve_on_behalf_of_a_person',
                'read_outside_its_circle', 'use_raw_user_or_connector_credentials',
            ],
            'prompt_version'     => 'authored-1',
        ]);

        $this->audit->record(
            AuditEventType::AgentBlueprintCreated,
            $circle,
            ActorType::User,
            $request->user()->id,
            'agent_blueprint',
            $blueprint->id,
            metadata: [
                'name'            => $blueprint->name,
                'execution_mode'  => $mode->value,
                'allowed_actions' => $declared,
                'circle_scoped'   => $blueprint->isCircleScoped(),
            ],
        );

        return response()->json(['data' => $this->presentBlueprint($blueprint->load('tools'), $circle)], 201);
    }

    public function update(Request $request, Circle $circle, AgentBlueprint $blueprint): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentAuthor, $circle);

        // System agents are ours. Their mandate is part of the product's
        // guarantees, not a customer setting.
        abort_if($blueprint->is_system, 403, 'System agents cannot be edited.');
        $this->assertOwnsBlueprint($request, $circle, $blueprint);

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:120'],
            'mandate'        => ['sometimes', 'string', 'max:2000'],
            'instructions'   => ['sometimes', 'nullable', 'string', 'max:20000'],
            'execution_mode' => ['sometimes', 'string', 'in:read_only,propose,execute'],
            'status'         => ['sometimes', 'string', 'in:active,suspended'],
        ]);

        $blueprint->fill($data);

        // Narrowing the mode must narrow the stored grants with it, or a later
        // widening would silently restore permissions nobody re-approved.
        if (isset($data['execution_mode'])) {
            $ceiling = array_map(
                fn (Permission $p) => $p->value,
                AgentExecutionMode::from($data['execution_mode'])->ceiling(),
            );
            $blueprint->allowed_actions = array_values(
                array_intersect($blueprint->allowed_actions ?? [], $ceiling),
            );
        }

        $blueprint->save();

        $this->audit->record(
            ($data['status'] ?? null) === 'suspended'
                ? AuditEventType::AgentBlueprintSuspended
                : AuditEventType::AgentBlueprintUpdated,
            $circle,
            ActorType::User,
            $request->user()->id,
            'agent_blueprint',
            $blueprint->id,
            metadata: $data,
        );

        return response()->json(['data' => $this->presentBlueprint($blueprint->load('tools'), $circle)]);
    }

    /** Give an authored agent a command it may run. */
    public function addTool(Request $request, Circle $circle, AgentBlueprint $blueprint): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentAuthor, $circle);
        abort_if($blueprint->is_system, 403, 'System agents cannot be edited.');
        $this->assertOwnsBlueprint($request, $circle, $blueprint);

        $data = $request->validate([
            'key'         => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:1000'],
            'side_effect' => ['required', 'string', 'in:none,circle_write,external_read,external_write,financial'],
            'approval_role' => ['nullable', 'string', 'in:owner,approver,reviewer'],
            'input_schema_json' => ['nullable', 'array'],
        ]);

        $effect = SideEffect::from($data['side_effect']);

        // A tool may only be declared at all if the blueprint could ever run it.
        abort_if(
            $effect !== SideEffect::None && ! ($blueprint->execution_mode?->canExecute() ?? false),
            422,
            'Only an agent in execute mode can declare a tool with side effects.',
        );

        $tool = AgentTool::create([
            'agent_blueprint_id'    => $blueprint->id,
            'key'                   => $data['key'],
            'name'                  => $data['name'],
            'description'           => $data['description'],
            'side_effect'           => $effect->value,
            // Never taken from the request: the side effect decides, and the
            // author can only ever make it stricter.
            'requires_approval'     => $effect->requiresApprovalByDefault(),
            'approval_role'         => $data['approval_role'] ?? null,
            'requires_owning_party' => $effect->requiresOwningParty(),
            'input_schema_json'     => $data['input_schema_json'] ?? null,
            'enabled'               => true,
        ]);

        return response()->json(['data' => $this->presentTool($tool)], 201);
    }

    /** Put an agent to work in this Circle. */
    public function instantiate(Request $request, Circle $circle, AgentBlueprint $blueprint): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentAuthor, $circle);

        abort_if(
            $blueprint->isCircleScoped() && $blueprint->circle_id !== $circle->id,
            422,
            'That agent was authored for a different Circle.',
        );

        $instance = AgentInstance::firstOrCreate(
            ['agent_blueprint_id' => $blueprint->id, 'circle_id' => $circle->id],
            ['status' => 'active'],
        );

        return response()->json(['data' => [
            'id'     => $instance->id,
            'status' => $instance->status,
        ]], 201);
    }

    // ----------------------------------------------------------- connections

    /**
     * Admit an agent supplied by one of the parties.
     *
     * The credential never arrives here. The client stores the secret with the
     * secret store and sends the reference plus a fingerprint, which is what
     * the counterparty is shown when they approve it.
     */
    public function connect(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentConnect, $circle);

        $data = $request->validate([
            'circle_party_id' => ['required', 'string', 'exists:circle_parties,id'],
            // Optional: binds the connection to a mandate already authored in
            // the studio, so admitting it produces an agent that can actually
            // be run rather than a row describing one.
            'agent_blueprint_id' => ['nullable', 'string', 'exists:agent_blueprints,id'],
            'name'            => ['required', 'string', 'max:120'],
            'provider_label'  => ['nullable', 'string', 'max:120'],
            'auth_mode'       => ['required', 'string', 'in:delegated_token,signed_webhook,mcp'],
            'endpoint_url'    => ['nullable', 'url', 'max:2000'],
            'credential_ref'  => ['nullable', 'string', 'max:255'],
            'key_fingerprint' => ['nullable', 'string', 'max:128'],
        ]);

        $party = CircleParty::findOrFail($data['circle_party_id']);
        abort_unless($party->circle_id === $circle->id, 422, 'That party is not in this Circle.');

        if (($blueprintId = $data['agent_blueprint_id'] ?? null) !== null) {
            $blueprint = AgentBlueprint::findOrFail($blueprintId);

            abort_if($blueprint->is_system, 422, 'A system agent is already available; it does not need connecting.');
            abort_unless(
                $blueprint->organisation_id === $party->organisation_id,
                422,
                'That agent belongs to a different organisation from the party bringing it.',
            );
        }

        $connection = AgentConnection::create([
            'circle_id'          => $circle->id,
            'circle_party_id'    => $party->id,
            'agent_blueprint_id' => $blueprintId,
            'name'            => $data['name'],
            'provider_label'  => $data['provider_label'] ?? null,
            'auth_mode'       => $data['auth_mode'],
            'endpoint_url'    => $data['endpoint_url'] ?? null,
            'credential_ref'  => $data['credential_ref'] ?? null,
            'key_fingerprint' => $data['key_fingerprint'] ?? null,
            'status'          => 'pending',
        ]);

        // Proposed, not admitted. The counterparty has not seen this
        // fingerprint yet, and conflating the two would put "we accepted it"
        // in the record at the moment somebody typed it in.
        $this->audit->record(
            AuditEventType::AgentConnectionProposed,
            $circle,
            ActorType::User,
            $request->user()->id,
            'agent_connection',
            $connection->id,
            metadata: [
                'name'        => $connection->name,
                'party'       => $party->label(),
                'auth_mode'   => $connection->auth_mode,
                'fingerprint' => $connection->shortFingerprint(),
                'blueprint'   => $blueprintId,
                'status'      => 'pending',
            ],
        );

        return response()->json(['data' => $this->presentConnection($connection)], 201);
    }

    public function connections(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $connections = AgentConnection::where('circle_id', $circle->id)
            ->with('party.organisation')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $connections->map(fn ($c) => $this->presentConnection($c))->all(),
        ]);
    }

    /**
     * Accept a specific key from a specific party.
     *
     * Owner-only, and in a multi-party Circle it cannot be the agent's own
     * party — the fingerprint exists so a counterparty can accept one key
     * rather than a name anybody could type.
     */
    public function admit(Request $request, AgentConnection $connection): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->presentConnection(
            $this->connections->admit($connection, $request->user(), $data['note'] ?? null),
        )]);
    }

    public function revokeConnection(Request $request, AgentConnection $connection): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->presentConnection(
            $this->connections->revoke($connection, $request->user(), $data['reason'] ?? null),
        )]);
    }

    // --------------------------------------------------------- action queue

    public function queue(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $pending = $this->actions->queue($circle);

        $recent = AgentAction::where('circle_id', $circle->id)
            ->whereNotIn('status', ['awaiting_approval'])
            ->with(['tool', 'agentInstance.blueprint', 'onBehalfOfParty', 'approvedBy'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'data'   => $pending->map(fn ($a) => $this->presentAction($a))->all(),
            'recent' => $recent->map(fn ($a) => $this->presentAction($a))->all(),
        ]);
    }

    /**
     * Approve an action and run it.
     *
     * Approval and execution are one request because the alternative teaches
     * the wrong thing. An approved action sitting in a queue waiting for a
     * second, separate "run" click makes approval feel provisional, and the
     * approval TTL exists precisely so that consent given now is not spent
     * three days from now. If the handler fails, the ledger records the failure
     * against the same row and the response says so.
     *
     * `execute: false` is honoured for the case where a human wants to record
     * their agreement without triggering the effect yet — the scheduled sweep
     * picks it up, or it expires.
     */
    public function approve(Request $request, AgentAction $action): JsonResponse
    {
        $data = $request->validate([
            'note'    => ['nullable', 'string', 'max:2000'],
            'execute' => ['nullable', 'boolean'],
        ]);

        $approved = $this->actions->approve($action, $request->user(), $data['note'] ?? null);

        if ($data['execute'] ?? true) {
            $approved = $this->executor->execute($approved);
        }

        return response()->json(['data' => $this->presentAction($approved->fresh([
            'tool', 'agentInstance.blueprint', 'onBehalfOfParty', 'approvedBy',
        ]))]);
    }

    /**
     * Run an action a human already agreed to.
     *
     * Separate from approve for the approval that was recorded earlier, and for
     * a tool classified `none` that never needed a human at all.
     */
    public function execute(Request $request, AgentAction $action): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentApprove, $action->circle);

        return response()->json(['data' => $this->presentAction(
            $this->executor->execute($action)->fresh([
                'tool', 'agentInstance.blueprint', 'onBehalfOfParty', 'approvedBy',
            ]),
        )]);
    }

    public function reject(Request $request, AgentAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json([
            'data' => $this->presentAction(
                $this->actions->reject($action, $request->user(), $data['reason'] ?? null),
            ),
        ]);
    }

    /**
     * The tools that have an implementation behind them.
     *
     * Exposed so the studio offers a picker rather than a free-text key field.
     * An author typing a name that no handler answers to produces a blueprint
     * that proposes actions which can never run, and the failure surfaces only
     * after an approver has already put their name to one.
     */
    public function catalogue(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registry->catalogue()]);
    }

    // --------------------------------------------------------- party lookups

    /**
     * Every organisation with a seat in this Circle.
     *
     * Includes the convener, which `circle_parties` already holds a row for
     * since the backfill. A party named before its organisation exists on the
     * platform has a null organisation_id and simply contributes nothing here —
     * it has no agents yet because it has no account yet.
     *
     * @return list<string>
     */
    private function partyOrganisationIds(Circle $circle): array
    {
        return CircleParty::where('circle_id', $circle->id)
            ->whereNotNull('organisation_id')
            ->pluck('organisation_id')
            ->push($circle->organisation_id)
            ->unique()
            ->values()
            ->all();
    }

    /** The party the acting user sits in, if the Circle uses parties. */
    private function actingParty(Request $request, Circle $circle): ?CircleParty
    {
        return CircleMembership::where('circle_id', $circle->id)
            ->where('user_id', $request->user()->id)
            ->first()?->party;
    }

    /**
     * Whose agent this is, from the author's seat rather than from the Circle.
     *
     * Falls back to the convener for a Circle with no parties, which is the
     * single-organisation shape the MVP was written around and still works.
     */
    private function authorOrganisation(Request $request, Circle $circle): ?Organisation
    {
        return $this->actingParty($request, $circle)?->organisation
            ?? $circle->organisation;
    }

    /**
     * An agent may only be edited by the organisation that authored it.
     *
     * Not by the convener, which is the point. In a Circle the client opened,
     * the client cannot quietly widen the contractor's agent's mandate and
     * leave the contractor carrying what it does — they can refuse to admit it,
     * or drop it from the Circle, but not rewrite it.
     */
    private function assertOwnsBlueprint(Request $request, Circle $circle, AgentBlueprint $blueprint): void
    {
        $organisation = $this->authorOrganisation($request, $circle);

        abort_unless(
            $organisation !== null && $blueprint->organisation_id === $organisation->id,
            403,
            'That agent belongs to another organisation. Only its author can change it.',
        );
    }

    // ------------------------------------------------------------ presenters

    private function presentBlueprint(AgentBlueprint $b, Circle $circle): array
    {
        $instance = $b->relationLoaded('instances') ? $b->instances->first() : null;

        return [
            'id'              => $b->id,
            'key'             => $b->key,
            'name'            => $b->name,
            'mandate'         => $b->mandate,
            'instructions'    => $b->instructions,
            'is_system'       => (bool) $b->is_system,
            'status'          => $b->status,
            'execution_mode'  => $b->execution_mode?->value,
            'provider'        => $b->provider,
            'circle_scoped'   => $b->isCircleScoped(),
            // What it can actually do, after the mode's ceiling is applied.
            'permissions'     => array_map(fn (Permission $p) => $p->value, $b->effectivePermissions()),
            'declared'        => $b->allowed_actions ?? [],
            'prohibited'      => $b->prohibited_actions ?? [],
            'tools'           => $b->relationLoaded('tools')
                ? $b->tools->map(fn (AgentTool $t) => $this->presentTool($t))->all()
                : [],
            'instance_id'     => $instance?->id,
            'is_running_here' => $instance !== null,
            'created_at'      => $b->created_at?->toISOString(),
        ];
    }

    private function presentTool(AgentTool $t): array
    {
        return [
            'id'                => $t->id,
            'key'               => $t->key,
            'name'              => $t->name,
            'description'       => $t->description,
            'side_effect'       => $t->side_effect->value,
            'needs_approval'    => $t->needsApproval(),
            'approval_role'     => $t->effectiveApprovalRole()?->value,
            'owning_party_only' => $t->needsOwningPartyApprover(),
            'enabled'           => (bool) $t->enabled,
            // A blueprint can name a tool nobody implemented. Saying so in the
            // studio is better than letting the author discover it from a
            // failed action after an approver has already signed for it.
            'implemented'       => $this->registry->isImplemented($t),
        ];
    }

    private function presentConnection(AgentConnection $c): array
    {
        return [
            'id'          => $c->id,
            'name'        => $c->name,
            'party'       => $c->party?->label(),
            'provider'    => $c->provider_label,
            'auth_mode'   => $c->auth_mode,
            'status'      => $c->status,
            'fingerprint' => $c->shortFingerprint(),
            'is_admitted' => $c->isAdmitted(),
            'blueprint_id' => $c->agent_blueprint_id,
            'admitted_by' => $c->admittedBy?->name,
            'admitted_at' => $c->admitted_at?->toISOString(),
            'revoked_at'  => $c->revoked_at?->toISOString(),
            'created_at'  => $c->created_at?->toISOString(),

            /*
             * Admission is a governance record, not a live integration.
             *
             * `endpoint_url` is stored and nothing in this application has ever
             * called it — there is no outbound request to a third-party agent
             * anywhere in the codebase. An admitted connection means a named
             * owner accepted a named key on the record; it does not mean
             * software is out there acting on that Circle.
             *
             * Stated in the payload rather than left to a comment, because the
             * distinction disappears the moment a UI renders "Admitted" next to
             * a green dot, and the people it disappears for are the ones who
             * signed the decision that admitted it.
             */
            'can_be_invoked'  => false,
            'invocation_note' => 'Admitted for the record. Circle does not call this agent; '
                . 'its endpoint is stored but never contacted.',
        ];
    }

    private function presentAction(AgentAction $a): array
    {
        return [
            'id'            => $a->id,
            'agent'         => $a->agentInstance?->blueprint?->name,
            'tool_key'      => $a->tool_key,
            'tool_name'     => $a->tool?->name ?? $a->tool_key,
            'side_effect'   => $a->side_effect->value,
            'status'        => $a->status->value,
            'intent'        => $a->intent,
            'arguments'     => $a->arguments_json,
            'result'        => $a->result_json,
            'error'         => $a->error,
            'on_behalf_of'  => $a->onBehalfOfParty?->label(),
            'needs_role'    => $a->tool?->effectiveApprovalRole()?->value,
            'owning_party_only' => $a->tool?->needsOwningPartyApprover() ?? false,
            'approved_by'   => $a->approvedBy?->name,
            'approved_at'   => $a->approved_at?->toISOString(),
            'rejection_reason' => $a->rejection_reason,
            'expires_at'    => $a->expires_at?->toISOString(),
            'is_expired'    => $a->isExpired(),
            'runnable'      => $this->executor->canRun($a->tool_key),
            'executed_at'   => $a->executed_at?->toISOString(),
            'created_at'    => $a->created_at?->toISOString(),
        ];
    }
}
