<?php

namespace App\Services\Agent\Tools;

use App\Models\AgentAction;
use App\Models\AgentTool;

/**
 * The tools that exist, as opposed to the tools a blueprint claims.
 *
 * A blueprint can declare a tool called anything. Whether that tool *does*
 * anything depends on whether a handler is registered under its key — so an
 * author cannot conjure a capability by naming one, and a blueprint edited to
 * point at a tool that was later removed fails loudly at execution rather than
 * silently succeeding.
 *
 * The registry is also where the declared consequence is reconciled with the
 * real one. `agent_tools.side_effect` decides who has to approve; this class
 * refuses to execute when the handler's own classification is more severe than
 * what the approver was shown. Otherwise an author could register a payment
 * against a tool declared `circle_write` and collect a reviewer's signature for
 * something that needed the owner's.
 */
class ToolRegistry
{
    /** @var array<string, ToolHandler> */
    private array $handlers = [];

    /** @param  iterable<ToolHandler>  $handlers */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(ToolHandler $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    public function get(string $key): ?ToolHandler
    {
        return $this->handlers[$key] ?? null;
    }

    /** @return list<ToolHandler> */
    public function all(): array
    {
        return array_values($this->handlers);
    }

    /**
     * The handler for an approved action, or a refusal explaining why not.
     *
     * @throws \RuntimeException when no handler can safely run this action
     */
    public function handlerFor(AgentAction $action): ToolHandler
    {
        $handler = $this->get($action->tool_key);

        if ($handler === null) {
            throw new \RuntimeException(sprintf(
                'No handler is registered for the tool "%s". Nothing was done.',
                $action->tool_key,
            ));
        }

        // The severity ordering matters more than the enum's declaration order,
        // so it is spelled out rather than inferred.
        $rank = [
            'none'           => 0,
            'circle_write'   => 1,
            'external_read'  => 2,
            'external_write' => 3,
            'financial'      => 4,
        ];

        $declared = $rank[$action->side_effect->value] ?? 99;
        $actual   = $rank[$handler->sideEffect()->value] ?? 99;

        if ($actual > $declared) {
            throw new \RuntimeException(sprintf(
                'The tool "%s" was approved as %s but actually performs %s. Refusing to run it.',
                $action->tool_key,
                $action->side_effect->value,
                $handler->sideEffect()->value,
            ));
        }

        return $handler;
    }

    /**
     * Everything a blueprint could be given, for the studio's tool picker.
     *
     * Returned as the shape `AgentTool` is created from, so adding a built-in
     * tool to an agent is one click rather than a form somebody fills in wrong.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        return array_map(fn (ToolHandler $h) => [
            'key'               => $h->key(),
            'name'              => $h->name(),
            'description'       => $h->description(),
            'side_effect'       => $h->sideEffect()->value,
            'input_schema_json' => $h->inputSchema(),
        ], $this->all());
    }

    /** Whether a declared tool has anything behind it. */
    public function isImplemented(AgentTool $tool): bool
    {
        return $this->has($tool->key);
    }
}
