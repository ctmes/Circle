<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\Circle;
use App\Services\Authorisation\AccessGate;
use App\Services\Search\EvidenceSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Search inside a Circle's evidence (spec §12).
 *
 * Scoped to one Circle by construction — there is no cross-Circle search, for
 * the same reason there is no cross-Circle listing route.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly EvidenceSearch $search,
    ) {}

    public function evidence(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $data = $request->validate([
            'q'     => ['required', 'string', 'max:200'],
            'lane'  => ['nullable', 'string', 'in:document,spreadsheet,image,video,audio,url,other'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $hits = $this->search->search(
            circle: $circle,
            actor: $request->user(),
            query: $data['q'],
            lane: $data['lane'] ?? null,
            limit: (int) ($data['limit'] ?? 20),
        );

        return response()->json([
            'data' => $hits,
            'meta' => [
                'query' => trim($data['q']),
                'count' => count($hits),
                // The snippet arrives with the matched words wrapped, so the
                // client can mark them without being handed markup to render.
                'highlight' => [
                    'start' => EvidenceSearch::HIGHLIGHT_START,
                    'stop'  => EvidenceSearch::HIGHLIGHT_STOP,
                ],
            ],
        ]);
    }
}
