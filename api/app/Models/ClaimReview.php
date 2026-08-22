<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimReview extends Model
{
    use HasUlids;

    protected $fillable = ['claim_id', 'reviewer_user_id', 'outcome', 'comment'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
