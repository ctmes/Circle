<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Middleware\ConfineScopedTokens;
use App\Models\Circle;
use App\Models\Organisation;
use App\Models\TranscriptImport;
use App\Services\Authorisation\AccessGate;
use App\Services\Transcripts\TranscriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Meeting transcripts in, and the log of what each one did (spec §24).
 *
 * `store` is the webhook. It is written for the thing on the other end, which
 * is usually an automation — Zapier, Make, a note-taker's own webhook — rather
 * than a person: it takes a transcript under either of the two field names
 * those tools most often produce, it answers 202 with an import id as soon as
 * the transcript is recorded, and a resend of the same meeting returns the
 * original import rather than a second one.
 *
 * The log (`index`, `show`) is readable by members of the company. It never
 * returns the transcript's text — once filed, that lives in the Circle's vault
 * behind the gate, and a company-wide log that repeated it would be a way round
 * every party scope a Circle has.
 */
class TranscriptController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly TranscriptService $transcripts,
    ) {}

    /** Receive a transcript for a company. The route the connector calls. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, withOrganisation: true);

        $organisation = $this->organisationFor($request, $data['organisation_id'] ?? null);

        $import = $this->transcripts->receive($organisation, $request->user(), $data);
        $status = $import->wasRecentlyCreated ? 202 : 200;

        // A connector token is write-only (see ConfineScopedTokens). A resend
        // of a meeting that has already been applied must not become a way for
        // whoever holds the token to read what that meeting changed.
        if (ConfineScopedTokens::isConnectorToken($request->user()->currentAccessToken())) {
            return response()->json(['data' => ['id' => $import->id, 'status' => $import->status]], $status);
        }

        return response()->json(['data' => $this->present($import)], $status);
    }

    /** Receive a transcript for one Circle — routing is skipped. */
    public function storeForCircle(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::AgentRun, $circle);

        $data = $this->validated($request, withOrganisation: false);
        $data['circle_id'] = $circle->id;

        $import = $this->transcripts->receive($circle->organisation, $request->user(), $data);

        return response()->json(['data' => $this->present($import)], $import->wasRecentlyCreated ? 202 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organisation_id' => ['nullable', 'string'],
            'circle_id'       => ['nullable', 'string'],
        ]);

        $organisations = $request->user()->organisationMemberships()->pluck('organisation_id');

        $imports = TranscriptImport::query()
            ->whereIn('organisation_id', $organisations)
            ->when($data['organisation_id'] ?? null, fn ($q, $id) => $q->where('organisation_id', $id))
            ->when($data['circle_id'] ?? null, fn ($q, $id) => $q->where('circle_id', $id))
            ->with(['circle:id,name', 'submittedBy:id,name'])
            ->latest('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $imports->map(fn ($i) => $this->present($i))->all()]);
    }

    public function show(Request $request, TranscriptImport $import): JsonResponse
    {
        $this->assertMember($request, $import);

        return response()->json(['data' => $this->present($import->load(['circle:id,name', 'submittedBy:id,name']), detail: true)]);
    }

    public function retry(Request $request, TranscriptImport $import): JsonResponse
    {
        $this->assertMember($request, $import);

        return response()->json(['data' => $this->present($this->transcripts->retry($import))], 202);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withOrganisation): array
    {
        // `transcript` is what most note-taker automations call the field;
        // `text` is what a person writing a script reaches for. Both are fine.
        if (! $request->filled('text') && $request->filled('transcript')) {
            $request->merge(['text' => $request->input('transcript')]);
        }

        $rules = [
            'text'           => ['required', 'string', 'max:1000000'],
            'title'          => ['nullable', 'string', 'max:300'],
            'occurred_at'    => ['nullable', 'date'],
            'participants'   => ['nullable', 'array', 'max:100'],
            'participants.*' => ['string', 'max:200'],
            'source'         => ['nullable', 'string', 'max:60'],
            'external_id'    => ['nullable', 'string', 'max:200'],
            'circle_id'      => ['nullable', 'string'],
        ];

        if ($withOrganisation) {
            $rules['organisation_id'] = ['nullable', 'string'];
        }

        return $request->validate($rules);
    }

    /**
     * Which company a transcript is for.
     *
     * A connector token is issued for one company and says so in its
     * abilities, and that wins over anything in the body — a leaked token
     * cannot be pointed at a different company by changing a field. A person's
     * own session names the company, or has only one to choose from.
     */
    private function organisationFor(Request $request, ?string $requested): Organisation
    {
        $user  = $request->user();
        $token = $user->currentAccessToken();

        $bound = null;

        if (ConfineScopedTokens::isConnectorToken($token)) {
            foreach ((array) ($token->abilities ?? []) as $ability) {
                if (str_starts_with((string) $ability, 'org:')) {
                    $bound = substr((string) $ability, 4);
                }
            }

            abort_if($bound === null, 403, 'This connector token is not bound to a company.');
            abort_if($requested !== null && $requested !== $bound, 403, 'This connector token belongs to a different company.');
        }

        $id = $bound ?? $requested;

        if ($id === null) {
            $memberships = $user->organisationMemberships()->pluck('organisation_id');

            abort_unless($memberships->count() === 1, 422, 'Say which company this transcript is for (organisation_id).');

            $id = $memberships->first();
        }

        return Organisation::findOrFail($id);
    }

    private function assertMember(Request $request, TranscriptImport $import): void
    {
        abort_unless(
            $request->user()->organisationMemberships()->where('organisation_id', $import->organisation_id)->exists(),
            404,
        );
    }

    /** @return array<string, mixed> */
    private function present(TranscriptImport $import, bool $detail = false): array
    {
        $out = [
            'id'             => $import->id,
            'organisation_id' => $import->organisation_id,
            'status'         => $import->status,
            'source'         => $import->source,
            'external_id'    => $import->external_id,
            'title'          => $import->title,
            'occurred_at'    => $import->occurred_at?->toISOString(),
            'participants'   => $import->participants_json,
            'chars'          => $import->content_chars,
            'circle'         => $import->circle_id === null ? null : [
                'id'   => $import->circle_id,
                'name' => $import->circle?->name,
            ],
            'routing'        => $import->routing,
            'routing_reason' => $import->routing_reason,
            'submitted_by'   => ['id' => $import->submitted_by_user_id, 'name' => $import->submittedBy?->name],
            'summary'        => $import->result_json['summary'] ?? null,
            'counts'         => $import->result_json['counts'] ?? null,
            'mode'           => $import->result_json['mode'] ?? null,
            'error'          => $import->error,
            'created_at'     => $import->created_at?->toISOString(),
            'processed_at'   => $import->processed_at?->toISOString(),
        ];

        if ($detail) {
            $out['uncertainty'] = $import->result_json['uncertainty'] ?? null;
            $out['actions']     = $import->result_json['actions'] ?? [];
            $out['evidence_item_id'] = $import->evidence_item_id;
            $out['agent_run_id']     = $import->agent_run_id;
        }

        return $out;
    }
}
