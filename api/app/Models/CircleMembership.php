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
        'circle_id', 'user_id', 'circle_party_id', 'circle_role', 'is_external',
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

    /**
     * The party this person represents.
     *
     * Nullable for Circles created before parties existed, and for the
     * convener's own staff where no party row was ever needed.
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'circle_party_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->invite_status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Whether this member sits outside the convening party.
     *
     * `is_external` remains the stored fast path the gate reads, because a
     * Circle with no parties still has to answer this. Where a party is set it
     * is authoritative: external means "not the convener", which is the honest
     * reading once a Circle spans several companies.
     */
    /**
     * The party this member counts as, for visibility decisions.
     *
     * A member with no party row sits with the convener — the same fallback
     * CommentService uses when deciding who owns a new private thread. The two
     * must agree, or someone opens a thread they cannot then read.
     */
    public function effectivePartyId(): ?string
    {
        if ($this->circle_party_id !== null) {
            return $this->circle_party_id;
        }

        return CircleParty::where('circle_id', $this->circle_id)
            ->where('is_convener', true)
            ->value('id');
    }

    public function isExternal(): bool
    {
        $party = $this->relationLoaded('party') ? $this->party : $this->party()->first();

        return $party === null ? (bool) $this->is_external : ! $party->is_convener;
    }
}
