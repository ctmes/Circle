<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Export extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'requested_by_user_id', 'status', 'storage_key',
        'sha256', 'byte_size', 'manifest_json', 'audit_chain_valid',
        'error', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest_json'     => 'array',
            'audit_chain_valid' => 'boolean',
            'byte_size'         => 'integer',
            'completed_at'      => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
