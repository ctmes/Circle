<?php

namespace App\Models;

use App\Enums\FeeBasis;
use App\Enums\OpeningStatus;
use App\Enums\PrincipalKind;
use App\Enums\WorkVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Work nobody has been found for yet (spec §21.1).
 *
 * The one object in the product a person outside the Circle may see, and the
 * only exception to §15's rule. What keeps that safe is `publicView()` below:
 * an opening is rendered outside from its own columns and the goal's title,
 * and there is no path from here into the tree, the evidence or the threads.
 */
class WorkOpening extends Model
{
    use HasUlids;

    protected $fillable = [
        'circle_id', 'posted_by_party_id', 'created_by_user_id', 'goal_id',
        'title', 'brief', 'acceptance_condition', 'principal_kind', 'status',
        'visibility', 'fee_basis', 'fee_amount_minor', 'currency',
        'term_starts_at', 'term_ends_at', 'estimated_units', 'closes_at',
        'posted_at', 'filled_at',
    ];

    protected function casts(): array
    {
        return [
            'principal_kind'   => PrincipalKind::class,
            'status'           => OpeningStatus::class,
            'visibility'       => WorkVisibility::class,
            'fee_basis'        => FeeBasis::class,
            'fee_amount_minor' => 'integer',
            'estimated_units'  => 'integer',
            'term_starts_at'   => 'datetime',
            'term_ends_at'     => 'datetime',
            'closes_at'        => 'datetime',
            'posted_at'        => 'datetime',
            'filled_at'        => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function postedByParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'posted_by_party_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(WorkApplication::class);
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class);
    }

    /** Open, and not past its own closing date. */
    public function acceptsApplications(): bool
    {
        return $this->status->acceptsApplications()
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }

    public function hasClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }

    /**
     * Everything an outsider is allowed to know.
     *
     * The boundary of §15's exception, expressed as a method rather than left
     * to each controller to remember. The Circle's name is here because an
     * applicant deciding whether to bid needs to know who they would be
     * working for; nothing else about the Circle is, and there is deliberately
     * no relation traversal in this array beyond a goal's title.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        return [
            'id'                   => $this->id,
            'title'                => $this->title,
            'brief'                => $this->brief,
            'acceptance_condition' => $this->acceptance_condition,
            'work_title'           => $this->goal?->title,
            'principal_kind'       => $this->principal_kind->value,
            'status'               => $this->status->value,
            'fee_basis'            => $this->fee_basis->value,
            'fee_basis_label'      => $this->fee_basis->label(),
            'fee_amount_minor'     => $this->fee_amount_minor,
            'currency'             => $this->currency,
            'estimated_units'      => $this->estimated_units,
            'term_starts_at'       => $this->term_starts_at?->toIso8601String(),
            'term_ends_at'         => $this->term_ends_at?->toIso8601String(),
            'closes_at'            => $this->closes_at?->toIso8601String(),
            'posted_at'            => $this->posted_at?->toIso8601String(),
            'posted_by'            => $this->postedByParty?->label(),
            'circle_name'          => $this->circle?->name,
        ];
    }
}
