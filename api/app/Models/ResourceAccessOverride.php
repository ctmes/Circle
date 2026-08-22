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
        'resource_id', 'user_id', 'permission', 'allow', 'granted_by_user_id', 'expires_at',
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

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
