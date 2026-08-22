<?php

namespace App\Models;

use App\Enums\IntegrityStatus;
use App\Enums\ProcessingStatus;
use App\Support\MediaType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable record of one uploaded binary. Originals are never overwritten;
 * a replacement is always a new row with supersedes_version_id set (spec §7).
 */
class EvidenceVersion extends Model
{
    use HasUlids;

    protected $fillable = [
        'evidence_item_id', 'version_number', 'storage_key', 'original_filename',
        'mime_type', 'byte_size', 'sha256', 'metadata_json',
        'extracted_text_status', 'preview_status', 'transcript_status',
        'processing_status', 'processing_error',
        'created_by_user_id', 'supersedes_version_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json'         => 'array',
            'byte_size'             => 'integer',
            'version_number'        => 'integer',
            'extracted_text_status' => ProcessingStatus::class,
            'preview_status'        => ProcessingStatus::class,
            'transcript_status'     => ProcessingStatus::class,
            'processing_status'     => ProcessingStatus::class,
        ];
    }

    public function evidenceItem(): BelongsTo
    {
        return $this->belongsTo(EvidenceItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(ClaimCitation::class);
    }

    /** Derived artifacts (OCR, transcript, frames) produced from this version. */
    public function derivedArtifacts(): HasMany
    {
        return $this->hasMany(DerivedArtifact::class, 'parent_resource_id')
            ->where('parent_resource_type', 'evidence_version');
    }

    public function isSuperseded(): bool
    {
        return static::query()
            ->where('evidence_item_id', $this->evidence_item_id)
            ->where('version_number', '>', $this->version_number)
            ->exists();
    }

    /**
     * Integrity is derived rather than stored, so it cannot silently drift out
     * of step with the facts it summarises:
     *   - a newer version exists            -> superseded
     *   - hash recorded and last check ok   -> intact
     *   - hash mismatch recorded            -> changed
     *   - not yet hashed                    -> unknown
     */
    public function integrityStatus(): IntegrityStatus
    {
        if ($this->isSuperseded()) {
            return IntegrityStatus::Superseded;
        }

        if (($this->metadata_json['integrity_mismatch'] ?? false) === true) {
            return IntegrityStatus::Changed;
        }

        return $this->sha256 !== null ? IntegrityStatus::Intact : IntegrityStatus::Unknown;
    }

    public function lane(): string
    {
        return $this->metadata_json['lane'] ?? MediaType::laneFor($this->original_filename, $this->mime_type);
    }
}
