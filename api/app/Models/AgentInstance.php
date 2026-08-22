<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Services\Authorisation\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A blueprint bound to exactly one Circle. This is the identity the policy gate
 * authorises — the agent never borrows a user's credentials (spec §9).
 */
class AgentInstance extends Model implements Actor
{
    use HasUlids;

    protected $fillable = ['agent_blueprint_id', 'circle_id', 'status', 'disabled_at'];

    protected function casts(): array
    {
        return ['disabled_at' => 'datetime'];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AgentBlueprint::class, 'agent_blueprint_id');
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    // ---------------------------------------------------------- Actor

    public function actorType(): ActorType
    {
        return ActorType::Agent;
    }

    public function actorId(): string
    {
        return $this->id;
    }

    public function actorLabel(): string
    {
        return $this->blueprint?->name ?? 'Agent';
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->disabled_at === null;
    }
}
