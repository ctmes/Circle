<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceAccessOverride extends Model
{
    use HasUlids;

    protected $fillable = [
        'resource_id', 'user_id', 'circle_party_id', 'permission', 'allow',
        'reason', 'granted_by_user_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
            'allow'      => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CircleResource::class, 'resource_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'circle_party_id');
    }

    /** How specific this override is; the gate applies the narrowest match. */
    public function specificity(): int
    {
        return match (true) {
            $this->user_id !== null        => 2,
            $this->circle_party_id !== null => 1,
            default                         => 0,
        };
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
