<?php

namespace App\Models;

use App\Enums\CircleRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CircleMembership extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'user_id', 'circle_role', 'is_external',
        'invite_status', 'expires_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'circle_role' => CircleRole::class,
            'is_external' => 'boolean',
            'expires_at'  => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->invite_status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
