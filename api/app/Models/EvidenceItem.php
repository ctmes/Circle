<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\IntegrityStatus;
use App\Enums\OriginStatus;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'resource_id', 'origin_status', 'integrity_status', 'review_status',
        'classification', 'restricted_to_party_id', 'source_label', 'source_url',
        'uploader_user_id', 'expires_at', 'superseded_by_id', 'agent_read',
        'downloadable', 'stale_at',
    ];

    protected function casts(): array
    {
        return [
            'origin_status'    => OriginStatus::class,
            'integrity_status' => IntegrityStatus::class,
            'review_status'    => ReviewStatus::class,
            'classification'   => Classification::class,
            'agent_read'       => 'boolean',
            'downloadable'     => 'boolean',
            'expires_at'       => 'datetime',
            'stale_at'         => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CircleResource::class, 'resource_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    /** The party this item is confined to, or null for the whole Circle. */
    public function restrictedToParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'restricted_to_party_id');
    }

    /** The nodes of the plan this document is filed against. */
    public function goals(): BelongsToMany
    {
        return $this->belongsToMany(Goal::class, 'evidence_item_goal')
            ->withPivot(['id', 'attached_by_user_id', 'attached_at']);
    }

    /**
     * Whether a reader sitting in $partyId may see this item at all.
     *
     * The rule, in one place, because it is asked in four: the gate, the
     * register, the agent access statement and the export packet.
     *
     * A restricted item is visible to the party it belongs to *and to the
     * convener*. That asymmetry is deliberate and it is where this differs from
     * a private comment thread, which hides from the convener too. Two reasons.
     * The convener receives the export packet, so evidence hidden from it would
     * either reappear there or quietly fall out of the record — a promise the
     * product cannot keep either way. And the case this exists for is a
     * subcontractor's rate card: it is submitted *to* the convener. The parties
     * it must not reach are the other bidders.
     *
     * Widening beyond that is a ResourceAccessOverride — a named grant to a
     * named person, which the gate consults before it gets here.
     *
     * @param  string|null  $partyId          the reader's effective party
     * @param  string|null  $convenerPartyId  the Circle's convening party
     */
    public function isVisibleToParty(?string $partyId, ?string $convenerPartyId): bool
    {
        if ($this->restricted_to_party_id === null) {
            return true;
        }

        // No party at all in a Circle that uses them: nothing to match on, so
        // the restriction holds. Failing open here would make the column
        // decorative for exactly the memberships that predate parties.
        if ($partyId === null) {
            return false;
        }

        return $partyId === $this->restricted_to_party_id || $partyId === $convenerPartyId;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EvidenceVersion::class)->orderBy('version_number');
    }

    /** The newest version; older ones remain retrievable and citable forever. */
    public function currentVersion(): ?EvidenceVersion
    {
        return $this->versions()->orderByDesc('version_number')->first();
    }
}
