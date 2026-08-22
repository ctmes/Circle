<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per resource the agent attempted to read, including denials — this
 * is what answers "what did the agent access and why" (spec §19).
 */
class AgentResourceAccess extends Model
{
    use HasUlids;

    protected $fillable = [
        'agent_run_id', 'resource_id', 'evidence_version_id',
        'access_type', 'permitted', 'reason', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'permitted'   => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CircleResource::class, 'resource_id');
    }
}
