<?php

namespace App\Models;

use App\Enums\PrincipalType;
use App\Enums\WorkOutcome;
use App\Enums\WorkVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a principal carries away (spec §21.3).
 *
 * The only object here not scoped to a Circle. `circle_name` and
 * `counterparty_name` are stored rather than joined for that reason: the row
 * has to keep reading correctly after the Circle it came from is closed,
 * exported and gone.
 *
 * Compiled by the platform, signed by the counterparty, published by its
 * subject — three different parties, none of whom can do the other's part.
 * That separation is the whole value: a claim signed by the company that was
 * paying, and that is not the worker's employer, is a materially different
 * thing from a self-declared skill list.
 */
class WorkRecord extends Model
{
    use HasUlids;

    protected $fillable = [
        'principal_type', 'principal_id', 'principal_organisation_id',
        'counterparty_organisation_id', 'counterparty_name',
        'circle_id', 'circle_name', 'engagement_id', 'title', 'summary',
        'party_role', 'fee_basis', 'started_at', 'ended_at', 'outcome',
        'metrics_json', 'refusals_json', 'attested_by_party_id',
        'attested_by_user_id', 'attested_at', 'attestation_note',
        'visibility', 'published_at', 'sequence', 'previous_hash', 'record_hash',
    ];

    protected function casts(): array
    {
        return [
            'principal_type' => PrincipalType::class,
            'outcome'        => WorkOutcome::class,
            'visibility'     => WorkVisibility::class,
            'metrics_json'   => 'array',
            'refusals_json'  => 'array',
            'sequence'       => 'integer',
            'started_at'     => 'datetime',
            'ended_at'       => 'datetime',
            'attested_at'    => 'datetime',
            'published_at'   => 'datetime',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'counterparty_organisation_id');
    }

    public function principalOrganisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'principal_organisation_id');
    }

    public function attestedByParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'attested_by_party_id');
    }

    public function attestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by_user_id');
    }

    public function isAttested(): bool
    {
        return $this->attested_at !== null;
    }

    public function isPublished(): bool
    {
        return $this->visibility !== null && $this->published_at !== null;
    }

    /** A metric by name, defaulting to zero rather than null. */
    public function metric(string $key): int
    {
        return (int) (($this->metrics_json ?? [])[$key] ?? 0);
    }

    /**
     * The fields the hash covers.
     *
     * Deliberately excludes `visibility` and `published_at`: publishing and
     * hiding a record must not break its signature, because the subject
     * controls those and the counterparty controls the rest. If publication
     * were hashed, every hide would look like tampering — and the one thing
     * this chain exists to detect would be drowned in noise.
     *
     * @return array<string, mixed>
     */
    public function hashableAttributes(): array
    {
        return [
            'id'                           => $this->id,
            'principal_type'               => $this->principal_type->value,
            'principal_id'                 => $this->principal_id,
            'principal_organisation_id'    => $this->principal_organisation_id,
            'counterparty_organisation_id' => $this->counterparty_organisation_id,
            'counterparty_name'            => $this->counterparty_name,
            'circle_id'                    => $this->circle_id,
            'circle_name'                  => $this->circle_name,
            'engagement_id'                => $this->engagement_id,
            'title'                        => $this->title,
            'party_role'                   => $this->party_role,
            'fee_basis'                    => $this->fee_basis,
            'started_at'                   => $this->started_at?->toIso8601String(),
            'ended_at'                     => $this->ended_at?->toIso8601String(),
            'outcome'                      => $this->outcome->value,
            'metrics_json'                 => $this->metrics_json,
            'refusals_json'                => $this->refusals_json,
            'attested_by_party_id'         => $this->attested_by_party_id,
            'attested_at'                  => $this->attested_at?->toIso8601String(),
            'attestation_note'             => $this->attestation_note,
            'sequence'                     => $this->sequence,
        ];
    }
}
