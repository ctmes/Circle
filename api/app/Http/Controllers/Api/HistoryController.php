<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Circle;
use App\Models\Export;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;
use App\Services\Evidence\EvidenceStorage;
use App\Services\Exports\ExportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HistoryController extends Controller
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
        private readonly ExportBuilder $exports,
        private readonly EvidenceStorage $storage,
    ) {}

    /** The Circle History view (spec §12), with live chain verification. */
    public function index(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        $events = AuditEvent::where('circle_id', $circle->id)
            ->when($request->query('event_type'), fn ($q, $v) => $q->where('event_type', $v))
            ->when($request->query('actor_id'), fn ($q, $v) => $q->where('actor_id', $v))
            ->when($request->query('actor_type'), fn ($q, $v) => $q->where('actor_type', $v))
            ->when($request->query('resource_id'), fn ($q, $v) => $q->where('resource_id', $v))
            ->orderByDesc('sequence')
            ->limit((int) $request->query('limit', 200))
            ->get();

        return response()->json([
            'data' => $events->map(fn (AuditEvent $e) => [
                'sequence'         => $e->sequence,
                'id'               => $e->id,
                'event_type'       => $e->event_type->value,
                'actor_type'       => $e->actor_type->value,
                'actor_id'         => $e->actor_id,
                'resource_type'    => $e->resource_type,
                'resource_id'      => $e->resource_id,
                'resource_version' => $e->resource_version,
                'occurred_at'      => $e->occurred_at?->toISOString(),
                'summary'          => $this->summarise($e),
                // Raw JSON is expandable in the UI (spec §12).
                'metadata'         => $e->metadata_json,
                'previous_hash'    => $e->previous_hash,
                'event_hash'       => $e->event_hash,
            ])->all(),
            'meta' => [
                'chain' => $this->audit->verify($circle->id)->toArray(),
            ],
        ]);
    }

    /** Re-verifies the whole chain on demand. */
    public function verify(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::CircleView, $circle);

        return response()->json(['data' => $this->audit->verify($circle->id)->toArray()]);
    }

    public function createExport(Request $request, Circle $circle): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ExportCreate, $circle);

        $export = Export::create([
            'circle_id'            => $circle->id,
            'requested_by_user_id' => $request->user()->id,
            'status'               => 'pending',
        ]);

        try {
            $export = $this->exports->build($export);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Export failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['data' => $this->presentExport($export)], 201);
    }

    public function showExport(Request $request, Export $export): JsonResponse
    {
        $this->gate->authorise($request->user(), Permission::ExportCreate, $export->circle);

        return response()->json(['data' => $this->presentExport($export)]);
    }

    private function presentExport(Export $export): array
    {
        return [
            'id'                => $export->id,
            'status'            => $export->status,
            'sha256'            => $export->sha256,
            'byte_size'         => $export->byte_size,
            'audit_chain_valid' => $export->audit_chain_valid,
            'completed_at'      => $export->completed_at?->toISOString(),
            'error'             => $export->error,
            'manifest'          => $export->manifest_json,
            'download_url'      => $export->storage_key
                ? $this->storage->signedDownloadUrl($export->storage_key, "circle-{$export->circle_id}-packet.zip")
                : null,
        ];
    }

    /** A human-readable one-liner for the event card (spec §12). */
    private function summarise(AuditEvent $event): string
    {
        $meta = $event->metadata_json ?? [];

        return match ($event->event_type->value) {
            'circle.created'             => "Circle created: {$meta['name']}",
            'circle.member_invited'      => "Invited {$meta['email']} as {$meta['circle_role']}",
            'circle.member_role_changed' => isset($meta['event'])
                ? "Invitation accepted as {$meta['circle_role']}"
                : "Role changed from {$meta['from']} to {$meta['to']}",
            'circle.member_removed'      => 'Member access revoked',
            'circle.closed'              => sprintf(
                'Circle closed; %d external membership(s) revoked',
                $meta['external_memberships_revoked'] ?? 0,
            ),
            'resource.uploaded'          => "Uploaded {$meta['filename']}",
            'resource.version_created'   => "New version {$event->resource_version} of {$meta['filename']}",
            'resource.viewed'            => 'Evidence viewed',
            'resource.downloaded'        => 'Evidence downloaded',
            'resource.shared'            => 'Evidence access changed',
            'resource.processing_completed' => sprintf(
                'Processed: %s, %s bytes, SHA-256 recorded',
                $meta['mime_type'] ?? 'unknown type',
                $meta['byte_size'] ?? '?',
            ),
            'resource.processing_failed' => 'Processing failed: ' . ($meta['error'] ?? $meta['reason'] ?? 'unknown'),
            'resource.marked_stale'      => 'Marked potentially stale',
            'claim.created'              => 'Claim created with ' . ($meta['citation_count'] ?? 0) . ' citation(s)',
            'claim.reviewed'             => 'Claim reviewed: ' . ($meta['outcome'] ?? $meta['review_status'] ?? ''),
            'claim.contested'            => 'Claim contested',
            'decision.created'           => isset($meta['event'])
                ? 'Approval superseded by a new version'
                : "Decision created: {$meta['title']}",
            'decision.approved'          => 'Decision approved against version ' . ($meta['subject_version'] ?? 'n/a'),
            'decision.rejected'          => 'Decision rejected',
            'commitment.created'         => "Commitment created: {$meta['title']}",
            'commitment.updated'         => "Commitment moved from {$meta['from']} to {$meta['to']}",
            'agent.run_started'          => 'Agent run started'
                . (($meta['model'] ?? 'none') === 'none' ? ' (no model configured)' : " ({$meta['model']})"),
            'agent.resource_retrieved'   => ($meta['permitted'] ?? false)
                ? 'Agent read an evidence item'
                : 'Agent was refused an evidence item (' . ($meta['reason'] ?? '') . ')',
            'agent.output_created'       => sprintf(
                'Agent produced %d claim(s) and %d decision draft(s)',
                $meta['claims'] ?? 0,
                $meta['decisions'] ?? 0,
            ),
            'agent.run_failed'           => 'Agent run failed',
            'export.created'             => 'Mission packet exported',
            'access.denied'              => "Access denied: {$meta['permission']} ({$meta['reason']})",
            default                      => $event->event_type->value,
        };
    }
}
