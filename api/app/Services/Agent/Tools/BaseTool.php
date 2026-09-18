<?php

namespace App\Services\Agent\Tools;

use App\Models\AgentAction;
use App\Models\AgentInstance;
use App\Models\Circle;

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

    /** The label an agent's writes are attributed with in the record. */
    protected function attribution(AgentAction $action): string
    {
        return $this->agent($action)->blueprint?->name ?? 'Agent';
    }
}
