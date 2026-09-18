<?php

namespace App\Services\Agent;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Enums\ProcessingStatus;
use App\Models\AgentInstance;
use App\Models\AgentResourceAccess;
use App\Models\AgentRun;
use App\Models\Circle;
use App\Models\DerivedArtifact;
use App\Models\EvidenceItem;
use App\Services\Audit\AuditChain;
use App\Services\Authorisation\AccessGate;

/**
 * Builds an agent's context window — and is the only path by which any agent
 * ever sees Circle content.
 *
 * Every candidate resource is put through AccessGate individually. Denials are
 * recorded just as retrievals are, so the "what did this agent access, and what
 * was it refused" question has a complete answer (spec §16, §19).
 *
 * The agent is given *extracted text only*. It never receives raw binaries,
 * signed URLs, or connector credentials.
 */
class AgentRetrieval
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AuditChain $audit,
    ) {}

    /**
     * Written for the Steward and unchanged when authored agents arrived, which
     * is the point: retrieval never asks whose agent it is serving. The gate
     * decides per item, per instance, every time — so an agent brought by a
     * contractor is filtered by exactly the code that filters ours.
     *
     * @param  list<string>|null  $onlyItemIds  Narrows the candidate set to named
     *         evidence items. Narrowing only: an item listed here is still put
     *         through the gate, and one that is not agent-readable is still
     *         refused. Convening uses it so that reading a contract does not
     *         sweep in every other document in the Circle (spec 23).
     * @return array{sources: list<array>, manifest: array}
     */
    public function gather(AgentInstance $agent, Circle $circle, AgentRun $run, ?array $onlyItemIds = null): array
    {
        $maxSources     = (int) config('circle.agent.max_sources');
        $maxCharsPerDoc = (int) config('circle.agent.max_chars_per_source');

        $items = EvidenceItem::query()
            ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
            // A cheap pre-filter only. The authoritative check is the per-item
            // AccessGate call below — this just avoids loading the whole vault.
            ->where('agent_read', true)
            ->when($onlyItemIds !== null, fn ($q) => $q->whereIn('id', $onlyItemIds))
            ->with(['resource', 'versions'])
            ->get();

        $sources  = [];
        $manifest = [
            'circle_id'         => $circle->id,
            'scoped_to_items'   => $onlyItemIds,
            'considered'        => $items->count(),
            'retrieved'         => 0,
            'denied'            => 0,
            'items'             => [],
            'max_sources'       => $maxSources,
            'max_chars_per_doc' => $maxCharsPerDoc,
        ];

        foreach ($items as $item) {
            if (count($sources) >= $maxSources) {
                break;
            }

            $decision = $this->gate->inspect($agent, Permission::ResourceAgentRead, $circle, $item->resource);

            if (! $decision->allowed) {
                $this->recordAccess($run, $item, null, permitted: false, reason: $decision->reason);
                $manifest['denied']++;
                $manifest['items'][] = [
                    'evidence_item_id' => $item->id,
                    'permitted'        => false,
                    'reason'           => $decision->reason,
                ];

                continue;
            }

            $version = $item->currentVersion();

            if ($version === null || $version->processing_status !== ProcessingStatus::Ready) {
                // Unverified evidence is not shown to the agent at all — it
                // would be citing something we cannot yet vouch for.
                $this->recordAccess($run, $item, $version?->id, permitted: false, reason: 'version_not_ready');
                $manifest['denied']++;

                continue;
            }

            $text = $this->extractedTextFor($version->id, $maxCharsPerDoc);

            $sources[] = [
                'evidence_item_id'    => $item->id,
                'evidence_version_id' => $version->id,
                'name'                => $item->resource->name,
                'filename'            => $version->original_filename,
                'version_number'      => $version->version_number,
                'mime_type'           => $version->mime_type,
                'lane'                => $version->lane(),
                'sha256'              => $version->sha256,
                'uploaded_by'         => $item->uploader?->name,
                'uploaded_at'         => $version->created_at?->toISOString(),
                'origin_status'       => $item->origin_status->value,
                'integrity_status'    => $version->integrityStatus()->value,
                'review_status'       => $item->review_status->value,
                'classification'      => $item->classification->value,
                // Floored to a whole day. Carbon 3's diffInDays() returns a
                // float, so a version uploaded moments ago came out as
                // 5.787037037037E-6 and went into the prompt verbatim — the
                // model was being told an item was "5.787037037037E-6 days
                // ago". EvidenceStaleness has always declared this an int.
                'age_days'            => $version->created_at === null
                    ? null
                    : (int) floor($version->created_at->diffInDays(now(), absolute: true)),
                'content'             => $text,
            ];

            $this->recordAccess($run, $item, $version->id, permitted: true);
            $manifest['retrieved']++;
            $manifest['items'][] = [
                'evidence_item_id'    => $item->id,
                'evidence_version_id' => $version->id,
                'sha256'              => $version->sha256,
                'permitted'           => true,
                'chars_supplied'      => $text === null ? 0 : mb_strlen($text['text'] ?? ''),
            ];
        }

        return ['sources' => $sources, 'manifest' => $manifest];
    }

    /**
     * Pulls the derived text for a version: document/spreadsheet extraction,
     * OCR, or transcript — whichever exists. Truncation is explicit and stated
     * in the payload so the model knows it is not seeing the whole document.
     */
    private function extractedTextFor(string $versionId, int $maxChars): ?array
    {
        $artifacts = DerivedArtifact::query()
            ->where('parent_resource_type', 'evidence_version')
            ->where('parent_resource_id', $versionId)
            ->whereIn('artifact_type', ['extraction', 'ocr', 'transcript'])
            ->where('status', 'ready')
            ->get();

        if ($artifacts->isEmpty()) {
            return null;
        }

        $parts = [];
        $pageIndex = null;
        $segments = null;

        foreach ($artifacts as $artifact) {
            $content = $artifact->content_json ?? [];
            $text = $content['text'] ?? null;

            if (is_string($text) && $text !== '') {
                $parts[] = ['source' => $artifact->artifact_type->value, 'text' => $text];
            }

            // Keep the locator scaffolding so the model can cite a page or a
            // timestamp rather than guessing one.
            if (isset($content['pages'])) {
                $pageIndex = array_map(
                    fn (array $p) => ['page' => $p['page'], 'start_char' => $p['start_char'], 'end_char' => $p['end_char']],
                    $content['pages'],
                );
            }

            if (isset($content['segments'])) {
                $segments = $content['segments'];
            }

            if (isset($content['sheets'])) {
                $pageIndex = array_map(
                    fn (array $s) => ['sheet' => $s['name'], 'cell_count' => $s['cell_count']],
                    $content['sheets'],
                );
            }
        }

        if ($parts === []) {
            return null;
        }

        $combined = implode("\n\n", array_column($parts, 'text'));
        $truncated = mb_strlen($combined) > $maxChars;

        return [
            'text'       => $truncated ? mb_substr($combined, 0, $maxChars) : $combined,
            'truncated'  => $truncated,
            'total_chars' => mb_strlen($combined),
            'sources'    => array_column($parts, 'source'),
            'page_index' => $pageIndex,
            'segments'   => $segments,
        ];
    }

    private function recordAccess(
        AgentRun $run,
        EvidenceItem $item,
        ?string $versionId,
        bool $permitted,
        ?string $reason = null,
    ): void {
        AgentResourceAccess::create([
            'agent_run_id'        => $run->id,
            'resource_id'         => $item->resource_id,
            'evidence_version_id' => $versionId,
            'access_type'         => 'read',
            'permitted'           => $permitted,
            'reason'              => $reason,
            'occurred_at'         => now(),
        ]);

        $this->audit->record(
            AuditEventType::AgentResourceRetrieved,
            $run->circle,
            ActorType::Agent,
            $run->agent_instance_id,
            'evidence_item',
            $item->id,
            $versionId,
            ['permitted' => $permitted, 'reason' => $reason, 'agent_run_id' => $run->id],
        );
    }
}
