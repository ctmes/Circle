<?php

namespace App\Models;

use App\Enums\AgentExecutionMode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mandate frozen at the moment somebody agreed to pay for it (spec §21.6).
 *
 * The blueprint stays the living thing its author edits. This is what was
 * hired — the same relationship an evidence version has to its item (§6.2),
 * and for the same reason: what was agreed to last month must still resolve
 * to exactly what was agreed to.
 */
class AgentBlueprintVersion extends Model
{
    use HasUlids;

    /** Versions offerable outside their own organisation. */
    public const LISTED = ['network', 'public'];

    protected $fillable = [
        'agent_blueprint_id', 'organisation_id', 'version_number', 'name',
        'mandate', 'instructions', 'execution_mode', 'prompt_version',
        'allowed_actions', 'prohibited_actions', 'tools_json', 'content_hash',
        'listing_status', 'release_note', 'published_by_user_id', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'execution_mode'     => AgentExecutionMode::class,
            'allowed_actions'    => 'array',
            'prohibited_actions' => 'array',
            'tools_json'         => 'array',
            'version_number'     => 'integer',
            'published_at'       => 'datetime',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AgentBlueprint::class, 'agent_blueprint_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class, 'agent_blueprint_version_id');
    }

    public function isListed(): bool
    {
        return in_array($this->listing_status, self::LISTED, true);
    }

    /**
     * The tools this version declares, with what each one can do.
     *
     * Read from the frozen snapshot rather than from the blueprint's current
     * tools, which is the entire point of the table — a tool added after the
     * hire is the same problem as a mandate widened after it.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        return $this->tools_json ?? [];
    }

    /** The most consequential thing this version can do, for a hirer's eye. */
    public function highestSideEffect(): string
    {
        $order = ['none' => 0, 'circle_write' => 1, 'external_read' => 2, 'external_write' => 3, 'financial' => 4];
        $worst = 'none';

        foreach ($this->tools() as $tool) {
            $effect = $tool['side_effect'] ?? 'none';

            if (($order[$effect] ?? 0) > ($order[$worst] ?? 0)) {
                $worst = $effect;
            }
        }

        return $worst;
    }

    /**
     * The hash a hirer is shown.
     *
     * Canonical JSON so key order cannot change the digest — the same
     * construction §11 uses on the audit chain, because "is this the mandate I
     * approved in March" has to be answerable mechanically rather than by
     * reading two pages of JSON.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function hashOf(array $attributes): string
    {
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
