<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'agent_instance_id', 'circle_id', 'triggered_by_user_id', 'run_type', 'status',
        'model_provider', 'model_name', 'prompt_version',
        'retrieval_manifest_json', 'output_json', 'error',
        'input_tokens', 'output_tokens', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'retrieval_manifest_json' => 'array',
            'output_json'             => 'array',
            'started_at'              => 'datetime',
            'finished_at'             => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AgentInstance::class, 'agent_instance_id');
    }

    public function resourceAccesses(): HasMany
    {
        return $this->hasMany(AgentResourceAccess::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(DerivedArtifact::class, 'agent_run_id');
    }
}
