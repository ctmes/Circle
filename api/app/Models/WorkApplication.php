<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\FeeBasis;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offer to do the work (spec §21.1).
 *
 * The half a branch cannot carry. The branch says what would change in the
 * plan; this says on what terms — and until the applicant is shortlisted there
 * is no branch at all, because a branch needs Circle membership and an
 * applicant has none.
 */
class WorkApplication extends Model
{
    use HasUlids;

    protected $fillable = [
        'work_opening_id', 'organisation_id', 'applicant_user_id',
        'agent_blueprint_version_id', 'goal_branch_id', 'status',
        'fee_basis', 'fee_amount_minor', 'currency', 'statement', 'availability',
        'admitted_party_id', 'submitted_at', 'shortlisted_at', 'decided_at',
        'decision_reason', 'decided_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status'           => ApplicationStatus::class,
            'fee_basis'        => FeeBasis::class,
            'fee_amount_minor' => 'integer',
            'submitted_at'     => 'datetime',
            'shortlisted_at'   => 'datetime',
            'decided_at'       => 'datetime',
        ];
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(WorkOpening::class, 'work_opening_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function blueprintVersion(): BelongsTo
    {
        return $this->belongsTo(AgentBlueprintVersion::class, 'agent_blueprint_version_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(GoalBranch::class, 'goal_branch_id');
    }

    public function admittedParty(): BelongsTo
    {
        return $this->belongsTo(CircleParty::class, 'admitted_party_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** An application offering an agent rather than a person. */
    public function isAgentApplication(): bool
    {
        return $this->agent_blueprint_version_id !== null;
    }

    /**
     * Whether this applicant is inside the Circle.
     *
     * Both halves are required, and the second is not redundant: an admitted
     * party can be withdrawn afterwards, and the status alone would then say
     * somebody has access that the gate has already taken away.
     */
    public function hasAccess(): bool
    {
        return $this->status->isAdmitted() && $this->admitted_party_id !== null;
    }
}
