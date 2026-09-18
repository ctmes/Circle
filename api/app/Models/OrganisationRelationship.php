<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who has worked with whom (spec §21.5).
 *
 * Derived from engagements rather than declared, so the network is a fact
 * about work done rather than a list somebody curated. This is what `network`
 * visibility resolves against, and the reason it is worth defaulting to.
 *
 * The pair is stored in a fixed order so one relationship is one row —
 * "a has worked with b" and "b has worked with a" are the same fact, and two
 * rows would eventually let them disagree.
 */
class OrganisationRelationship extends Model
{
    use HasUlids;

    protected $fillable = [
        'organisation_a_id', 'organisation_b_id', 'engagements_count',
        'completed_count', 'first_engaged_at', 'last_engaged_at',
    ];

    protected function casts(): array
    {
        return [
            'engagements_count' => 'integer',
            'completed_count'   => 'integer',
            'first_engaged_at'  => 'datetime',
            'last_engaged_at'   => 'datetime',
        ];
    }

    public function organisationA(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_a_id');
    }

    public function organisationB(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_b_id');
    }

    /**
     * The two ids in the canonical order.
     *
     * String comparison over ULIDs, which are lexicographically ordered, so
     * the pair is stable and no caller has to remember which way round it went.
     *
     * @return array{0: string, 1: string}
     */
    public static function pair(string $one, string $other): array
    {
        return $one <= $other ? [$one, $other] : [$other, $one];
    }
}
