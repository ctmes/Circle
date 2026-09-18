<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billable unit (spec §21.6).
 *
 * Written when an action is approved and executed rather than when it is
 * proposed — an agent does not get paid for asking. That distinction is only
 * expressible because §20.4 writes the proposal to the ledger before anything
 * is attempted; the meter reads the half that actually happened.
 */
class EngagementMeterEntry extends Model
{
    use HasUlids;

    protected $fillable = [
        'engagement_id', 'unit', 'quantity_milli', 'note',
        'source_type', 'source_id', 'recorded_by_user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'occurred_at'    => 'datetime',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function quantity(): float
    {
        return round($this->quantity_milli / 1000, 3);
    }

    /**
     * Whether this entry points at something that happened, or is somebody's
     * word for it.
     *
     * An agent's actions are derived from the ledger; a person's hours are
     * asserted. The record should say which, and this is the only place that
     * distinction survives.
     */
    public function isDerived(): bool
    {
        return $this->source_type !== null;
    }
}
