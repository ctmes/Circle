<?php

namespace App\Models;

use App\Enums\CitationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimCitation extends Model
{
    use HasUlids;

    protected $fillable = [
        'claim_id', 'evidence_version_id', 'citation_type', 'locator_json', 'excerpt',
    ];

    protected function casts(): array
    {
        return [
            'citation_type' => CitationType::class,
            'locator_json'  => 'array',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function evidenceVersion(): BelongsTo
    {
        return $this->belongsTo(EvidenceVersion::class);
    }
}
