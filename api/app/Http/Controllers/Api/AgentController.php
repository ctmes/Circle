<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\AgentRun;
use App\Models\Circle;
use App\Models\AgentBlueprint;
use App\Models\AgentInstance;
use App\Services\Agent\AgentRunner;
use App\Services\Agent\AuthoredPrompt;
use App\Services\Agent\CircleSteward;
use App\Services\Agent\StewardPrompt;
use App\Services\Authorisation\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AgentController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly CircleSteward $steward,
        private readonly AgentRunner $runner,
    ) {}

    /**
     * Run an authored agent.
     *
     * Until this existed there was exactly one way to run anything — the
     * Steward — so the studio could author a blueprint, declare its tools and
     * instantiate it, and then offer no way to invoke it. An authored agent was
     * a document about an agent.
     *
     * The blueprint arrives as a route parameter and the prompt is assembled
     * from it here, rather than being stored anywhere the model could reach.
     * Everything else — the gate checks, the retrieval manifest, the citation
     * validator, the ledger — is the same code path the Steward takes, which is
     * the only reason it is safe to let customers point it at their own text.
     */
    public function run(Request $request, Circle $circle, AgentBlueprint $blueprint): JsonResponse
    {
        $data = $request->validate([
            'open_questions'   => ['nullable', 'array'],
            'open_questions.*' => ['string', 'max:500'],
        ]);

        abort_if(
            $blueprint->isCircleScoped() && $blueprint->circle_id !== $circle->id,
            422,
            'That agent was authored for a different Circle.',
        );

        // A blueprint belonging to no party here has no business running here,
        // even if somebody knows its id.
        abort_unless(
            $blueprint->is_system
                || $blueprint->circle_id === $circle->id
                || $this->blueprintBelongsToAParty($circle, $blueprint),
            403,
            'That agent belongs to an organisation with no seat in this Circle.',
        );

        $instance = AgentInstance::firstOrCreate(
            ['agent_blueprint_id' => $blueprint->id, 'circle_id' => $circle->id],
            ['status' => 'active'],
        );

        // Relations the runner and the gate both read; loading them here keeps
        // the hot path out of lazy loads inside a transaction.
        $instance->setRelation('blueprint', $blueprint);
        $instance->setRelation('circle', $circle);

        try {
            $run = $this->runner->run(
                agent: $instance,
                triggeredBy: $request->user(),
                prompt: new AuthoredPrompt($blueprint, $blueprint->tools()->get()->all()),
                openQuestions: $data['open_questions'] ?? [],
                runType: 'authored_run',
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data'    => ['status' => 'failed'],
            ], 502);
        }

        return response()->json([
            'data' => $this->present($run->load('resourceAccesses', 'instance.blueprint')),
        ], 201);
    }

    private function blueprintBelongsToAParty(Circle $circle, AgentBlueprint $blueprint): bool
    {
        return $blueprint->organisation_id !== null
            && (
                $blueprint->organisation_id === $circle->organisation_id
                || $circle->parties()
                    ->where('organisation_id', $blueprint->organisation_id)
                    ->exists()
            );
    }

    /** Runs the Steward and returns the sourced brief it produced. */
    public function stewardBrief(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'open_questions'   => ['nullable', 'array'],
            'open_questions.*' => ['string', 'max:500'],
        ]);

        try {
            $run = $this->steward->runBrief($circle, $request->user(), $data['open_questions'] ?? []);
        } catch (HttpException $e) {
            // A policy refusal must surface as the 403 the gate decided on.
            // Symfony's HttpException extends RuntimeException, so it would
            // otherwise be swallowed by the provider-failure branch below.
            throw $e;
        } catch (\RuntimeException $e) {
            // A failed run is still a recorded run; return its id so the caller
            // can inspect what was retrieved before the failure.
            return response()->json([
                'message' => $e->getMessage(),
                'data'    => ['status' => 'failed'],
            ], 502);
        }

        return response()->json(['data' => $this->present($run->load('resourceAccesses', 'instance.blueprint'))], 201);
    }

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $runs = AgentRun::where('circle_id', $circle->id)
            ->with(['resourceAccesses', 'instance.blueprint'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $runs->map(fn ($r) => $this->present($r))->all()]);
    }

    public function show(Request $request, AgentRun $agentRun): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $agentRun->circle);

        return response()->json([
            'data' => $this->present($agentRun->load('resourceAccesses.resource', 'instance.blueprint', 'artifacts')),
        ]);
    }

    /**
     * Every field of the agent output contract from spec §9 travels with the
     * run, so a client cannot render a brief without its provenance.
     */
    private function present(AgentRun $run): array
    {
        return [
            'id'                => $run->id,
            'run_type'          => $run->run_type,
            'status'            => $run->status,
            'output_type'       => 'steward_brief',
            'agent_instance_id' => $run->agent_instance_id,
            'blueprint'         => $run->instance?->blueprint?->key,
            'blueprint_version' => $run->instance?->blueprint?->version,
            'model_provider'    => $run->model_provider,
            'model_name'        => $run->model_name,
            'prompt_version'    => $run->prompt_version,
            'created_at'        => $run->created_at?->toISOString(),
            'started_at'        => $run->started_at?->toISOString(),
            'finished_at'       => $run->finished_at?->toISOString(),
            'tokens'            => ['input' => $run->input_tokens, 'output' => $run->output_tokens],
            'triggered_by'      => $run->triggered_by_user_id,
            'error'             => $run->error,
            'label'             => StewardPrompt::DERIVED_LABEL,
            'derived'           => true,
            'output'            => $run->output_json,
            'retrieval_manifest' => $run->retrieval_manifest_json,
            // What the agent actually touched, including refusals.
            'resource_accesses' => $run->relationLoaded('resourceAccesses')
                ? $run->resourceAccesses->map(fn ($a) => [
                    'resource_id'         => $a->resource_id,
                    'evidence_version_id' => $a->evidence_version_id,
                    'permitted'           => $a->permitted,
                    'reason'              => $a->reason,
                    'occurred_at'         => $a->occurred_at?->toISOString(),
                ])->all()
                : [],
        ];
    }
}
