<?php

namespace App\Services\Agent;

use App\Models\Circle;

/**
 * What the runner needs from a prompt, whoever wrote it.
 *
 * Two implementations, and the difference between them is the whole point of
 * the amendment. `StewardPrompt` is ours: its mandate is part of the product's
 * guarantees and no customer can edit it. `AuthoredPrompt` is assembled from a
 * blueprint someone wrote in the studio, and everything customer-supplied in it
 * sits *inside* boundaries the runner controls.
 *
 * Both must be able to say which version produced a given output, because a
 * brief read six months from now has to be traceable to the exact instructions
 * behind it — and for an authored agent that means the blueprint version too.
 */
interface AgentPromptContract
{
    public function systemPrompt(): string;

    /** @param  list<array>  $sources */
    public function userPrompt(Circle $circle, array $sources, array $openQuestions = []): string;

    public function outputSchema(): array;

    /** Recorded on the run and on every artifact it produces. */
    public function version(): string;

    /** The label stamped on anything this agent puts into the record. */
    public function derivedLabel(): string;
}
