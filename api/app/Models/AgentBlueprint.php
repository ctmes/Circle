<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The versioned, declarative mandate for an agent (spec §9). */
class AgentBlueprint extends Model
{
    use HasUlids;

    public const STEWARD = 'circle_steward';

    protected $fillable = [
        'key', 'name', 'mandate', 'version',
        'allowed_actions', 'prohibited_actions', 'prompt_version',
    ];

    protected function casts(): array
    {
        return [
            'allowed_actions'    => 'array',
            'prohibited_actions' => 'array',
        ];
    }

    public function instances(): HasMany
    {
        return $this->hasMany(AgentInstance::class);
    }
}
