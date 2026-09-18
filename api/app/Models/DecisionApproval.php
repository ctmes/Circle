<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only resolution history for a decision. Rows are never updated. */
class DecisionApproval extends Model
{
    use HasUlids;

    protected $fillable = [
        'decision_id', 'actor_user_id', 'circle_party_id', 'outcome',
        'subject_type', 'subject_id', 'subject_version', 'comment', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'circle_party_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
