<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\Classification;
use App\Enums\Permission;
use App\Enums\ReviewStatus;
use App\Jobs\ProcessEvidenceVersion;
use App\Models\Circle;
use App\Models\EvidenceItem;
use App\Models\EvidenceVersion;
use App\Models\ResourceAccessOverride;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use App\Services\Evidence\EvidenceService;
use App\Services\Evidence\EvidenceStorage;
use App\Support\MediaType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EvidenceController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly EvidenceService $evidence,
        private readonly EvidenceStorage $storage,
        private readonly AuditChain $audit,
    ) {}

    /**
     * Step 1 of the ingest pipeline: hand the browser a signed URL so the binary
     * goes straight to private storage and never transits the API.
     */
    public function signUpload(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ResourceUpload, $circle);

        abort_unless($circle->acceptsContributions(), 422, 'This Circle is not accepting new evidence.');

        $data = $request->validate([
            'filename'     => ['required', 'string', 'max:255'],
            'content_type' => ['nullable', 'string', 'max:255'],
            'byte_size'    => ['nullable', 'integer', 'min:1'],
        ]);

        $filename = $data['filename'];

        abort_unless(
            MediaType::isSupported($filename),
            422,
            'Unsupported file type. Supported: ' . implode(', ', MediaType::supportedExtensions()),
        );

        // The key is minted server-side under this Circle's prefix, so a client
        // cannot aim its upload at a Circle it cannot see.
        $key = $this->storage->stagedUploadKey($circle->id, $filename);

        $contentType = MediaType::canonicalMime($filename, $data['content_type'] ?? 'application/octet-stream');

        return response()->json([
            'data' => $this->storage->signedUploadUrl($key, $contentType),
        ]);
    }

    /** Step 3: register the uploaded object and queue verification. */
    public function store(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ResourceUpload, $circle);

        abort_unless($circle->acceptsContributions(), 422, 'This Circle is not accepting new evidence.');

        $data = $request->validate([
            'storage_key'    => ['required', 'string', 'max:1024'],
            'filename'       => ['required', 'string', 'max:255'],
            'name'           => ['nullable', 'string', 'max:255'],
            'content_type'   => ['nullable', 'string', 'max:255'],
            'classification' => ['nullable', 'string', 'in:public,internal,confidential,restricted'],
            'source_label'   => ['nullable', 'string', 'max:255'],
            'source_url'     => ['nullable', 'url', 'max:2048'],
            'agent_read'     => ['nullable', 'boolean'],
            'downloadable'   => ['nullable', 'boolean'],
            'expires_at'     => ['nullable', 'date', 'after:now'],
        ]);

        // The key must live under this Circle's prefix — otherwise a caller
        // could register an object belonging to a Circle they cannot see.
        abort_unless(
            str_starts_with($data['storage_key'], "circles/{$circle->id}/evidence/"),
            422,
            'The storage key does not belong to this Circle.',
        );

        abort_unless($this->storage->exists($data['storage_key']), 422, 'No uploaded object was found at that key.');

        $item = $this->evidence->createItem(
            circle: $circle,
            uploader: $request->user(),
            storageKey: $data['storage_key'],
            originalFilename: $data['filename'],
            displayName: $data['name'] ?? null,
            declaredMimeType: $data['content_type'] ?? null,
            classification: Classification::from($data['classification'] ?? 'internal'),
            sourceLabel: $data['source_label'] ?? null,
            sourceUrl: $data['source_url'] ?? null,
            agentRead: (bool) ($data['agent_read'] ?? false),
            downloadable: (bool) ($data['downloadable'] ?? true),
            expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        ProcessEvidenceVersion::dispatch($item->currentVersion()->id);

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ResourceView, $circle);

        $items = EvidenceItem::query()
            ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
            ->with(['resource', 'versions', 'uploader'])
            ->when($request->query('review_status'), fn ($q, $v) => $q->where('review_status', $v))
            ->when($request->query('origin_status'), fn ($q, $v) => $q->where('origin_status', $v))
            ->when($request->query('classification'), fn ($q, $v) => $q->where('classification', $v))
            ->when($request->query('uploader'), fn ($q, $v) => $q->where('uploader_user_id', $v))
            ->orderByDesc('created_at')
            ->get();

        // Filtering by media lane happens after load: the lane lives in the
        // version's metadata rather than a column.
        if ($lane = $request->query('lane')) {
            $items = $items->filter(fn ($i) => $i->currentVersion()?->lane() === $lane)->values();
        }

        return response()->json(['data' => $items->map(fn ($i) => $this->present($i))->values()]);
    }

    public function show(Request $request, EvidenceItem $evidenceItem): JsonResponse
    {
        $circle = $evidenceItem->resource->circle;
        $this->gate->authorise($request->user(), Permission::ResourceView, $circle, $evidenceItem->resource);

        $this->audit->record(
            AuditEventType::ResourceViewed, $circle, ActorType::User, $request->user()->id,
            'evidence_item', $evidenceItem->id,
        );

        return response()->json(['data' => $this->present($evidenceItem->load('versions', 'uploader'), detailed: true)]);
    }

    public function versions(Request $request, EvidenceItem $evidenceItem): JsonResponse
    {
        $circle = $evidenceItem->resource->circle;
        $this->gate->authorise($request->user(), Permission::ResourceView, $circle, $evidenceItem->resource);

        return response()->json([
            'data' => $evidenceItem->versions()->with('createdBy')->get()->map(fn ($v) => $this->presentVersion($v))->all(),
        ]);
    }

    /** Adds a replacement version; the previous one is superseded, not replaced. */
    public function addVersion(Request $request, EvidenceItem $evidenceItem): JsonResponse
    {
        $circle = $evidenceItem->resource->circle;
        $this->gate->authorise($request->user(), Permission::ResourceUpload, $circle, $evidenceItem->resource);

        abort_unless($circle->acceptsContributions(), 422, 'This Circle is not accepting new evidence.');

        $data = $request->validate([
            'storage_key'  => ['required', 'string', 'max:1024'],
            'filename'     => ['required', 'string', 'max:255'],
            'content_type' => ['nullable', 'string', 'max:255'],
        ]);

        abort_unless(
            str_starts_with($data['storage_key'], "circles/{$circle->id}/evidence/"),
            422,
            'The storage key does not belong to this Circle.',
        );

        abort_unless($this->storage->exists($data['storage_key']), 422, 'No uploaded object was found at that key.');

        $version = $this->evidence->addVersion(
            item: $evidenceItem,
            uploader: $request->user(),
            storageKey: $data['storage_key'],
            originalFilename: $data['filename'],
            declaredMimeType: $data['content_type'] ?? null,
        );

        ProcessEvidenceVersion::dispatch($version->id);

        return response()->json(['data' => $this->presentVersion($version)], 201);
    }

    /** Toggles the explicit agent-read and download rights (spec §10). */
    public function updateAccess(Request $request, EvidenceItem $evidenceItem): JsonResponse
    {
        $circle = $evidenceItem->resource->circle;
        $this->gate->authorise($request->user(), Permission::ResourceShare, $circle, $evidenceItem->resource);

        $data = $request->validate([
            'agent_read'     => ['sometimes', 'boolean'],
            'downloadable'   => ['sometimes', 'boolean'],
            'classification' => ['sometimes', 'string', 'in:public,internal,confidential,restricted'],
            'grant'          => ['sometimes', 'array'],
            'grant.user_id'    => ['required_with:grant', 'string', 'exists:users,id'],
            'grant.permission' => ['required_with:grant', 'string'],
            'grant.allow'      => ['required_with:grant', 'boolean'],
        ]);

        $evidenceItem->fill(array_intersect_key($data, array_flip(['agent_read', 'downloadable', 'classification'])))->save();

        if (isset($data['grant'])) {
            ResourceAccessOverride::updateOrCreate(
                [
                    'resource_id' => $evidenceItem->resource_id,
                    'user_id'     => $data['grant']['user_id'],
                    'permission'  => $data['grant']['permission'],
                ],
                [
                    'allow'              => $data['grant']['allow'],
                    'granted_by_user_id' => $request->user()->id,
                ],
            );
        }

        $this->audit->record(
            AuditEventType::ResourceShared, $circle, ActorType::User, $request->user()->id,
            'evidence_item', $evidenceItem->id, metadata: $data,
        );

        return response()->json(['data' => $this->present($evidenceItem->fresh()->load('versions', 'uploader'))]);
    }

    public function review(Request $request, EvidenceItem $evidenceItem): JsonResponse
    {
        $circle = $evidenceItem->resource->circle;
        $this->gate->authorise($request->user(), Permission::ClaimReview, $circle, $evidenceItem->resource);

        $data = $request->validate([
            'review_status' => ['required', 'string', 'in:reviewed,contested,unreviewed,stale'],
            'comment'       => ['nullable', 'string', 'max:2000'],
        ]);

        $item = $this->evidence->review(
            $evidenceItem,
            $request->user(),
            ReviewStatus::from($data['review_status']),
            $data['comment'] ?? null,
        );

        return response()->json(['data' => $this->present($item->fresh()->load('versions', 'uploader'))]);
    }

    public function markStale(Request $request, EvidenceItem $evidenceItem): JsonResponse
    {
        $circle = $evidenceItem->resource->circle;
        $this->gate->authorise($request->user(), Permission::ClaimReview, $circle, $evidenceItem->resource);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $item = $this->evidence->markStale(
            $evidenceItem, ActorType::User, $request->user()->id, $data['reason'] ?? null,
        );

        return response()->json(['data' => $this->present($item->fresh()->load('versions', 'uploader'))]);
    }

    /**
     * Issues a short-lived download URL. Download is a separate right from view,
     * so this runs its own check (spec §7).
     */
    public function downloadUrl(Request $request, EvidenceVersion $version): JsonResponse
    {
        $item   = $version->evidenceItem;
        $circle = $item->resource->circle;

        $this->gate->authorise($request->user(), Permission::ResourceDownload, $circle, $item->resource);

        $this->audit->record(
            AuditEventType::ResourceDownloaded, $circle, ActorType::User, $request->user()->id,
            'evidence_version', $version->id, (string) $version->version_number,
            ['sha256' => $version->sha256],
        );

        return response()->json([
            'data' => [
                'url'        => $this->storage->signedDownloadUrl($version->storage_key, $version->original_filename),
                'expires_in' => 300,
                'sha256'     => $version->sha256,
            ],
        ]);
    }

    // ------------------------------------------------------------ presenters

    private function present(EvidenceItem $item, bool $detailed = false): array
    {
        $current = $item->currentVersion();

        $payload = [
            'id'               => $item->id,
            'resource_id'      => $item->resource_id,
            'name'             => $item->resource->name,
            // The three trust axes stay separate — never collapsed into one
            // "verified" badge (spec §6).
            'origin_status'    => $item->origin_status->value,
            'integrity_status' => $current?->integrityStatus()->value ?? $item->integrity_status->value,
            'review_status'    => $item->review_status->value,
            'classification'   => $item->classification->value,
            'agent_read'       => $item->agent_read,
            'downloadable'     => $item->downloadable,
            'source_label'     => $item->source_label,
            'source_url'       => $item->source_url,
            'uploader'         => ['id' => $item->uploader_user_id, 'name' => $item->uploader?->name],
            'expires_at'       => $item->expires_at?->toISOString(),
            'stale_at'         => $item->stale_at?->toISOString(),
            'created_at'       => $item->created_at?->toISOString(),
            'version_count'    => $item->versions()->count(),
            'current_version'  => $current ? $this->presentVersion($current) : null,
        ];

        if ($detailed) {
            $payload['versions'] = $item->versions->map(fn ($v) => $this->presentVersion($v))->all();
            $payload['used_by']  = $this->usedBy($item);
        }

        return $payload;
    }

    private function presentVersion(EvidenceVersion $version): array
    {
        return [
            'id'                    => $version->id,
            'version_number'        => $version->version_number,
            'original_filename'     => $version->original_filename,
            'mime_type'             => $version->mime_type,
            'byte_size'             => $version->byte_size,
            'sha256'                => $version->sha256,
            'lane'                  => $version->lane(),
            'integrity_status'      => $version->integrityStatus()->value,
            'processing_status'     => $version->processing_status->value,
            'processing_error'      => $version->processing_error,
            'extracted_text_status' => $version->extracted_text_status->value,
            'preview_status'        => $version->preview_status->value,
            'transcript_status'     => $version->transcript_status->value,
            'metadata'              => $version->metadata_json,
            'created_by'            => ['id' => $version->created_by_user_id, 'name' => $version->createdBy?->name],
            'supersedes_version_id' => $version->supersedes_version_id,
            'created_at'            => $version->created_at?->toISOString(),
        ];
    }

    /** The "used by" list from spec §12 — what depends on this evidence. */
    private function usedBy(EvidenceItem $item): array
    {
        $versionIds = $item->versions()->pluck('id');

        $citations = \App\Models\ClaimCitation::whereIn('evidence_version_id', $versionIds)
            ->with('claim')
            ->get();

        return [
            'claims' => $citations->map(fn ($c) => [
                'claim_id'            => $c->claim_id,
                'statement'           => $c->claim?->statement,
                'status'              => $c->claim?->status->value,
                'evidence_version_id' => $c->evidence_version_id,
                'locator'             => $c->locator_json,
            ])->all(),
            'decisions' => \App\Models\Decision::where('subject_type', 'evidence_item')
                ->where('subject_id', $item->id)
                ->get()
                ->map(fn ($d) => [
                    'decision_id'     => $d->id,
                    'title'           => $d->title,
                    'status'          => $d->status->value,
                    'subject_version' => $d->subject_version,
                ])->all(),
        ];
    }
}
