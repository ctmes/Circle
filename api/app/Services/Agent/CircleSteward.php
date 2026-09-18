<?php

namespace App\Services\Agent;

use App\Models\AgentBlueprint;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\Circle;
use App\Models\User;

/**
 * The Circle Steward (spec §9).
 *
 * Once this class was the only way an agent could run, and it held all the
 * machinery. That machinery now lives in AgentRunner and serves every agent,
 * which leaves the Steward as what it always should have been: a mandate and
 * a prompt.
 *
 * It stays a separate class rather than a row someone can edit because its
 * guarantees are the product's, not a customer setting. `propose` mode, no
 * tools, and a prompt nobody outside this repository can change — an authored
 * agent can be given a wider mandate, but the thing that ships turned on by
 * default cannot.
 */
class CircleSteward
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly StewardPrompt $prompt,
    ) {}

    public function runBrief(Circle $circle, User $triggeredBy, array $openQuestions = []): AgentRun
    {
        return $this->runner->run(
            agent: $this->instanceFor($circle),
            triggeredBy: $triggeredBy,
            prompt: $this->prompt,
            openQuestions: $openQuestions,
            runType: 'steward_brief',
        );
    }

    /** Lazily binds the Steward blueprint to this Circle. */
    public function instanceFor(Circle $circle): AgentInstance
    {
        $blueprint = AgentBlueprint::where('key', AgentBlueprint::STEWARD)->firstOrFail();

        return AgentInstance::firstOrCreate(
            ['agent_blueprint_id' => $blueprint->id, 'circle_id' => $circle->id],
            ['status' => 'active'],
        )->load('blueprint');
    }
}
