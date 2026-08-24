<?php

namespace App\Models;

use App\Enums\PartyRole;
use App\Enums\PartyStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisation participating in a Circle.
 *
 * A party may exist before its organisation does — counterparties get named in
 * the plan well before anyone from them signs in — so `organisation_id` is
 * nullable and gets bound when the first member accepts an invitation.
 */
class CircleParty extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'organisation_id', 'display_name', 'party_role', 'status',
        'is_convener', 'external_reference', 'invited_by_user_id',
        'joined_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'party_role'   => PartyRole::class,
            'status'       => PartyStatus::class,
            'is_convener'  => 'boolean',
            'joined_at'    => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CircleMembership::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class, 'responsible_party_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive() && $this->withdrawn_at === null;
    }

    /** The real organisation name once bound, the placeholder until then. */
    public function label(): string
    {
        return $this->organisation?->name ?? $this->display_name;
    }
}
