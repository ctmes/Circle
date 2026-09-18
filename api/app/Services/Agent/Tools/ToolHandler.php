<?php

namespace App\Services\Agent\Tools;

use App\Enums\SideEffect;
use App\Models\AgentAction;

/**
 * One thing an approved agent action can actually do.
 *
 * `agent_actions` has recorded proposals and approvals correctly since the
 * amendment landed, and `AgentActionService::execute()` has always taken an
 * injected handler so the ledger's guarantees could be tested without any real
 * side effect existing. This is where the real side effects start existing.
 *
 * Three rules every handler is written against:
 *
 * It runs from the *stored* arguments, never from anything re-read from the
 * model. Between proposal and approval a human looked at those arguments and
 * agreed to them; executing anything else makes the approval a lie.
 *
 * It attributes the result to the agent, not to the human who approved it. A
 * goal an agent created should read as the agent's in the record, with the
 * approver named alongside — collapsing the two loses the fact that a machine
 * drafted it.
 *
 * It declares its own side effect, and that declaration is what the approval
 * rules are computed from. A handler cannot be registered under a tool whose
 * declared consequence is milder than its own.
 */
interface ToolHandler
{
    /** Stable identifier. Matches `agent_tools.key`. */
    public function key(): string;

    public function name(): string;

    public function description(): string;

    /** The true consequence of running this, regardless of how it was declared. */
    public function sideEffect(): SideEffect;

    /** JSON Schema for `arguments`, shown to the model and validated before execution. */
    public function inputSchema(): array;

    /**
     * Do the thing. Returns what is stored in `agent_actions.result_json`.
     *
     * Throwing is a normal outcome: the ledger records the failure with the
     * message and the action lands in `failed` rather than `executed`.
     *
     * @return array<string, mixed>
     */
    public function handle(AgentAction $action): array;
}
