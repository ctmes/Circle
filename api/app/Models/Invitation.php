<?php

namespace App\Models;

use App\Enums\CircleRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'email', 'circle_role', 'is_external', 'token',
        'invited_by_user_id', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'circle_role' => CircleRole::class,
            'is_external' => 'boolean',
            'expires_at'  => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isRedeemable(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
