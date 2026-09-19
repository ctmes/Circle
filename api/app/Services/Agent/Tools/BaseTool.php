<?php

namespace App\Services\Agent\Tools;

use App\Models\AgentAction;
use App\Models\AgentInstance;
use App\Models\Circle;
use App\Models\Goal;

/**
 * Argument handling shared by the built-in tools.
 *
 * Everything reads from `arguments_json` as it was stored at proposal time.
 * That is the copy a human read before approving, and it is the only copy any
 * handler is allowed to see — which is why there is no accessor here that
 * reaches back to the run or the model output.
 */
abstract class BaseTool implements ToolHandler
{
    protected function circle(AgentAction $action): Circle
    {
        $circle = $action->circle;

        // Belt and braces: the gate refused a closed Circle at proposal time,
        // but an approval can outlive the Circle it was granted in, and this is
        // the last point before something actually happens.
        abort_if($circle->isClosed(), 422, 'This Circle is closed. Nothing was done.');

        return $circle;
    }

    protected function agent(AgentAction $action): AgentInstance
    {
        $agent = $action->agentInstance;

        abort_if($agent === null, 422, 'The agent that proposed this no longer exists.');

        return $agent;
    }

    /** @return mixed */
    protected function arg(AgentAction $action, string $key, mixed $default = null): mixed
    {
        return ($action->arguments_json ?? [])[$key] ?? $default;
    }

    protected function requireArg(AgentAction $action, string $key): mixed
    {
        $value = $this->arg($action, $key);

        if ($value === null || $value === '') {
            throw new \RuntimeException(sprintf('The tool "%s" needs a "%s" argument.', $this->key(), $key));
        }

        return $value;
    }

    /**
     * A date the agent supplied, or null.
     *
     * Unparseable dates throw rather than silently becoming null: a commitment
     * that quietly loses its deadline is worse than one that fails to be made.
     */
    protected function dateArg(AgentAction $action, string $key): ?\DateTimeImmutable
    {
        $raw = $this->arg($action, $key);

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $raw);
        } catch (\Exception) {
            throw new \RuntimeException(sprintf('"%s" is not a date this tool can read: %s', $key, $raw));
        }
    }

    /** A goal in this action's Circle, or a refusal naming the id it could not find. */
    protected function goal(AgentAction $action, string $key = 'goal_id'): Goal
    {
        $id   = (string) $this->requireArg($action, $key);
        $goal = Goal::where('circle_id', $this->circle($action)->id)->find($id);

        if ($goal === null) {
            throw new \RuntimeException(sprintf('No goal %s in this Circle.', $id));
        }

        return $goal;
    }

    /**
     * Whether this agent may change this goal at all.
     *
     * An agent a customer wrote acts for one company, and work another company
     * is answerable for is not its to report on, reschedule or close — the
     * same rule ReportGoalProgressTool has always applied. The product's own
     * agents are not partisan in that way: the Scribe reads the meeting record
     * of the Circle as a whole and keeps the whole plan current, which is the
     * only way a transcript saying "Northline have signed off the pad" can
     * mean anything.
     */
    protected function assertMayTouch(AgentAction $action, Goal $goal): void
    {
        if ($this->agent($action)->blueprint?->is_system) {
            return;
        }

        if ($goal->responsible_party_id !== null
            && $goal->responsible_party_id !== $action->on_behalf_of_party_id) {
            throw new \RuntimeException('That goal belongs to another party. You can only change your own work.');
        }
    }

    /**
     * The goal and every open goal beneath it, deepest first.
     *
     * Closing a phase closes the work inside it — "phase one is done" does not
     * mean "the phase is done and its three packages are still open" — and
     * walking the deepest first means each child is settled before the parent
     * that derives its progress from them.
     *
     * @return list<Goal>
     */
    protected function withOpenDescendants(Goal $goal): array
    {
        $out = [];

        foreach ($goal->children()->get() as $child) {
            if ($child->status->isOpen()) {
                array_push($out, ...$this->withOpenDescendants($child));
            }
        }

        $out[] = $goal;

        return $out;
    }

    /** The label an agent's writes are attributed with in the record. */
    protected function attribution(AgentAction $action): string
    {
        return $this->agent($action)->blueprint?->name ?? 'Agent';
    }
}
