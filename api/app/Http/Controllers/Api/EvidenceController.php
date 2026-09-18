<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\Classification;
use App\Enums\Permission;
use App\Enums\ReviewStatus;
use App\Jobs\ProcessEvidenceVersion;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
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
        private readonly \App\Services\Evidence\SupersessionImpact $impact,
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
            'restricted_to_party_id' => ['nullable', 'string', 'exists:circle_parties,id'],
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
            restrictedToPartyId: $this->partyScope($request, $circle, $data['restricted_to_party_id'] ?? null),
        );

        ProcessEvidenceVersion::dispatch($item->currentVersion()->id);

        return response()->json(['data' => $this->present($item)], 201);
    }

    /**
     * Sign a whole folder at once.
     *
     * The single-file path above is correct and, for the way this product is
     * actually used, insufficient: a tender pack is 140 files that already sit
     * in a folder, and asking somebody to add them one at a time is asking them
     * to do the filing twice. This is not a new ingest route — it is the same
     * signing step, batched, so the browser can walk a directory the person
     * dropped on it.
     *
     * Unsupported files are reported rather than refused. A folder of 140 will
     * contain a .DS_Store and somebody's thumbs.db, and failing the whole drop
     * because of them would teach people to go back to uploading one at a time.
     */
    public function signUploadBatch(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ResourceUpload, $circle);

        abort_unless($circle->acceptsContributions(), 422, 'This Circle is not accepting new evidence.');

        $data = $request->validate([
            'files'                => ['required', 'array', 'min:1', 'max:250'],
            'files.*.filename'     => ['required', 'string', 'max:255'],
            'files.*.content_type' => ['nullable', 'string', 'max:255'],
            'files.*.byte_size'    => ['nullable', 'integer', 'min:1'],
            // Where the browser walked a directory, this is the path inside it.
            // Carried through so the evidence name can keep the shape the
            // person recognises instead of 140 files in a flat list.
            'files.*.relative_path' => ['nullable', 'string', 'max:1024'],
        ]);

        $signed   = [];
        $rejected = [];

        foreach ($data['files'] as $file) {
            if (! MediaType::isSupported($file['filename'])) {
                $rejected[] = ['filename' => $file['filename'], 'reason' => 'unsupported_type'];

                continue;
            }

            $key = $this->storage->stagedUploadKey($circle->id, $file['filename']);

            $signed[] = [
                'filename'      => $file['filename'],
                'relative_path' => $file['relative_path'] ?? null,
                'storage_key'   => $key,
                'upload'        => $this->storage->signedUploadUrl(
                    $key,
                    MediaType::canonicalMime($file['filename'], $file['content_type'] ?? 'application/octet-stream'),
                ),
            ];
        }

        return response()->json([
            'data' => [
                'signed'   => $signed,
                'rejected' => $rejected,
                'supported_extensions' => MediaType::supportedExtensions(),
            ],
        ]);
    }

    /**
     * Register everything the browser just uploaded, in one request.
     *
     * Per-file outcomes rather than one verdict, for the same reason as above:
     * a folder drop that half-worked must say which half, and must not discard
     * the files that did land because one did not.
     */
    public function storeBatch(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ResourceUpload, $circle);

        abort_unless($circle->acceptsContributions(), 422, 'This Circle is not accepting new evidence.');

        $data = $request->validate([
            'items'                  => ['required', 'array', 'min:1', 'max:250'],
            'items.*.storage_key'    => ['required', 'string', 'max:1024'],
            'items.*.filename'       => ['required', 'string', 'max:255'],
            'items.*.name'           => ['nullable', 'string', 'max:255'],
            'items.*.content_type'   => ['nullable', 'string', 'max:255'],
            'items.*.relative_path'  => ['nullable', 'string', 'max:1024'],
            // Applied to every item in the batch. Per-item access decisions are
            // made afterwards on the items that need them — asking somebody to
            // classify 140 files at the moment of upload gets 140 defaults.
            'classification'         => ['nullable', 'string', 'in:public,internal,confidential,restricted'],
            'agent_read'             => ['nullable', 'boolean'],
            'downloadable'           => ['nullable', 'boolean'],
            'restricted_to_party_id' => ['nullable', 'string', 'exists:circle_parties,id'],
        ]);

        $classification = Classification::from($data['classification'] ?? 'internal');
        $partyScope     = $this->partyScope($request, $circle, $data['restricted_to_party_id'] ?? null);

        $created  = [];
        $rejected = [];

        foreach ($data['items'] as $entry) {
            $reason = $this->batchRejection($circle, $entry);

            if ($reason !== null) {
                $rejected[] = ['filename' => $entry['filename'], 'reason' => $reason];

                continue;
            }

            $item = $this->evidence->createItem(
                circle: $circle,
                uploader: $request->user(),
                storageKey: $entry['storage_key'],
                originalFilename: $entry['filename'],
                displayName: $entry['name'] ?? $this->nameFromPath($entry),
                declaredMimeType: $entry['content_type'] ?? null,
                classification: $classification,
                sourceLabel: $entry['relative_path'] ?? null,
                sourceUrl: null,
                agentRead: (bool) ($data['agent_read'] ?? false),
                downloadable: (bool) ($data['downloadable'] ?? true),
                expiresAt: null,
                restrictedToPartyId: $partyScope,
            );

            ProcessEvidenceVersion::dispatch($item->currentVersion()->id);

            $created[] = $this->present($item);
        }

        return response()->json([
            'data' => [
                'created'       => $created,
                'created_count' => count($created),
                'rejected'      => $rejected,
            ],
        ], 201);
    }

    /** Why this entry cannot be registered, or null if it can. */
    private function batchRejection(Circle $circle, array $entry): ?string
    {
        if (! str_starts_with($entry['storage_key'], "circles/{$circle->id}/evidence/")) {
            return 'wrong_circle';
        }

        if (! MediaType::isSupported($entry['filename'])) {
            return 'unsupported_type';
        }

        if (! $this->storage->exists($entry['storage_key'])) {
            return 'not_uploaded';
        }

        return null;
    }

    /**
     * Keep the folder shape in the name.
     *
     * "02 Received/Addendum 3.pdf" is how the person filed it and how they will
     * look for it. A flat list of 140 filenames is not a record anybody browses.
     */
    private function nameFromPath(array $entry): ?string
    {
        $path = $entry['relative_path'] ?? null;

        if ($path === null || $path === '') {
            return null;
        }

        $folder = trim(dirname(str_replace('\\', '/', $path)), './');

        return $folder === '' ? null : $folder . ' / ' . pathinfo($entry['filename'], PATHINFO_FILENAME);
    }

    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ResourceView, $circle);

        $items = EvidenceItem::query()
            ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
            ->with(['resource', 'versions', 'uploader', 'restrictedToParty'])
            ->when($request->query('review_status'), fn ($q, $v) => $q->where('review_status', $v))
            ->when($request->query('origin_status'), fn ($q, $v) => $q->where('origin_status', $v))
            ->when($request->query('classification'), fn ($q, $v) => $q->where('classification', $v))
            ->when($request->query('uploader'), fn ($q, $v) => $q->where('uploader_user_id', $v))
            ->orderByDesc('created_at')
            ->get();

        // The register is where the leak would actually happen. `show` runs the
        // gate per item, but a list that returns every row hands over the file
        // names, the uploaders and the dates — which for a rate card is most of
        // what a competitor wanted. Filtered through the same rule the gate
        // uses, so the two cannot drift apart.
        $partyId    = $this->viewerPartyId($request, $circle);
        $convenerId = CircleParty::convenerIdFor($circle->id);

        $items = $items->filter(fn (EvidenceItem $i) => $i->isVisibleToParty($partyId, $convenerId))->values();

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
            'restricted_to_party_id' => ['sometimes', 'nullable', 'string', 'exists:circle_parties,id'],
            'grant'          => ['sometimes', 'array'],
            'grant.user_id'    => ['required_with:grant', 'string', 'exists:users,id'],
            'grant.permission' => ['required_with:grant', 'string'],
            'grant.allow'      => ['required_with:grant', 'boolean'],
        ]);

        if (array_key_exists('restricted_to_party_id', $data)) {
            // Widening is not reversible in any way that matters — the other
            // parties have already read it — but it is still recorded rather
            // than refused, because an item put in the wrong scope by mistake
            // has to be fixable by someone.
            $data['restricted_to_party_id'] = $this->partyScope($request, $circle, $data['restricted_to_party_id']);
        }

        $evidenceItem->fill(array_intersect_key($data, array_flip([
            'agent_read', 'downloadable', 'classification', 'restricted_to_party_id',
        ])))->save();

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
    /**
     * What relied on this version.
     *
     * Asked of any version, not only a superseded one, because the useful time
     * to ask is usually *before* replacing something: a person about to issue
     * Rev D wants to know who priced against Rev C while they can still say so
     * in the covering note.
     *
     * Read-only and deliberately so. Nothing here marks a claim stale or
     * reopens a decision — a new revision does not necessarily invalidate a
     * judgement somebody made, and the software is in no position to decide
     * that it does.
     */
    public function impact(Request $request, EvidenceVersion $version): JsonResponse
    {
        $item   = $version->evidenceItem;
        $circle = $item->resource->circle;

        $this->gate->authorise($request->user(), Permission::ResourceView, $circle, $item->resource);

        $impact = $this->impact->of($version);

        return response()->json([
            'data' => [
                'evidence_version_id' => $version->id,
                'version_number'      => $version->version_number,
                'is_current'          => $item->currentVersion()?->id === $version->id,
                'claims' => $impact['claims']->map(fn ($claim) => [
                    'id'        => $claim->id,
                    'statement' => $claim->statement,
                    'status'    => $claim->status->value,
                    'author'    => $claim->isAgentAuthored() ? 'agent' : $claim->author?->name,
                ])->values(),
                'decisions' => $impact['decisions']->map(fn ($decision) => [
                    'id'       => $decision->id,
                    'title'    => $decision->title,
                    'status'   => $decision->status->value,
                    'approver' => $decision->approver?->name,
                ])->values(),
                // Who the product would tell if this version were superseded
                // now. Shown so the answer is inspectable rather than something
                // that silently happens in a mail queue.
                'would_notify' => $impact['recipients']->map(fn ($u) => $u->name)->values(),
            ],
        ]);
    }

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

    // ----------------------------------------------------------- party scope

    /**
     * The party the caller is reading as.
     *
     * The gate's own copy of this comes off the membership it already loaded;
     * here the membership has to be fetched, so the two agree by both going
     * through effectivePartyId() rather than by both reading the column.
     */
    private function viewerPartyId(Request $request, Circle $circle): ?string
    {
        $membership = CircleMembership::query()
            ->where('circle_id', $circle->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return $membership?->effectivePartyId();
    }

    /**
     * Validate a requested scope, and decide who may set one.
     *
     * Two rules. The party has to be in this Circle — `exists:circle_parties,id`
     * only proves it is a party somewhere, which would let a caller point an
     * item at a party in a Circle they have never seen and lose it. And you may
     * only scope an item to your own party, unless you hold resource.share.
     *
     * That second rule is what stops the scope becoming a way to hide something
     * from the people it belongs to: a contractor cannot upload a document and
     * pin it to a counterparty. Someone with resource.share is re-filing an item
     * that is already in the Circle, which is a different and legitimate act.
     */
    private function partyScope(Request $request, Circle $circle, ?string $partyId): ?string
    {
        if ($partyId === null) {
            return null;
        }

        $party = CircleParty::find($partyId);

        abort_unless($party !== null && $party->circle_id === $circle->id, 422, 'That party is not in this Circle.');

        $own = $this->viewerPartyId($request, $circle);

        abort_unless(
            $partyId === $own || $this->gate->allows($request->user(), Permission::ResourceShare, $circle),
            403,
            'You can only restrict an item to your own party.',
        );

        return $partyId;
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
            // Stated on every row, not just the detail pane: someone deciding
            // whether to put their commercials in needs to see, at a glance,
            // that the last person who did got a scope honoured.
            'restricted_to_party' => $item->restricted_to_party_id === null ? null : [
                'id'    => $item->restricted_to_party_id,
                'label' => $item->restrictedToParty?->label(),
            ],
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
