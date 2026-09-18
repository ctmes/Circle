<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArtifactType;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\DerivedArtifact;
use App\Services\Authorisation\AccessGate;
use App\Services\Convening\ConveningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Convening a Circle from an engagement of terms (spec §23).
 *
 * Three verbs, and the middle one is the interesting one.
 *
 * `store` runs the Convener over documents already in the vault and, by
 * default, writes the whole plan straight into the Circle. `show` returns the
 * proposal afterwards as a receipt — what was read, what was inferred, what had
 * to be repaired — optionally re-resolved against a different start date, which
 * costs nothing and calls no model because every date is either one the
 * document stated or a period measured from commencement. `accept` is the same
 * write path for a plan somebody edited first.
 *
 * Nothing here creates a Circle. A Circle is created the way it always was, and
 * convening is something done inside one — which keeps evidence, the gate and
 * the chain exactly as they are, with no staging area outside them where a
 * document could sit unaccounted for.
 */
class ConveningController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly ConveningService $convening,
    ) {}

    /**
     * Read the document and build the Circle from it.
     *
     * Applying is the default rather than an option, because the gesture this
     * serves is "here is the contract, give me the Circle". `apply: false` is
     * kept for the case where somebody wants the reading without the writing —
     * a second opinion on a variation, say — and for the review flow, which is
     * the same proposal handed to `accept` instead.
     *
     * Where the caller cannot write, the proposal is still returned and
     * `applied` is false with the permission that stopped it named. Losing a
     * model call because the last of three permissions was missing would be a
     * poor trade for a check that could have been reported.
     */
    public function store(Request $request, Circle $circle): JsonResponse
    {
        $data = $request->validate([
            'evidence_item_ids'   => ['nullable', 'array', 'max:10'],
            'evidence_item_ids.*' => ['string', 'exists:evidence_items,id'],
            'look_for'            => ['nullable', 'array', 'max:10'],
            'look_for.*'          => ['string', 'max:500'],
            'apply'               => ['nullable', 'boolean'],
            // The Steward's opening brief. On by default; off for a caller that
            // only wants the plan, and for tests that would otherwise spend a
            // second model call proving something about the first.
            'brief'               => ['nullable', 'boolean'],
        ]);

        $result = $this->convening->convene(
            circle: $circle,
            actor: $request->user(),
            evidenceItemIds: $data['evidence_item_ids'] ?? [],
            lookFor: $data['look_for'] ?? [],
            apply: (bool) ($data['apply'] ?? true),
            brief: (bool) ($data['brief'] ?? true),
        );

        return response()->json([
            'data' => array_merge($this->present($result['artifact']), [
                'applied'    => $result['applied'],
                'blocked_by' => $result['blocked_by'],
                'created'    => $result['created'],
            ]),
        ], 201);
    }

    /**
     * The most recent proposal for this Circle.
     *
     * `anchor` re-reads it against a different commencement date. This is a
     * re-resolution of stored model output, not a new run: the same proposal,
     * with its offsets measured from somewhere else. A plan that had to be
     * regenerated to move its start date would come back subtly different every
     * time somebody tried a date, which is not a thing a person can review.
     */
    public function show(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $data = $request->validate(['anchor' => ['nullable', 'date']]);

        $artifact = DerivedArtifact::where('circle_id', $circle->id)
            ->where('artifact_type', ArtifactType::ConvenedPlan->value)
            ->latest('created_at')
            ->first();

        if ($artifact === null) {
            return response()->json(['data' => null]);
        }

        $presented = $this->present($artifact);

        if (isset($data['anchor'])) {
            $presented['plan'] = $this->convening->reresolve($artifact, new \DateTimeImmutable($data['anchor']));
        }

        return response()->json(['data' => $presented]);
    }

    /**
     * Write the reviewed plan into the Circle.
     *
     * The body is the plan as the person left it, not the plan as it was
     * proposed. That is deliberate: by this point they have renamed steps,
     * removed the ones the document did not really support and moved the start
     * date, and what they are accepting is their version. The artifact keeps
     * both, so the difference stays legible.
     *
     * Steps are flat and name their parent by key, rather than nested. A
     * review screen lets somebody remove a step with children under it, and a
     * flat list with a missing parent is something the service can put at the
     * root — where a nested payload would have silently taken the children with
     * it.
     */
    public function accept(Request $request, Circle $circle, DerivedArtifact $artifact): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:200'],
            'purpose'    => ['required', 'string', 'max:5000'],
            'starts_at'  => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],

            'parties'                => ['nullable', 'array', 'max:20'],
            'parties.*.key'          => ['nullable', 'string', 'max:40'],
            'parties.*.display_name' => ['required', 'string', 'max:160'],
            'parties.*.party_role'   => ['required', 'string', 'in:principal,contractor,subcontractor,advisor,observer'],

            'steps'                         => ['nullable', 'array', 'max:200'],
            'steps.*.key'                   => ['required', 'string', 'max:40'],
            'steps.*.parent_key'            => ['nullable', 'string', 'max:40'],
            'steps.*.title'                 => ['required', 'string', 'max:255'],
            'steps.*.description'           => ['nullable', 'string', 'max:5000'],
            'steps.*.acceptance_condition'  => ['nullable', 'string', 'max:2000'],
            'steps.*.responsible_party_key' => ['nullable', 'string', 'max:40'],
            'steps.*.starts_on'             => ['nullable', 'date'],
            'steps.*.due_on'                => ['nullable', 'date'],
            'steps.*.clause'                => ['nullable', 'string', 'max:120'],

            'open_questions'                  => ['nullable', 'array', 'max:50'],
            'open_questions.*.question'       => ['required', 'string', 'max:255'],
            'open_questions.*.why_it_matters' => ['nullable', 'string', 'max:2000'],
        ]);

        $counts = $this->convening->accept($circle, $request->user(), $artifact, $data);

        return response()->json(['data' => $counts]);
    }

    /** @return array<string, mixed> */
    private function present(DerivedArtifact $artifact): array
    {
        $content = $artifact->content_json ?? [];

        return [
            'id'         => $artifact->id,
            'status'     => $artifact->status,
            'created_at' => $artifact->created_at?->toISOString(),
            'applied_at' => $content['applied_at'] ?? null,
            'applied_by' => $content['applied_by'] ?? null,
            // Stated on the object rather than left to the front end to phrase.
            // What this is — a machine reading of a document, binding on nobody
            // — has to travel with it wherever it is rendered.
            'label'      => $content['label'] ?? null,
            'agent'      => [
                'name'    => $content['agent_name'] ?? null,
                'version' => $content['blueprint_version'] ?? null,
            ],
            'model'      => ['provider' => $artifact->model_provider, 'name' => $artifact->model_name],
            'prompt_version' => $artifact->prompt_version,
            'agent_run_id'   => $artifact->agent_run_id,
            'sources'        => $content['sources'] ?? [],
            'created'        => $content['created'] ?? null,
            'plan'           => $content['plan'] ?? null,
        ];
    }
}
