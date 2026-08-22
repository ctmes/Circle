<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Polymorphic base registry row backing every Circle resource (table:
 * `resources`). Named CircleResource because `Resource` is soft-reserved in PHP.
 *
 * Authorisation hangs off this row, so AccessGate needs one code path for
 * every resource type.
 */
class CircleResource extends Model
{
    use HasUlids;

    protected $table = 'resources';

    protected $fillable = [
        'circle_id', 'resource_type', 'name',
        'created_by_type', 'created_by_id', 'status',
    ];

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function evidenceItem(): HasOne
    {
        return $this->hasOne(EvidenceItem::class, 'resource_id');
    }

    public function accessOverrides(): HasMany
    {
        return $this->hasMany(ResourceAccessOverride::class, 'resource_id');
    }
}
