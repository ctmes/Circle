<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A per-user allow/deny override layered on top of the Circle role default. */
class RoleGrant extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'user_id', 'permission', 'allow', 'granted_by_user_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
            'allow'      => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
