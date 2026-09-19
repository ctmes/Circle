<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One meeting transcript that arrived, and what became of it (spec §24).
 *
 * The line a person reads in the log. The authoritative account of what the
 * transcript changed is the agent action ledger, which records every write with
 * its intent, its arguments and its result; this row points at the run that
 * produced them and summarises the outcome.
 */
class TranscriptImport extends Model
{
    use HasUlids;

    public const PENDING    = 'pending';
    public const PROCESSING = 'processing';
    public const APPLIED    = 'applied';
    public const FAILED     = 'failed';
    /** A resend of something already processed. Recorded, not re-run. */
    public const DUPLICATE  = 'duplicate';
    /** Routed to nothing: not about a piece of work. The text is not kept. */
    public const IGNORED    = 'ignored';

    protected $fillable = [
        'organisation_id', 'submitted_by_user_id', 'circle_id', 'source', 'external_id',
        'title', 'occurred_at', 'participants_json', 'content_sha256', 'content_chars',
        'requested_circle_id', 'status', 'routing', 'routing_reason', 'evidence_item_id',
        'agent_run_id', 'result_json', 'error', 'processed_at', 'content',
    ];

    /** The transcript itself never leaves through the API. See the migration. */
    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'occurred_at'       => 'datetime',
            'processed_at'      => 'datetime',
            'participants_json' => 'array',
            'result_json'       => 'array',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function evidenceItem(): BelongsTo
    {
        return $this->belongsTo(EvidenceItem::class);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::APPLIED, self::FAILED, self::DUPLICATE, self::IGNORED], true);
    }
}
