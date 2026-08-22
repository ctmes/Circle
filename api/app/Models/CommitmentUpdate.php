<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitmentUpdate extends Model
{
    use HasUlids;

    protected $fillable = ['commitment_id', 'actor_user_id', 'from_status', 'to_status', 'note'];

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class);
    }
}
