<?php

namespace App\Services\Exports;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Circle;
use App\Models\Claim;
use App\Models\Commitment;
use App\Models\Decision;
use App\Models\DerivedArtifact;
use App\Models\EvidenceItem;
use App\Models\Export;
use App\Services\Audit\AuditChain;
use App\Services\Evidence\EvidenceStorage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds the Circle mission packet (spec §16).
 *
 * The packet contains the *record*, not the binaries: an evidence manifest with
 * SHA-256 for every version, so a holder of the originals can prove they match
 * what the Circle recorded, without the export becoming a multi-gigabyte copy of
 * the vault.
 *
 * The audit chain is verified at export time and the result is written into the
 * packet — an export that cannot vouch for its own audit trail says so.
 */
class ExportBuilder
{
    public function __construct(
        private readonly AuditChain $audit,
        private readonly EvidenceStorage $storage,
    ) {}

    public function build(Export $export): Export
    {
        $circle = $export->circle;

        $export->forceFill(['status' => 'building'])->save();

        $zipPath = sys_get_temp_dir() . '/circle-export-' . Str::lower((string) Str::ulid()) . '.zip';

        try {
            $verification = $this->audit->verify($circle->id);

            $documents = [
                'circle.json'        => $this->circleDocument($circle),
                'participants.json'  => $this->participants($circle),
                'evidence.json'      => $this->evidenceManifest($circle),
                'claims.json'        => $this->claims($circle),
                'decisions.json'     => $this->decisions($circle),
                'commitments.json'   => $this->commitments($circle),
                'agent_runs.json'    => $this->agentRuns($circle),
                'derived.json'       => $this->derivedArtifacts($circle),
                'audit_events.json'  => $this->auditEvents($circle, $verification),
            ];

            $zip = new ZipArchive();

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the export archive.');
            }

            $manifest = [
                'circle_id'          => $circle->id,
                'circle_name'        => $circle->name,
                'generated_at'       => now()->toISOString(),
                'generated_by'       => $export->requested_by_user_id,
                'audit_chain'        => $verification->toArray(),
                'files'              => [],
                'format_version'     => '1.0',
                'contains_originals' => false,
                'note' => 'This packet contains the Circle record and an evidence manifest with SHA-256 '
                    . 'digests. Original binaries remain in the evidence vault; the digests here let a '
                    . 'holder of those originals prove they are the same bytes this Circle recorded.',
            ];

            foreach ($documents as $name => $payload) {
                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $zip->addFromString($name, $json);

                $manifest['files'][$name] = [
                    'sha256' => hash('sha256', $json),
                    'bytes'  => strlen($json),
                ];
            }

            // Written last so it can describe every other file in the packet.
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('README.txt', $this->readme($circle, $verification->valid));
            $zip->close();

            $key = $this->storage->exportKey($circle, $export);
            $this->storage->putFile($key, $zipPath, 'application/zip');

            $export->forceFill([
                'status'            => 'ready',
                'storage_key'       => $key,
                'sha256'            => hash_file('sha256', $zipPath),
                'byte_size'         => filesize($zipPath) ?: null,
                'manifest_json'     => $manifest,
                'audit_chain_valid' => $verification->valid,
                'completed_at'      => now(),
            ])->save();

            $this->audit->record(
                AuditEventType::ExportCreated, $circle, ActorType::User, $export->requested_by_user_id,
                'export', $export->id, metadata: [
                    'sha256'            => $export->sha256,
                    'byte_size'         => $export->byte_size,
                    'audit_chain_valid' => $verification->valid,
                    'events_checked'    => $verification->eventsChecked,
                ],
            );

            return $export;
        } catch (\Throwable $e) {
            $export->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();

            throw $e;
        } finally {
            @unlink($zipPath);
        }
    }

    private function circleDocument(Circle $circle): array
    {
        return [
            'id'           => $circle->id,
            'name'         => $circle->name,
            'purpose'      => $circle->purpose,
            'status'       => $circle->status->value,
            'progress'     => (int) $circle->progress,
            'organisation' => ['id' => $circle->organisation_id, 'name' => $circle->organisation->name],
            'owner'        => ['id' => $circle->owner_user_id, 'name' => $circle->owner?->name],
            'starts_at'    => $circle->starts_at?->toISOString(),
            'expires_at'   => $circle->expires_at?->toISOString(),
            'closed_at'    => $circle->closed_at?->toISOString(),
            'created_at'   => $circle->created_at?->toISOString(),
        ];
    }

    private function participants(Circle $circle): array
    {
        return $circle->memberships()->with('user')->get()->map(fn ($m) => [
            'user_id'       => $m->user_id,
            'name'          => $m->user?->name,
            'email'         => $m->user?->email,
            'circle_role'   => $m->circle_role->value,
            'is_external'   => $m->is_external,
            'invite_status' => $m->invite_status,
            'joined_at'     => $m->created_at?->toISOString(),
            'expires_at'    => $m->expires_at?->toISOString(),
            'revoked_at'    => $m->revoked_at?->toISOString(),
        ])->all();
    }

    private function evidenceManifest(Circle $circle): array
    {
        $items = EvidenceItem::query()
            ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
            ->with(['resource', 'versions.createdBy', 'uploader'])
            ->get();

        return $items->map(fn (EvidenceItem $item) => [
            'evidence_item_id' => $item->id,
            'name'             => $item->resource->name,
            'origin_status'    => $item->origin_status->value,
            'integrity_status' => $item->integrity_status->value,
            'review_status'    => $item->review_status->value,
            'classification'   => $item->classification->value,
            'agent_readable'   => $item->agent_read,
            'source_label'     => $item->source_label,
            'source_url'       => $item->source_url,
            'uploaded_by'      => ['id' => $item->uploader_user_id, 'name' => $item->uploader?->name],
            'versions'         => $item->versions->map(fn ($v) => [
                'evidence_version_id'   => $v->id,
                'version_number'        => $v->version_number,
                'original_filename'     => $v->original_filename,
                'mime_type'             => $v->mime_type,
                'byte_size'             => $v->byte_size,
                'sha256'                => $v->sha256,
                'integrity'             => $v->integrityStatus()->value,
                'storage_key'           => $v->storage_key,
                'supersedes_version_id' => $v->supersedes_version_id,
                'created_by'            => $v->createdBy?->name,
                'created_at'            => $v->created_at?->toISOString(),
                'processing_status'     => $v->processing_status->value,
            ])->all(),
        ])->all();
    }

    private function claims(Circle $circle): array
    {
        return Claim::where('circle_id', $circle->id)
            ->with(['citations', 'reviews.reviewer', 'author'])
            ->get()
            ->map(fn (Claim $c) => [
                'claim_id'    => $c->id,
                'statement'   => $c->statement,
                'claim_type'  => $c->claim_type->value,
                'status'      => $c->status->value,
                'confidence'  => $c->confidence,
                'author_type' => $c->author_type,
                'author'      => $c->author_type === 'agent'
                    ? ['agent_instance_id' => $c->author_id, 'agent_run_id' => $c->agent_run_id]
                    : ['user_id' => $c->author_id, 'name' => $c->author?->name],
                'created_at'  => $c->created_at?->toISOString(),
                'citations'   => $c->citations->map(fn ($cit) => [
                    'evidence_version_id' => $cit->evidence_version_id,
                    'citation_type'       => $cit->citation_type->value,
                    'locator'             => $cit->locator_json,
                    'excerpt'             => $cit->excerpt,
                ])->all(),
                'reviews'     => $c->reviews->map(fn ($r) => [
                    'reviewer' => $r->reviewer?->name,
                    'outcome'  => $r->outcome,
                    'comment'  => $r->comment,
                    'at'       => $r->created_at?->toISOString(),
                ])->all(),
            ])->all();
    }

    private function decisions(Circle $circle): array
    {
        return Decision::where('circle_id', $circle->id)
            ->with(['approvals.actor', 'approver', 'createdBy'])
            ->get()
            ->map(fn (Decision $d) => [
                'decision_id'        => $d->id,
                'title'              => $d->title,
                'description'        => $d->description,
                'status'             => $d->status->value,
                'created_by'         => $d->createdBy?->name,
                'approver'           => $d->approver?->name,
                'subject'            => [
                    'type'    => $d->subject_type,
                    'id'      => $d->subject_id,
                    'version' => $d->subject_version,
                ],
                'expires_at'         => $d->expires_at?->toISOString(),
                'resolved_at'        => $d->resolved_at?->toISOString(),
                'resolution_comment' => $d->resolution_comment,
                'agent_run_id'       => $d->agent_run_id,
                // The immutable resolution history, in order.
                'approval_history'   => $d->approvals->map(fn ($a) => [
                    'actor'           => $a->actor?->name,
                    'actor_user_id'   => $a->actor_user_id,
                    'outcome'         => $a->outcome,
                    'subject_version' => $a->subject_version,
                    'comment'         => $a->comment,
                    'occurred_at'     => $a->occurred_at?->toISOString(),
                ])->all(),
            ])->all();
    }

    private function commitments(Circle $circle): array
    {
        return Commitment::where('circle_id', $circle->id)
            ->with(['owner', 'updates'])
            ->get()
            ->map(fn (Commitment $c) => [
                'commitment_id'        => $c->id,
                'title'                => $c->title,
                'description'          => $c->description,
                'acceptance_condition' => $c->acceptance_condition,
                'status'               => $c->status->value,
                'owner'                => $c->owner?->name,
                'due_at'               => $c->due_at?->toISOString(),
                'completed_at'         => $c->completed_at?->toISOString(),
                'created_by_type'      => $c->created_by_type,
                'updates'              => $c->updates->map(fn ($u) => [
                    'from'  => $u->from_status,
                    'to'    => $u->to_status,
                    'note'  => $u->note,
                    'at'    => $u->created_at?->toISOString(),
                ])->all(),
            ])->all();
    }

    private function agentRuns(Circle $circle): array
    {
        return AgentRun::where('circle_id', $circle->id)
            ->with(['resourceAccesses', 'instance.blueprint'])
            ->get()
            ->map(fn (AgentRun $r) => [
                'agent_run_id'       => $r->id,
                'run_type'           => $r->run_type,
                'status'             => $r->status,
                'blueprint'          => $r->instance?->blueprint?->key,
                'blueprint_version'  => $r->instance?->blueprint?->version,
                'model_provider'     => $r->model_provider,
                'model_name'         => $r->model_name,
                'prompt_version'     => $r->prompt_version,
                'triggered_by'       => $r->triggered_by_user_id,
                'started_at'         => $r->started_at?->toISOString(),
                'finished_at'        => $r->finished_at?->toISOString(),
                'tokens'             => ['input' => $r->input_tokens, 'output' => $r->output_tokens],
                'retrieval_manifest' => $r->retrieval_manifest_json,
                'error'              => $r->error,
                // Exactly what the agent read, and what it was refused.
                'resource_accesses'  => $r->resourceAccesses->map(fn ($a) => [
                    'resource_id'         => $a->resource_id,
                    'evidence_version_id' => $a->evidence_version_id,
                    'permitted'           => $a->permitted,
                    'reason'              => $a->reason,
                    'occurred_at'         => $a->occurred_at?->toISOString(),
                ])->all(),
            ])->all();
    }

    private function derivedArtifacts(Circle $circle): array
    {
        return DerivedArtifact::where('circle_id', $circle->id)
            ->get()
            ->map(fn (DerivedArtifact $a) => [
                'derived_artifact_id' => $a->id,
                'artifact_type'       => $a->artifact_type->value,
                'parent'              => ['type' => $a->parent_resource_type, 'id' => $a->parent_resource_id],
                'model_provider'      => $a->model_provider,
                'model_name'          => $a->model_name,
                'prompt_version'      => $a->prompt_version,
                'agent_run_id'        => $a->agent_run_id,
                'source_manifest'     => $a->source_manifest_json,
                'storage_key'         => $a->storage_key,
                'status'              => $a->status,
                'created_at'          => $a->created_at?->toISOString(),
                // Content of summaries is included; bulk extracted text is not,
                // to keep the packet readable.
                'content'             => $a->artifact_type->value === 'agent_summary' ? $a->content_json : null,
            ])->all();
    }

    private function auditEvents(Circle $circle, \App\Services\Audit\ChainVerification $verification): array
    {
        $events = AuditEvent::where('circle_id', $circle->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (AuditEvent $e) => [
                'sequence'         => $e->sequence,
                'id'               => $e->id,
                'event_type'       => $e->event_type->value,
                'actor_type'       => $e->actor_type->value,
                'actor_id'         => $e->actor_id,
                'resource_type'    => $e->resource_type,
                'resource_id'      => $e->resource_id,
                'resource_version' => $e->resource_version,
                'metadata'         => $e->metadata_json,
                'occurred_at'      => $e->occurred_at?->toISOString(),
                'previous_hash'    => $e->previous_hash,
                'event_hash'       => $e->event_hash,
            ])->all();

        return [
            'verification' => $verification->toArray(),
            'algorithm'    => 'event_hash = SHA256(canonical_json(event_without_event_hash) + previous_hash)',
            'canonical_json' => 'UTF-8 JSON with recursively sorted object keys, unescaped slashes and unicode.',
            'genesis'      => AuditChain::GENESIS,
            'events'       => $events,
        ];
    }

    private function readme(Circle $circle, bool $chainValid): string
    {
        $status = $chainValid
            ? 'VALID - every event hash recomputed correctly and every link matched.'
            : 'BROKEN - see audit_events.json -> verification for the first break.';

        return <<<TEXT
        Circle mission packet
        =====================

        Circle:     {$circle->name}
        Purpose:    {$circle->purpose}
        Exported:   {$this->now()}
        Audit chain: {$status}

        Contents
        --------
        manifest.json       Digest of every file in this packet.
        circle.json         Mission metadata.
        participants.json   Who was involved, their role, and when access ended.
        evidence.json       Every evidence item and version, with SHA-256 digests.
        claims.json         Claims, their citations, and their review history.
        decisions.json      Decisions and the immutable approval history.
        commitments.json    Commitments and their status changes.
        agent_runs.json     Every agent run, what it read, and what it was refused.
        derived.json        Machine-generated artifacts and their provenance.
        audit_events.json   The full audit chain plus its verification result.

        About the evidence
        ------------------
        This packet does not contain the original files. It contains their
        SHA-256 digests. Anyone holding the originals can recompute those digests
        and confirm the files are the exact bytes this Circle recorded.

        About derived content
        ---------------------
        Anything marked derived - OCR, transcripts, agent summaries and agent
        claims - is machine-generated. It records which model and prompt version
        produced it. It is not a verification of the underlying evidence.

        About the audit chain
        ---------------------
        Each event hashes its own contents together with the previous event's
        hash. Removing, reordering or editing an event breaks every link after
        it. This is tamper evidence for this application's own record; it is not
        an independently anchored ledger.
        TEXT;
    }

    private function now(): string
    {
        return now()->toDayDateTimeString() . ' UTC';
    }
}
