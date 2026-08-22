<?php

namespace App\Http\Controllers\Api;

use App\Enums\ClaimType;
use App\Enums\Permission;
use App\Models\Circle;
use App\Models\Claim;
use App\Services\Authorisation\AccessGate;
use App\Services\Claims\ClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ClaimController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly ClaimService $claims,
    ) {}

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $claims = Claim::where('circle_id', $circle->id)
            ->with(['citations.evidenceVersion.evidenceItem.resource', 'author', 'reviews.reviewer'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('author_type'), fn ($q, $v) => $q->where('author_type', $v))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $claims->map(fn ($c) => $this->present($c))->all()]);
    }

    public function store(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ClaimCreate, $circle);

        $data = $request->validate([
            'statement'                       => ['required', 'string', 'max:5000'],
            'claim_type'                      => ['required', 'string', 'in:factual,technical_assessment,commercial_assessment,risk,recommendation'],
            'confidence'                      => ['nullable', 'numeric', 'min:0', 'max:1'],
            'citations'                       => ['nullable', 'array'],
            'citations.*.evidence_version_id' => ['required', 'string', 'exists:evidence_versions,id'],
            'citations.*.citation_type'       => ['nullable', 'string'],
            'citations.*.locator'             => ['nullable', 'array'],
            'citations.*.excerpt'             => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $claim = $this->claims->create(
                circle: $circle,
                author: $request->user(),
                statement: $data['statement'],
                type: ClaimType::from($data['claim_type']),
                citations: $data['citations'] ?? [],
                confidence: $data['confidence'] ?? null,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($claim->load('citations', 'author'))], 201);
    }

    public function show(Request $request, Claim $claim): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $claim->circle);

        return response()->json([
            'data' => $this->present($claim->load('citations.evidenceVersion.evidenceItem.resource', 'author', 'reviews.reviewer')),
        ]);
    }

    public function addCitation(Request $request, Claim $claim): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ClaimCreate, $claim->circle);

        $data = $request->validate([
            'evidence_version_id' => ['required', 'string', 'exists:evidence_versions,id'],
            'citation_type'       => ['nullable', 'string'],
            'locator'             => ['nullable', 'array'],
            'excerpt'             => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $citation = $this->claims->addCitation($claim, $data);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id'                  => $citation->id,
                'evidence_version_id' => $citation->evidence_version_id,
                'citation_type'       => $citation->citation_type->value,
                'locator'             => $citation->locator_json,
            ],
        ], 201);
    }

    public function review(Request $request, Claim $claim): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ClaimReview, $claim->circle);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:reviewed,changes_requested,contested,rejected'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $claim = $this->claims->review($claim, $request->user(), $data['outcome'], $data['comment'] ?? null);

        return response()->json(['data' => $this->present($claim->fresh()->load('citations', 'reviews.reviewer', 'author'))]);
    }

    private function present(Claim $claim): array
    {
        return [
            'id'          => $claim->id,
            'statement'   => $claim->statement,
            'claim_type'  => $claim->claim_type->value,
            'status'      => $claim->status->value,
            'confidence'  => $claim->confidence,
            'author_type' => $claim->author_type,
            'author'      => $claim->isAgentAuthored()
                ? ['agent_instance_id' => $claim->author_id, 'agent_run_id' => $claim->agent_run_id]
                : ['user_id' => $claim->author_id, 'name' => $claim->author?->name],
            // Agent-authored claims always carry the derived label so no client
            // can present one as an established fact (spec §9).
            'derived'     => $claim->isAgentAuthored(),
            'derived_label' => $claim->isAgentAuthored()
                ? \App\Services\Agent\StewardPrompt::DERIVED_LABEL
                : null,
            'created_at'  => $claim->created_at?->toISOString(),
            'citations'   => $claim->relationLoaded('citations')
                ? $claim->citations->map(fn ($c) => [
                    'id'                  => $c->id,
                    'evidence_version_id' => $c->evidence_version_id,
                    'citation_type'       => $c->citation_type->value,
                    'locator'             => $c->locator_json,
                    'excerpt'             => $c->excerpt,
                    'evidence'            => $c->relationLoaded('evidenceVersion') && $c->evidenceVersion ? [
                        'evidence_item_id' => $c->evidenceVersion->evidence_item_id,
                        'name'             => $c->evidenceVersion->evidenceItem?->resource?->name,
                        'filename'         => $c->evidenceVersion->original_filename,
                        'version_number'   => $c->evidenceVersion->version_number,
                        'integrity_status' => $c->evidenceVersion->integrityStatus()->value,
                    ] : null,
                ])->all()
                : [],
            'reviews'     => $claim->relationLoaded('reviews')
                ? $claim->reviews->map(fn ($r) => [
                    'reviewer' => $r->reviewer?->name,
                    'outcome'  => $r->outcome,
                    'comment'  => $r->comment,
                    'at'       => $r->created_at?->toISOString(),
                ])->all()
                : [],
        ];
    }
}
