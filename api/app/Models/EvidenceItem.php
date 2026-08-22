<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\IntegrityStatus;
use App\Enums\OriginStatus;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'resource_id', 'origin_status', 'integrity_status', 'review_status',
        'classification', 'source_label', 'source_url', 'uploader_user_id',
        'expires_at', 'superseded_by_id', 'agent_read', 'downloadable', 'stale_at',
    ];

    protected function casts(): array
    {
        return [
            'origin_status'    => OriginStatus::class,
            'integrity_status' => IntegrityStatus::class,
            'review_status'    => ReviewStatus::class,
            'classification'   => Classification::class,
            'agent_read'       => 'boolean',
            'downloadable'     => 'boolean',
            'expires_at'       => 'datetime',
            'stale_at'         => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CircleResource::class, 'resource_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EvidenceVersion::class)->orderBy('version_number');
    }

    /** The newest version; older ones remain retrievable and citable forever. */
    public function currentVersion(): ?EvidenceVersion
    {
        return $this->versions()->orderByDesc('version_number')->first();
    }
}
