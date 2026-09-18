<?php

namespace App\Services\Agent\Tools;

use App\Enums\ActorType;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\EvidenceItem;
use App\Services\Evidence\EvidenceService;

/**
 * Mark an evidence item as no longer current.
 *
 * The one write against evidence an agent gets, and it is deliberately the
 * mildest one available: staleness is a flag beside the item, not a change to
 * it. The binary is untouched, the version lineage is untouched, and every
 * citation made against it still resolves — a reader is simply told the thing
 * they are looking at may have been overtaken.
 *
 * An agent noticing that a drawing predates a revision everyone has moved on
 * from is genuinely useful and genuinely hard for a person to catch across a
 * few hundred uploads. An agent deleting or superseding evidence is not on the
 * table and never will be.
 */
class FlagEvidenceStaleTool extends BaseTool
{
    public function __construct(private readonly EvidenceService $evidence) {}

    public function key(): string
    {
        return 'flag_evidence_stale';
    }

    public function name(): string
    {
        return 'Flag evidence as stale';
    }

    public function description(): string
    {
        return 'Mark an evidence item as possibly out of date, with a reason. '
            . 'The item and every citation against it are left intact — this only adds a warning beside it.';
    }

    public function sideEffect(): SideEffect
    {
        return SideEffect::CircleWrite;
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['evidence_item_id', 'reason'],
            'properties'           => [
                'evidence_item_id' => ['type' => 'string'],
                'reason'           => [
                    'type'        => 'string',
                    'description' => 'What suggests it has been overtaken. Cite the thing that supersedes it if you can.',
                ],
            ],
        ];
    }

    public function handle(AgentAction $action): array
    {
        $circle = $this->circle($action);
        $agent  = $this->agent($action);

        $itemId = (string) $this->requireArg($action, 'evidence_item_id');
        $reason = (string) $this->requireArg($action, 'reason');

        $item = EvidenceItem::whereKey($itemId)
            ->whereHas('resource', fn ($q) => $q->where('circle_id', $circle->id))
            ->first();

        if ($item === null) {
            throw new \RuntimeException(sprintf('No evidence item %s in this Circle.', $itemId));
        }

        // Takes an actor type rather than a user, so the flag reads as the
        // agent's in the register and in the packet.
        $this->evidence->markStale($item, ActorType::Agent, $agent->id, $reason);

        return [
            'evidence_item_id' => $item->id,
            'stale_at'         => $item->fresh()->stale_at?->toISOString(),
        ];
    }
}
