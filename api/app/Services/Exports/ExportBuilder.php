<?php

namespace App\Services\Exports;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Circle;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\Claim;
use App\Models\Comment;
use App\Models\CommentThread;
use App\Models\Commitment;
use App\Models\Decision;
use App\Models\DerivedArtifact;
use App\Models\EvidenceItem;
use App\Models\EvidenceVersion;
use App\Models\Export;
use App\Models\Goal;
use App\Models\GoalScheduleChange;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Evidence\EvidenceStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds the Circle mission packet (spec §16, amended by §20).
 *
 * The packet is the endpoint of the product: the thing a party walks away with
 * when the work is over, readable by someone who has never seen this software.
 * It carries the originals where they fit, and the digests always — so a holder
 * of the files can prove they are the bytes the Circle recorded even for the
 * ones too large to travel.
 *
 * Three things the packet gets from §20 that the MVP could not express: the
 * goal tree with every movement of a due date and who agreed to it, the
 * on-record conversation, and the agent execution ledger. A packet that shows
 * decisions without showing what was said before them, or what software did on
 * whose authority, is not the record anyone is arguing about.
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

        /** @var list<string> Local copies of originals; ZipArchive reads these at close(). */
        $tempFiles = [];

        try {
            $verification = $this->audit->verify($circle->id);

            $zip = new ZipArchive();

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the export archive.');
            }

            // Originals go in first so evidence.json can say, per version,
            // whether the file travelled with the packet and where it landed.
            $items     = $this->evidenceItems($circle);
            $originals = $this->packOriginals($zip, $items, $tempFiles, ...$this->readerScope($export, $circle));

            $documents = [
                'circle.json'        => $this->circleDocument($circle),
                'participants.json'  => $this->participants($circle),
                'goals.json'         => $this->goals($circle),
                'evidence.json'      => $this->evidenceManifest($items, $originals),
                'claims.json'        => $this->claims($circle),
                'decisions.json'     => $this->decisions($circle),
                'commitments.json'   => $this->commitments($circle),
                'threads.json'       => $this->threads($circle),
                'agent_runs.json'    => $this->agentRuns($circle),
                'agent_actions.json' => $this->agentActions($circle),
                'derived.json'       => $this->derivedArtifacts($circle),
                'audit_events.json'  => $this->auditEvents($circle, $verification),
            ];

            $manifest = [
                'circle_id'          => $circle->id,
                'circle_name'        => $circle->name,
                'generated_at'       => now()->toISOString(),
                'generated_by'       => $export->requested_by_user_id,
                'audit_chain'        => $verification->toArray(),
                'files'              => [],
                'format_version'     => '2.0',
                'contains_originals' => $originals['included_count'] > 0,
                'originals'          => $originals['summary'],
                'note'               => $this->manifestNote($originals),
            ];

            foreach ($documents as $name => $payload) {
                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $zip->addFromString($name, $json);

                $manifest['files'][$name] = [
                    'sha256' => hash('sha256', $json),
                    'bytes'  => strlen($json),
                ];
            }

            // Digests of the originals as they sit in this packet, alongside the
            // digest the Circle recorded at upload. The two matching is the
            // whole evidentiary claim; where they differ the packet says so.
            foreach ($originals['files'] as $path => $file) {
                $manifest['files'][$path] = $file;
            }

            // Written last so it can describe every other file in the packet.
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('README.txt', $this->readme($circle, $verification->valid, $originals));
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
                    'sha256'             => $export->sha256,
                    'byte_size'          => $export->byte_size,
                    'audit_chain_valid'  => $verification->valid,
                    'events_checked'     => $verification->eventsChecked,
                    'originals_included' => $originals['included_count'],
                    'originals_omitted'  => $originals['omitted_count'],
                ],
            );

            return $export;
        } catch (\Throwable $e) {
            $export->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();

            throw $e;
        } finally {
            @unlink($zipPath);

            foreach ($tempFiles as $temp) {
                @unlink($temp);
            }
        }
    }

    // ------------------------------------------------------------- originals

    /**
     * Streams the original binaries into the packet at evidence/{item}/v{n}-{file}.
     *
     * Copied through local temp files rather than read into strings, because a
     * site video is routinely larger than the PHP memory limit. ZipArchive does
     * not read an added file until close(), so the temps outlive this method and
     * are cleaned up by the caller.
     *
     * Current versions are packed before superseded ones: when the cap bites it
     * should drop history before it drops the evidence the Circle is currently
     * standing on. A single oversized file is skipped rather than ending the
     * run, so the smaller files behind it still travel.
     *
     * @param  Collection<int, EvidenceItem>  $items
     * @param  list<string>  $tempFiles
     * @return array{summary: array, files: array<string, array>, included: array<string, array>,
     *               included_count: int, omitted_count: int, omitted: list<array>}
     */
    private function packOriginals(
        ZipArchive $zip,
        Collection $items,
        array &$tempFiles,
        ?string $readerPartyId = null,
        ?string $convenerPartyId = null,
    ): array {
        $cap      = max(0, (int) config('circle.export.max_original_bytes'));
        $used     = 0;
        $files    = [];
        $included = [];
        $omitted  = [];

        foreach ($this->versionsInPackingOrder($items) as [$item, $version]) {
            /** @var EvidenceItem $item */
            /** @var EvidenceVersion $version */
            $path = sprintf(
                'evidence/%s/v%d-%s',
                $item->id,
                $version->version_number,
                $this->storage->sanitiseFilename($version->original_filename ?: 'file'),
            );

            $record = [
                'evidence_item_id'    => $item->id,
                'item_name'           => $item->resource?->name,
                'evidence_version_id' => $version->id,
                'version_number'      => $version->version_number,
                'original_filename'   => $version->original_filename,
                'byte_size'           => $version->byte_size,
                'recorded_sha256'     => $version->sha256,
                'path'                => $path,
            ];

            // Checked before the size cap and before the vault, because it is
            // the only reason on this list that is about who asked. An export
            // is requestable by any approver, and in a Circle spanning several
            // companies that is not always the convener — so the packet cannot
            // assume its requester may hold every party's files.
            if (! $item->isVisibleToParty($readerPartyId, $convenerPartyId)) {
                $omitted[] = $record + [
                    'reason' => 'party_restricted',
                    'detail' => 'This item is scoped to a party the requester of this export is not in. '
                        . 'The record of it stays in evidence.json — a packet that silently dropped rows '
                        . 'would not be this Circle’s record — but the file itself did not travel.',
                ];

                continue;
            }

            if ($cap === 0) {
                $omitted[] = $record + [
                    'reason' => 'originals_disabled',
                    'detail' => 'This deployment is configured to export the record only. No original '
                        . 'files are included; the SHA-256 digests in evidence.json still identify them.',
                ];

                continue;
            }

            if ($version->storage_key === null || ! $this->storage->exists($version->storage_key)) {
                $omitted[] = $record + [
                    'reason' => 'missing_from_vault',
                    'detail' => 'The Circle holds a record of this version but the file itself is not in '
                        . 'the evidence vault, so it could not be placed in this packet. Its recorded '
                        . 'SHA-256 is still in evidence.json.',
                ];

                continue;
            }

            $size = $this->storage->size($version->storage_key) ?? (int) $version->byte_size;

            if ($used + $size > $cap) {
                $omitted[] = $record + [
                    'reason' => 'size_cap',
                    'detail' => sprintf(
                        'Including this file (%s) would take the packet past its %s limit on original '
                        . 'files. It was left out; its SHA-256 in evidence.json still identifies it, and '
                        . 'it can be requested separately.',
                        $this->humanBytes($size),
                        $this->humanBytes($cap),
                    ),
                ];

                continue;
            }

            $local = $this->storage->pullToTempFile($version->storage_key);

            if ($local === null) {
                $omitted[] = $record + [
                    'reason' => 'unreadable',
                    'detail' => 'The evidence vault refused to read this object while the packet was '
                        . 'being built. Its recorded SHA-256 is still in evidence.json.',
                ];

                continue;
            }

            $tempFiles[] = $local;
            $zip->addFile($local, $path);

            $actualBytes  = filesize($local) ?: 0;
            $actualSha256 = hash_file('sha256', $local) ?: null;
            $used        += $actualBytes;

            $files[$path] = [
                'sha256'          => $actualSha256,
                'bytes'           => $actualBytes,
                'recorded_sha256' => $version->sha256,
                // The evidentiary claim, stated per file rather than left to
                // the reader to work out.
                'matches_record'  => $version->sha256 !== null && $actualSha256 !== null
                    && hash_equals($version->sha256, $actualSha256),
            ];

            $included[$version->id] = $record + ['sha256' => $actualSha256, 'bytes' => $actualBytes];
        }

        return [
            'files'          => $files,
            'included'       => $included,
            'included_count' => count($included),
            'omitted_count'  => count($omitted),
            'omitted'        => $omitted,
            'summary'        => [
                'policy' => 'Original files are placed at evidence/{evidence_item_id}/v{version}-{filename}. '
                    . 'Current versions are packed before superseded ones, so where the size limit bites it '
                    . 'drops superseded history rather than the evidence in force. Every file left out is '
                    . 'listed below with the reason.',
                'byte_cap'       => $cap,
                'byte_cap_human' => $cap === 0 ? 'originals disabled' : $this->humanBytes($cap),
                'bytes_included' => $used,
                'included_count' => count($included),
                'omitted_count'  => count($omitted),
                'omitted'        => $omitted,
            ],
        ];
    }

    /**
     * Every version paired with its item, current versions first.
     *
     * @param  Collection<int, EvidenceItem>  $items
     * @return list<array{0: EvidenceItem, 1: EvidenceVersion}>
     */
    private function versionsInPackingOrder(Collection $items): array
    {
        $current = [];
        $history = [];

        foreach ($items as $item) {
            foreach ($item->versions->sortByDesc('version_number')->values() as $index => $version) {
                if ($index === 0) {
                    $current[] = [$item, $version];
                } else {
                    $history[] = [$item, $version];
                }
            }
        }

        return array_merge($current, $history);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1) . ' ' . $units[$power];
    }

    private function manifestNote(array $originals): string
    {
        if ($originals['included_count'] === 0) {
            return 'This packet contains the Circle record and an evidence manifest with SHA-256 digests. '
                . 'No original files travelled with it — see originals.omitted for the reason in each case. '
                . 'A holder of the originals can recompute the digests here and prove they are the same '
                . 'bytes this Circle recorded.';
        }

        $note = sprintf(
            'This packet contains the Circle record and %d original file(s) under evidence/, each with the '
            . 'SHA-256 of the bytes as shipped and the SHA-256 the Circle recorded at upload. Where the two '
            . 'match, the file is provably the one the Circle worked from.',
            $originals['included_count'],
        );

        if ($originals['omitted_count'] > 0) {
            $note .= sprintf(
                ' %d file(s) were left out; every one is named in originals.omitted with the reason. Their '
                . 'digests are still in evidence.json, so they remain identifiable.',
                $originals['omitted_count'],
            );
        }

        return $note;
    }

    // ------------------------------------------------------------- documents

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
            // What the mission said it was, at each point somebody restated
            // it. Without this the packet shows one wording and every claim,
            // decision and commitment under it reads as having been made
            // against that wording — which for a renamed Circle is false.
            'revisions'    => $this->missionRevisions($circle),
        ];
    }

    /**
     * The mission statement's history, drawn straight from the chain.
     *
     * Each entry carries the hash of the audit event it came from, so a reader
     * holding the packet can find that event in audit_events.json and confirm
     * the wording here is the wording that was recorded — the revision list is
     * a convenience, and it must not become a second, unverifiable copy of the
     * record. The first entry is the Circle as opened.
     *
     * @return list<array<string, mixed>>
     */
    private function missionRevisions(Circle $circle): array
    {
        $events = AuditEvent::where('circle_id', $circle->id)
            ->whereIn('event_type', [
                AuditEventType::CircleCreated->value,
                AuditEventType::CircleDetailsChanged->value,
            ])
            ->orderBy('sequence')
            ->get();

        return $events->map(function (AuditEvent $event) {
            $meta = $event->metadata_json ?? [];

            return [
                'at'         => $event->occurred_at?->toISOString(),
                'actor_id'   => $event->actor_id,
                'event'      => $event->event_type->value,
                'event_id'   => $event->id,
                'event_hash' => $event->event_hash,
                'reason'     => $meta['reason'] ?? null,
                'changes'    => $event->event_type === AuditEventType::CircleCreated
                    ? [
                        'name'    => ['from' => null, 'to' => $meta['name'] ?? null],
                        'purpose' => ['from' => null, 'to' => $meta['purpose'] ?? null],
                    ]
                    : ($meta['changes'] ?? []),
            ];
        })->all();
    }

    /**
     * The people, and the organisations they answered for.
     *
     * A party role is a commercial position rather than a permission set, and it
     * is the half of this document that still means something years later:
     * "contractor" carries weight in a record, "external" does not.
     */
    private function participants(Circle $circle): array
    {
        return [
            'parties' => $circle->parties()->with('organisation')->get()->map(fn ($p) => [
                'party_id'           => $p->id,
                'name'               => $p->label(),
                'organisation_id'    => $p->organisation_id,
                'party_role'         => $p->party_role->value,
                'is_convener'        => (bool) $p->is_convener,
                'status'             => $p->status->value,
                'external_reference' => $p->external_reference,
                'joined_at'          => $p->joined_at?->toISOString(),
                'withdrawn_at'       => $p->withdrawn_at?->toISOString(),
            ])->all(),
            'people' => $circle->memberships()->with(['user', 'party.organisation'])->get()->map(fn ($m) => [
                'user_id'       => $m->user_id,
                'name'          => $m->user?->name,
                'email'         => $m->user?->email,
                'party_id'      => $m->circle_party_id,
                'party'         => $m->party?->label(),
                'party_role'    => $m->party?->party_role->value,
                'circle_role'   => $m->circle_role->value,
                'is_external'   => $m->isExternal(),
                'invite_status' => $m->invite_status,
                'joined_at'     => $m->created_at?->toISOString(),
                'expires_at'    => $m->expires_at?->toISOString(),
                'revoked_at'    => $m->revoked_at?->toISOString(),
            ])->all(),
        ];
    }

    /**
     * The goal tree, and every movement of a due date.
     *
     * Schedule changes appear twice on purpose: on the goal they belong to,
     * where the history of that piece of work reads in one place, and again as
     * one date-ordered list, because "what slipped, when, and who agreed" is the
     * question that table exists to answer and it should not require walking a
     * tree to get at. Both views render the same rows.
     */
    private function goals(Circle $circle): array
    {
        $goals = Goal::where('circle_id', $circle->id)
            ->with([
                'owner', 'acceptedBy', 'responsibleParty.organisation',
                'scheduleChanges.changedBy', 'scheduleChanges.requiresParty.organisation',
                'scheduleChanges.agreedBy',
            ])
            ->orderBy('position')
            ->get();

        // Children are hydrated from memory so effectiveProgress(), which
        // recurses the whole subtree, does not fire a query per node.
        $byParent = $goals->groupBy('parent_goal_id');

        foreach ($goals as $goal) {
            $goal->setRelation('children', ($byParent[$goal->id] ?? collect())->sortBy('position')->values());
        }

        $roots = $goals->whereNull('parent_goal_id')->sortBy('position')->values();

        $attached = [
            'commitments' => Commitment::where('circle_id', $circle->id)->whereNotNull('goal_id')
                ->get(['id', 'goal_id'])->groupBy('goal_id'),
            'decisions'   => Decision::where('circle_id', $circle->id)->whereNotNull('goal_id')
                ->get(['id', 'goal_id'])->groupBy('goal_id'),
            'claims'      => Claim::where('circle_id', $circle->id)->whereNotNull('goal_id')
                ->get(['id', 'goal_id'])->groupBy('goal_id'),
        ];

        $changes = GoalScheduleChange::where('circle_id', $circle->id)
            ->with(['goal', 'changedBy', 'requiresParty.organisation', 'agreedBy'])
            ->orderBy('created_at')
            ->get();

        return [
            'tree' => $roots->map(fn (Goal $g) => $this->goalNode($g, $attached))->all(),
            'goal_schedule_changes' => $changes->map(fn (GoalScheduleChange $c) => [
                'goal_id'    => $c->goal_id,
                'goal_title' => $c->goal?->title,
            ] + $this->scheduleChange($c))->all(),
            'note' => 'Reported progress is a figure a person asserted. Derived progress ignores a parent '
                . 'goal\'s own figure and averages its children, so a parent cannot read 80% over sub-goals '
                . 'at 20%. Acceptance is separate from completion: an owner marking their own work done is '
                . 'a claim about it, and only accepted_by settles it. Every schedule change listed here '
                . 'also appears on its goal in the tree above — the same rows, shown twice.',
        ];
    }

    /** @param array<string, Collection> $attached */
    private function goalNode(Goal $goal, array $attached): array
    {
        return [
            'goal_id'              => $goal->id,
            'parent_goal_id'       => $goal->parent_goal_id,
            'title'                => $goal->title,
            'description'          => $goal->description,
            'status'               => $goal->status->value,
            'owner'                => ['user_id' => $goal->owner_user_id, 'name' => $goal->owner?->name],
            // The organisation answerable for this node. People leave projects;
            // the company still owes the deliverable.
            'responsible_party'    => $goal->responsibleParty === null ? null : [
                'party_id'   => $goal->responsible_party_id,
                'name'       => $goal->responsibleParty->label(),
                'party_role' => $goal->responsibleParty->party_role->value,
            ],
            'acceptance_condition'     => $goal->acceptance_condition,
            'accepted'                 => $goal->isAccepted(),
            'accepted_by'              => $goal->acceptedBy?->name,
            'accepted_at'              => $goal->accepted_at?->toISOString(),
            'accepted_via_decision_id' => $goal->accepted_via_decision_id,
            'starts_at'                => $goal->starts_at?->toISOString(),
            'due_at'                   => $goal->due_at?->toISOString(),
            'overdue'                  => $goal->isOverdue(),
            'reported_progress'        => (int) $goal->progress,
            'derived_progress'         => $goal->effectiveProgress(),
            'progress_set_by'          => $goal->progress_set_by_user_id,
            'progress_set_at'          => $goal->progress_set_at?->toISOString(),
            'created_by'               => ['type' => $goal->created_by_type, 'id' => $goal->created_by_id],
            'created_at'               => $goal->created_at?->toISOString(),
            'commitment_ids'           => ($attached['commitments'][$goal->id] ?? collect())->pluck('id')->all(),
            'decision_ids'             => ($attached['decisions'][$goal->id] ?? collect())->pluck('id')->all(),
            'claim_ids'                => ($attached['claims'][$goal->id] ?? collect())->pluck('id')->all(),
            'schedule_changes'         => $goal->scheduleChanges
                ->sortBy('created_at')
                ->map(fn (GoalScheduleChange $c) => $this->scheduleChange($c))
                ->values()->all(),
            'sub_goals'                => $goal->children->map(fn (Goal $g) => $this->goalNode($g, $attached))->all(),
        ];
    }

    private function scheduleChange(GoalScheduleChange $change): array
    {
        return [
            'schedule_change_id' => $change->id,
            'from_due_at'        => $change->from_due_at?->toISOString(),
            'to_due_at'          => $change->to_due_at?->toISOString(),
            'days_moved'         => $change->daysMoved(),
            'reason'             => $change->reason,
            'changed_by'         => $change->changedBy?->name,
            'changed_at'         => $change->created_at?->toISOString(),
            // A move that touched another party's position had to be agreed by
            // that party. Recorded here whether or not it ever was.
            'required_agreement_from' => $change->requiresParty?->label(),
            'agreed_by'               => $change->agreedBy?->name,
            'agreed_at'               => $change->agreed_at?->toISOString(),
            'awaiting_agreement'      => $change->isAwaitingAgreement(),
        ];
    }

    /** @return Collection<int, EvidenceItem> */
    private function evidenceItems(Circle $circle): Collection
    {
        return EvidenceItem::query()
            ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
            ->with(['resource', 'versions.createdBy', 'uploader', 'restrictedToParty.organisation'])
            ->get();
    }

    /**
     * The party the person who asked for this packet reads as.
     *
     * @return array{0: ?string, 1: ?string} their party, and the convening party
     */
    private function readerScope(Export $export, Circle $circle): array
    {
        $membership = CircleMembership::query()
            ->where('circle_id', $circle->id)
            ->where('user_id', $export->requested_by_user_id)
            ->first();

        return [$membership?->effectivePartyId(), CircleParty::convenerIdFor($circle->id)];
    }

    /** @param Collection<int, EvidenceItem> $items */
    private function evidenceManifest(Collection $items, array $originals): array
    {
        $omittedByVersion = collect($originals['omitted'])->keyBy('evidence_version_id');

        return $items->map(fn (EvidenceItem $item) => [
            'evidence_item_id' => $item->id,
            'name'             => $item->resource->name,
            'origin_status'    => $item->origin_status->value,
            'integrity_status' => $item->integrity_status->value,
            'review_status'    => $item->review_status->value,
            'classification'   => $item->classification->value,
            // Named, the same way a party-scoped thread is named in
            // threads.json: the reader should know whose material this was.
            'restricted_to_party' => $item->restrictedToParty?->label(),
            'agent_readable'   => $item->agent_read,
            'source_label'     => $item->source_label,
            'source_url'       => $item->source_url,
            'uploaded_by'      => ['id' => $item->uploader_user_id, 'name' => $item->uploader?->name],
            'versions'         => $item->versions->map(function ($v) use ($originals, $omittedByVersion) {
                $packed  = $originals['included'][$v->id] ?? null;
                $omitted = $omittedByVersion[$v->id] ?? null;

                return [
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
                    // Where the file itself is in this packet, or why it is not.
                    'original_in_packet'    => $packed !== null,
                    'packet_path'           => $packed['path'] ?? null,
                    'omitted_reason'        => $omitted['reason'] ?? null,
                    'omitted_detail'        => $omitted['detail'] ?? null,
                ];
            })->all(),
        ])->all();
    }

    private function claims(Circle $circle): array
    {
        return Claim::where('circle_id', $circle->id)
            ->with(['citations', 'reviews.reviewer', 'author'])
            ->get()
            ->map(fn (Claim $c) => [
                'claim_id'    => $c->id,
                'goal_id'     => $c->goal_id,
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
                'goal_id'            => $d->goal_id,
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
            ->with(['owner', 'updates', 'ownerParty.organisation'])
            ->get()
            ->map(fn (Commitment $c) => [
                'commitment_id'        => $c->id,
                'goal_id'              => $c->goal_id,
                'title'                => $c->title,
                'description'          => $c->description,
                'acceptance_condition' => $c->acceptance_condition,
                'status'               => $c->status->value,
                'owner'                => $c->owner?->name,
                'owner_party'          => $c->ownerParty?->label(),
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

    /**
     * The on-record conversation.
     *
     * Inclusion is per comment, not per thread (spec §20.3). A comment that
     * carried a state change is part of the decision and is always here. Plain
     * discussion is out unless its author marked it for the record — if every
     * aside were discoverable in a dispute, people would stop speaking candidly
     * and the feature would be worth nothing.
     *
     * What is withheld is counted rather than hidden. A packet that silently
     * dropped half a conversation would be worse than one that says plainly how
     * much it is not showing.
     */
    private function threads(Circle $circle): array
    {
        $threads = CommentThread::where('circle_id', $circle->id)
            ->with([
                'comments.authorParty.organisation', 'comments.mentions.user',
                'visibleToParty.organisation', 'resolvedBy', 'attachedBy',
            ])
            ->orderBy('created_at')
            ->get();

        $authors  = $this->commentAuthorNames($threads);
        $subjects = $this->subjectLabels($threads);

        $commentsTotal = 0;
        $onRecordTotal = 0;
        $included      = [];

        foreach ($threads as $thread) {
            $onRecord = $thread->comments->filter(fn (Comment $c) => $c->isOnRecord());

            $commentsTotal += $thread->comments->count();
            $onRecordTotal += $onRecord->count();

            if ($onRecord->isEmpty()) {
                continue;
            }

            $included[] = [
                'thread_id' => $thread->id,
                'subject'   => [
                    'type'  => $thread->subject_type,
                    'id'    => $thread->subject_id,
                    // A general discussion names itself. Every one of them
                    // shares the Circle as its subject, so a label looked up by
                    // subject id would give them all the same name.
                    'label' => $thread->title
                        ?? $subjects[$thread->subject_type][$thread->subject_id]
                        ?? null,
                ],
                // Where it began, where it did not begin here. A decision whose
                // conversation started as a question nobody had filed yet is a
                // true thing about how the decision was reached.
                'attached_from_general' => $thread->wasAttached() ? [
                    'at' => $thread->attached_at?->toISOString(),
                    'by' => $thread->attachedBy?->name,
                ] : null,
                // A party-scoped thread was private to one organisation. Its
                // on-record comments still travel; the reader should know where
                // they were said.
                'visibility'         => $thread->visibility->value,
                'visible_to_party'   => $thread->visibleToParty?->label(),
                'status'             => $thread->status,
                'resolved_by'        => $thread->resolvedBy?->name,
                'resolved_at'        => $thread->resolved_at?->toISOString(),
                'created_at'         => $thread->created_at?->toISOString(),
                'last_activity_at'   => $thread->last_activity_at?->toISOString(),
                'comments_total'     => $thread->comments->count(),
                'comments_on_record' => $onRecord->count(),
                'comments_withheld'  => $thread->comments->count() - $onRecord->count(),
                'comments'           => $onRecord->sortBy('created_at')
                    ->map(fn (Comment $c) => $this->comment($c, $authors))
                    ->values()->all(),
            ];
        }

        return [
            'threads' => $included,
            'summary' => [
                'threads_total'      => $threads->count(),
                'threads_included'   => count($included),
                'comments_total'     => $commentsTotal,
                'comments_on_record' => $onRecordTotal,
                'comments_withheld'  => $commentsTotal - $onRecordTotal,
            ],
            'inclusion_rule' => 'A comment is in this packet if it carried a state change — a review, an '
                . 'approval, a commitment update, an acceptance, a schedule change, a sign-off on an agent '
                . 'action — or if its author explicitly marked it for the record. Everything else is working '
                . 'discussion and was withheld by design, so that people can speak plainly inside the '
                . 'product. Withheld comments are counted above and per thread, never silently dropped. A '
                . 'thread whose comments were all withheld does not appear individually but is counted in '
                . 'threads_total.',
        ];
    }

    /** @param array<string, string> $authors */
    private function comment(Comment $c, array $authors): array
    {
        $deleted = $c->isDeleted();

        return [
            'comment_id'   => $c->id,
            'author_type'  => $c->author_type,
            'author'       => $c->author_type === 'user' ? ($authors[$c->author_id] ?? null) : null,
            'author_id'    => $c->author_id,
            'author_party' => $c->authorParty?->label(),
            // A comment that carried a state change and was later deleted stays
            // in the record as an event. Its text does not: the deletion was an
            // act by a person, and the packet should not quietly undo it.
            'body'              => $deleted ? null : $c->body,
            'deleted'           => $deleted,
            'deleted_at'        => $c->deleted_at?->toISOString(),
            'on_record_because' => $c->carriesAction() ? 'carried_action' : 'marked_for_the_record',
            'action_type'       => $c->action_type,
            'action_id'         => $c->action_id,
            'mentions'          => $c->mentions->map(fn ($m) => $m->user?->name)->filter()->values()->all(),
            'edited_at'         => $c->edited_at?->toISOString(),
            'created_at'        => $c->created_at?->toISOString(),
        ];
    }

    /**
     * Comment authorship is polymorphic (a user or an agent), so there is no
     * relation to eager-load. Resolved in one query rather than one per comment.
     *
     * @param  Collection<int, CommentThread>  $threads
     * @return array<string, string>
     */
    private function commentAuthorNames(Collection $threads): array
    {
        $ids = $threads->flatMap->comments
            ->where('author_type', 'user')
            ->pluck('author_id')
            ->filter()
            ->unique();

        return $ids->isEmpty() ? [] : User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Human-readable titles for the objects threads hang off, so a reader never
     * has to resolve a ULID by hand against another file.
     *
     * @param  Collection<int, CommentThread>  $threads
     * @return array<string, array<string, ?string>>
     */
    private function subjectLabels(Collection $threads): array
    {
        $labels = [];

        foreach ($threads->groupBy('subject_type') as $type => $group) {
            $ids = $group->pluck('subject_id')->unique();

            $labels[$type] = match ($type) {
                'goal'          => Goal::whereIn('id', $ids)->pluck('title', 'id')->all(),
                'claim'         => Claim::whereIn('id', $ids)->pluck('statement', 'id')->all(),
                'decision'      => Decision::whereIn('id', $ids)->pluck('title', 'id')->all(),
                'commitment'    => Commitment::whereIn('id', $ids)->pluck('title', 'id')->all(),
                'evidence_item' => EvidenceItem::whereIn('id', $ids)->with('resource')->get()
                    ->mapWithKeys(fn (EvidenceItem $i) => [$i->id => $i->resource?->name])->all(),
                default         => [],
            };
        }

        return $labels;
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

    /**
     * The agent execution ledger (spec §20.4).
     *
     * Every attempted action, including the ones refused, expired or failed —
     * the row is written before anything is tried, so a packet showing only
     * successes would be a packet with the interesting half missing.
     *
     * `tool_key` and `side_effect` are read flat off the row rather than through
     * the tool relation, so the ledger still says what happened years after the
     * blueprint was edited or the tool deleted.
     */
    private function agentActions(Circle $circle): array
    {
        $actions = AgentAction::where('circle_id', $circle->id)
            ->with([
                'agentInstance.blueprint.organisation', 'onBehalfOfParty.organisation',
                'approvedBy', 'rejectedBy', 'tool',
            ])
            ->orderBy('created_at')
            ->get();

        return [
            'actions' => $actions->map(fn (AgentAction $a) => [
                'agent_action_id' => $a->id,
                'summary'         => $a->describe(),
                'tool_key'        => $a->tool_key,
                'tool_name'       => $a->tool?->name,
                'side_effect'     => $a->side_effect->value,
                'status'          => $a->status->value,
                'intent'          => $a->intent,
                'arguments'       => $a->arguments_json,
                'result'          => $a->result_json,
                'error'           => $a->error,
                'agent'           => [
                    'agent_instance_id' => $a->agent_instance_id,
                    'blueprint'         => $a->agentInstance?->blueprint?->key,
                    'blueprint_name'    => $a->agentInstance?->blueprint?->name,
                    'blueprint_version' => $a->agentInstance?->blueprint?->version,
                    'execution_mode'    => $a->agentInstance?->blueprint?->execution_mode?->value,
                    'authored_by'       => $a->agentInstance?->blueprint?->organisation?->name,
                ],
                'agent_run_id'    => $a->agent_run_id,
                // An agent never acts for "the Circle". It acts for a party,
                // and that party carries the consequence.
                'on_behalf_of'    => $a->onBehalfOfParty === null ? null : [
                    'party_id'   => $a->on_behalf_of_party_id,
                    'name'       => $a->onBehalfOfParty->label(),
                    'party_role' => $a->onBehalfOfParty->party_role->value,
                ],
                'approval'        => [
                    'decision_id'      => $a->decision_id,
                    'approved_by'      => $a->approvedBy?->name,
                    'approved_at'      => $a->approved_at?->toISOString(),
                    'rejected_by'      => $a->rejectedBy?->name,
                    'rejected_at'      => $a->rejected_at?->toISOString(),
                    'rejection_reason' => $a->rejection_reason,
                    'expires_at'       => $a->expires_at?->toISOString(),
                    'expired_unused'   => $a->isExpired(),
                ],
                'executed_at'     => $a->executed_at?->toISOString(),
                'idempotency_key' => $a->idempotency_key,
                'proposed_at'     => $a->created_at?->toISOString(),
            ])->all(),
            'summary' => [
                'total'          => $actions->count(),
                'by_status'      => $actions->countBy(fn (AgentAction $a) => $a->status->value)->all(),
                'by_side_effect' => $actions->countBy(fn (AgentAction $a) => $a->side_effect->value)->all(),
            ],
            'note' => 'An agent proposes; anything with a side effect needs a human holding the authority '
                . 'of the party that bears it. Rows are written when an action is proposed, before anything '
                . 'is attempted, so refused, expired and failed actions appear here alongside those that '
                . 'ran. An agent cannot approve on behalf of a person, including its own actions.',
        ];
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
            'verification'   => $verification->toArray(),
            'algorithm'      => 'event_hash = SHA256(canonical_json(event_without_event_hash) + previous_hash)',
            'canonical_json' => 'UTF-8 JSON with recursively sorted object keys, unescaped slashes and unicode.',
            'genesis'        => AuditChain::GENESIS,
            'events'         => $events,
        ];
    }

    // ---------------------------------------------------------------- readme

    /**
     * The packet has to explain itself to someone who has never seen this
     * product and may be reading it in a dispute years from now. That reader
     * needs three things the software's own vocabulary does not give them for
     * free: what a Circle and a party are, which parts of the record are
     * machine-generated, and what the packet is deliberately not showing.
     */
    private function readme(Circle $circle, bool $chainValid, array $originals): string
    {
        $status = $chainValid
            ? 'VALID - every event hash recomputed correctly and every link matched.'
            : 'BROKEN - see audit_events.json -> verification for the first break.';

        $originalsPara = $originals['included_count'] === 0
            ? $this->readmeWithoutOriginals()
            : $this->readmeWithOriginals($originals);

        return <<<TEXT
        Circle mission packet
        =====================

        Circle:      {$circle->name}
        Purpose:     {$circle->purpose}
        Exported:    {$this->now()}
        Audit chain: {$status}

        What this is
        ------------
        A Circle is a single piece of work run jointly by two or more separate
        organisations - for example a contractor and the company that engaged it.
        Each organisation taking part is called a party, and each party has a
        commercial position: convener (the one that opened the Circle),
        principal, contractor, subcontractor, advisor or observer.

        Everything below was recorded inside the Circle as the work happened. It
        was not assembled afterwards from memory. Each file is JSON - structured
        text that opens in any text editor, and in a spreadsheet or database tool
        if you want to sort it.

        Identifiers that look like 01J4KX9... are unique keys, and they are how
        the files cross-reference each other: a goal_id in one file points at the
        same goal in another.

        Contents
        --------
        manifest.json       A digest of every file in this packet, so the packet
                            itself can be checked for completeness and tampering.
        README.txt          This file.
        circle.json         What the work was, who convened it, when it ran.
        participants.json   The organisations involved and their commercial
                            position, and the people, their organisation, and
                            what each was permitted to do.
        goals.json          The work broken into goals and sub-goals, what "done"
                            was agreed to mean for each, who was answerable, and
                            every movement of a due date.
        evidence.json       Every file supplied to the Circle: who supplied it,
                            when, every version of it, and its digest.
        claims.json         Statements someone made about the work, the evidence
                            cited for each, and who reviewed it.
        decisions.json      Formal sign-offs, and the approval history behind
                            each - who approved, on which version, and when.
        commitments.json    Who undertook to do what, by when, and how it moved.
        threads.json        The on-record conversation attached to each object.
        agent_runs.json     Every time software read the Circle, and exactly what
                            it was shown and what it was refused.
        agent_actions.json  Every action software attempted, who authorised it,
                            and on which party's behalf.
        derived.json        Machine-generated material, and where it came from.
        audit_events.json   The tamper-evident log of everything above.
        evidence/           The original files themselves, where they fit.

        About the evidence
        ------------------
        {$originalsPara}

        A version is never overwritten. Replacing a file creates a new version
        recording which one it supersedes, so the earlier file is still in the
        record and still readable.

        Some evidence was scoped to a single party rather than shared with the
        whole Circle. Where such a file was not one the person who requested
        this packet was entitled to hold, the file did not travel - but its
        record did. It is still in evidence.json, named, attributed and
        digested, marked "party_restricted" as the reason it is absent from
        evidence/. Nothing is dropped silently; a packet that concealed the
        existence of material would not be the Circle's record.

        About the conversation
        ----------------------
        threads.json does not contain everything that was said. Inclusion is
        decided per comment, and the rule is deliberate:

          - A comment that carried a state change - a review, an approval, a
            commitment update, an acceptance, a schedule change, a sign-off on an
            agent action - is part of the decision itself and is always here.

          - A comment that was ordinary working discussion is here only if its
            author explicitly marked it "for the record".

        The reason is that people have to be able to think out loud inside a
        shared project. If every aside were destined for a packet like this one,
        nobody would say anything candid and the record would get worse, not
        better. What was withheld is counted - per thread and in total - so this
        packet never quietly conceals the size of a conversation.

        Some threads were visible to one party only. Where an on-record comment
        came from such a thread, the thread's visibility is stated alongside it.

        About dates that moved
        ----------------------
        Every change to a goal's due date is recorded with a reason, who made it,
        and - where the change affected another party's position - whether that
        party agreed and who agreed on its behalf. A change still marked
        "awaiting_agreement" was made without the counterparty's assent.

        About progress figures
        ----------------------
        "reported_progress" is a number a person asserted. "derived_progress" is
        calculated: a parent goal ignores its own figure and averages its
        sub-goals. Where the two differ, the reported figure was optimistic.

        Acceptance is separate from completion. An owner marking their own work
        done is a claim about it; the goal is settled only when someone else
        accepted it, which is recorded as accepted_by and accepted_at.

        About derived content
        ---------------------
        Anything marked derived - text extraction, transcripts, summaries and
        claims made by software - is machine-generated. It records which model
        and which prompt version produced it. It is a reading of the evidence,
        not a verification of it, and should be treated as one.

        About the software agents
        -------------------------
        Software could act inside this Circle, within limits the record shows.
        The governing rule was that an agent proposes and a person authorises:
        any action with a consequence outside the Circle needed a human holding
        the authority of the party that would bear it.

        agent_actions.json is written when an action is proposed, before anything
        is attempted, so actions that were refused, expired unapproved or failed
        appear alongside those that ran. Every action names the party it acted
        for - an agent never acted for "the Circle" - and no agent could approve
        anything on a person's behalf, including its own actions.

        agent_runs.json is the reading side: what software was shown, and what it
        was refused. Access to any given file was opt-in per file and was never
        inherited.

        About the audit chain
        ---------------------
        Each event in audit_events.json hashes its own contents together with the
        previous event's hash. Removing, reordering or editing an event breaks
        every link after it, which is detectable by recomputing the chain -
        audit_events.json states the algorithm so this can be done independently.

        This is tamper evidence for this application's own record. It is not an
        independently anchored or externally witnessed ledger, and it does not by
        itself prove when an event occurred.
        TEXT;
    }

    private function readmeWithoutOriginals(): string
    {
        return <<<TEXT
            No original files travelled with this packet. What is here instead is a
            SHA-256 digest of each one, in evidence.json. A digest is a fingerprint:
            anyone holding a copy of a file can recompute it and see whether it is
            the same bytes this Circle recorded. manifest.json -> originals.omitted
            names every file that was left out and why.
            TEXT;
    }

    private function readmeWithOriginals(array $originals): string
    {
        $tail = $originals['omitted_count'] === 0
            ? 'Every original the Circle holds is in this packet.'
            : sprintf(
                "%d file(s) were left out - because of the packet's size limit on\n"
                . "originals, because the file was scoped to a party the requester of\n"
                . "this export is not in, or because the vault could not produce it.\n"
                . "Each one is named in manifest.json -> originals.omitted with the\n"
                . "reason that applies to it, and its digest is still in evidence.json,\n"
                . "so it remains identifiable and can be requested separately.",
                $originals['omitted_count'],
            );

        return sprintf(
            <<<TEXT
                The evidence/ folder holds %d original file(s), laid out as

                    evidence/<evidence item id>/v<version number>-<filename>

                For each one, manifest.json records two digests: "sha256", the
                fingerprint of the file as it sits in this packet, and
                "recorded_sha256", the fingerprint this Circle recorded when the file
                was uploaded. Where "matches_record" is true, the file in front of you
                is byte-for-byte the file the Circle worked from.

                %s
                TEXT,
            $originals['included_count'],
            $tail,
        );
    }

    private function now(): string
    {
        return now()->toDayDateTimeString() . ' UTC';
    }
}
