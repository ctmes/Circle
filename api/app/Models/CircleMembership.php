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
        'circle_id', 'user_id', 'circle_party_id', 'engagement_id', 'circle_role',
        'is_external', 'invite_status', 'expires_at', 'revoked_at',
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

    /**
     * The contract that issued this seat, if one did (spec §21.2).
     *
     * Null for everyone who was simply invited, which is the ordinary case.
     * Where it is set the seat lives and dies with the engagement — AccessGate
     * reads it as check (7), and it can only ever narrow what the role gives.
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /** Whether this seat was issued by a temp contract rather than an invitation. */
    public function isEngaged(): bool
    {
        return $this->engagement_id !== null;
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

        return CircleParty::convenerIdFor($this->circle_id);
    }

    public function isExternal(): bool
    {
        $party = $this->relationLoaded('party') ? $this->party : $this->party()->first();

        return $party === null ? (bool) $this->is_external : ! $party->is_convener;
    }
}
