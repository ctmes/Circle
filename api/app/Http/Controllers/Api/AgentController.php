<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\AgentRun;
use App\Models\Circle;
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
    ) {}

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
