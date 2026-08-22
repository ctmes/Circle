<?php

namespace App\Models;

use App\Enums\ArtifactType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Any system- or agent-generated output. Kept strictly separate from the
 * preserved original so an AI interpretation can never be mistaken for the
 * evidence itself (spec §2).
 */
class DerivedArtifact extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'parent_resource_type', 'parent_resource_id', 'artifact_type',
        'content_json', 'storage_key', 'model_provider', 'model_name',
        'prompt_version', 'agent_run_id', 'source_manifest_json', 'status',
    ];

    protected function casts(): array
    {
        return [
            'artifact_type'        => ArtifactType::class,
            'content_json'         => 'array',
            'source_manifest_json' => 'array',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(EvidenceVersion::class, 'parent_resource_id');
    }
}
