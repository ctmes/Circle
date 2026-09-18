<?php

namespace App\Models;

use App\Enums\PartyRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node of a captured plan (spec §21.4).
 *
 * Offsets rather than dates, and a party *role* rather than a party. Both for
 * the same reason: a template that carried the last project's deadlines and
 * the last client's name into the next one would be fixed by hand once and
 * then never used again.
 */
class WorkPackageNode extends Model
{
    use HasUlids;

    protected $fillable = [
        'work_package_id', 'parent_node_id', 'title', 'description',
        'acceptance_condition', 'starts_offset_days', 'due_offset_days',
        'default_party_role', 'post_as_opening', 'position',
    ];

    protected function casts(): array
    {
        return [
            'default_party_role' => PartyRole::class,
            'starts_offset_days' => 'integer',
            'due_offset_days'    => 'integer',
            'post_as_opening'    => 'boolean',
            'position'           => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(WorkPackage::class, 'work_package_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(WorkPackageNode::class, 'parent_node_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(WorkPackageNode::class, 'parent_node_id')->orderBy('position');
    }

    /**
     * This node's dates, against a given anchor.
     *
     * @return array{starts_at: ?\DateTimeImmutable, due_at: ?\DateTimeImmutable}
     */
    public function datesFrom(\DateTimeInterface $anchor): array
    {
        $base = \Carbon\CarbonImmutable::instance(
            $anchor instanceof \DateTimeImmutable ? $anchor : \DateTimeImmutable::createFromInterface($anchor),
        );

        return [
            'starts_at' => $this->starts_offset_days === null
                ? null
                : $base->addDays($this->starts_offset_days)->toDateTimeImmutable(),
            'due_at' => $this->due_offset_days === null
                ? null
                : $base->addDays($this->due_offset_days)->toDateTimeImmutable(),
        ];
    }
}
