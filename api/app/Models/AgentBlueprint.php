<?php

namespace App\Models;

use App\Enums\AgentExecutionMode;
use App\Enums\Permission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The versioned, declarative mandate for an agent (spec §9).
 *
 * Originally there was exactly one of these and no way to author another. Now
 * organisations write their own, which makes the mandate the thing standing
 * between an authored agent and the Circle it runs in — so it is enforced by
 * AccessGate rather than merely described here.
 */
class AgentBlueprint extends Model
{
    use HasUlids;

    public const STEWARD = 'circle_steward';

    protected $fillable = [
        'key', 'organisation_id', 'circle_id', 'created_by_user_id', 'is_system',
        'execution_mode', 'provider', 'status', 'name', 'mandate', 'instructions',
        'version', 'allowed_actions', 'prohibited_actions', 'prompt_version',
    ];

    protected function casts(): array
    {
        return [
            'allowed_actions'    => 'array',
            'prohibited_actions' => 'array',
            'is_system'          => 'boolean',
            'execution_mode'     => AgentExecutionMode::class,
        ];
    }

    public function instances(): HasMany
    {
        return $this->hasMany(AgentInstance::class);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(AgentTool::class)->where('enabled', true);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Whether this blueprint is confined to a single Circle. */
    public function isCircleScoped(): bool
    {
        return $this->circle_id !== null;
    }

    /**
     * The permissions this blueprint actually grants.
     *
     * The intersection of what it declares and what its execution mode permits
     * it to declare at all. An authored blueprint listing `agent.execute` while
     * sitting in `read_only` gets nothing — the mode is the outer bound, and it
     * is a single column so that downgrading an agent is one edit that takes
     * effect everywhere at once.
     *
     * @return array<int, Permission>
     */
    public function effectivePermissions(): array
    {
        $mode    = $this->execution_mode ?? AgentExecutionMode::ReadOnly;
        $ceiling = $mode->ceiling();

        $declared = array_filter(array_map(
            fn (string $value) => Permission::tryFrom($value),
            $this->allowed_actions ?? [],
        ));

        // Compared by identity, not by string: array_intersect would coerce
        // these enum cases to string and fatal.
        return array_values(array_filter(
            $declared,
            fn (Permission $p) => in_array($p, $ceiling, strict: true),
        ));
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->effectivePermissions(), strict: true);
    }
}
