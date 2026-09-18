<?php

namespace App\Models;

use App\Enums\WorkVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shape of a plan, with the Circle removed (spec §21.4).
 *
 * A form, not a copy. Nothing that identifies the project it was captured from
 * survives capture — no evidence, no parties, no dates, no ids.
 */
class WorkPackage extends Model
{
    use HasUlids;

    protected $fillable = [
        'organisation_id', 'name', 'summary', 'source_circle_id',
        'forked_from_id', 'version', 'visibility', 'published_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'visibility'   => WorkVisibility::class,
            'version'      => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function sourceCircle(): BelongsTo
    {
        return $this->belongsTo(Circle::class, 'source_circle_id');
    }

    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(WorkPackage::class, 'forked_from_id');
    }

    public function forks(): HasMany
    {
        return $this->hasMany(WorkPackage::class, 'forked_from_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Every node, flat. The tree is assembled by WorkPackageService. */
    public function nodes(): HasMany
    {
        return $this->hasMany(WorkPackageNode::class)->orderBy('position');
    }

    public function rootNodes(): HasMany
    {
        return $this->hasMany(WorkPackageNode::class)->whereNull('parent_node_id')->orderBy('position');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * How far this shape has travelled.
     *
     * Walked rather than counted with a stored depth, because a fork's parent
     * can be deleted and the column would then be a lie. A null link ends the
     * walk, which is the honest answer: "at least this far".
     */
    public function lineageDepth(): int
    {
        $depth   = 0;
        $current = $this->forkedFrom;

        while ($current !== null && $depth < 32) {
            $depth++;
            $current = $current->forkedFrom;
        }

        return $depth;
    }
}
