<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only audit record. Rows are never updated or deleted — the hash
 * chain in App\Services\Audit\AuditChain is what makes that detectable.
 */
class AuditEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'circle_id', 'actor_type', 'actor_id', 'event_type',
        'resource_type', 'resource_id', 'resource_version',
        'metadata_json', 'occurred_at', 'previous_hash', 'event_hash',
    ];

    protected function casts(): array
    {
        return [
            'actor_type'    => ActorType::class,
            'event_type'    => AuditEventType::class,
            'metadata_json' => 'array',
            'occurred_at'   => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /**
     * The exact field set that is hashed. Order here is irrelevant — the
     * canonicaliser sorts keys — but membership is critical: adding a field
     * later invalidates every existing chain.
     */
    public function hashableAttributes(): array
    {
        return [
            'id'               => $this->id,
            'circle_id'        => $this->circle_id,
            'actor_type'       => $this->actor_type instanceof ActorType ? $this->actor_type->value : $this->actor_type,
            'actor_id'         => $this->actor_id,
            'event_type'       => $this->event_type instanceof AuditEventType ? $this->event_type->value : $this->event_type,
            'resource_type'    => $this->resource_type,
            'resource_id'      => $this->resource_id,
            'resource_version' => $this->resource_version,
            'metadata_json'    => $this->metadata_json,
            'occurred_at'      => $this->occurred_at?->toISOString(),
        ];
    }
}
