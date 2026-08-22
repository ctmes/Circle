<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Claim extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'author_type', 'author_id', 'statement',
        'claim_type', 'status', 'confidence', 'agent_run_id',
    ];

    protected function casts(): array
    {
        return [
            'claim_type' => ClaimType::class,
            'status'     => ClaimStatus::class,
            'confidence' => 'float',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function citations(): HasMany
    {
        return $this->hasMany(ClaimCitation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ClaimReview::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isAgentAuthored(): bool
    {
        return $this->author_type === 'agent';
    }
}
