<?php

namespace App\Services\Agent;

use App\Models\AgentTool;

/**
 * The response schema every agent answers against (spec §9, §20.4).
 *
 * One builder rather than one schema per agent, so that an authored agent
 * cannot describe its output in a shape the persistence layer does not
 * understand. The model is never asked what kind of thing it is returning — it
 * fills in a structure the application already knows how to validate.
 *
 * `additionalProperties: false` everywhere is doing real work: without it a
 * model that invents `approved: true` produces output that reads, to a human
 * skimming the JSON, as though something had been approved.
 *
 * BEFORE ADDING A FIELD HERE
 *
 * Every property on this schema is a question put to a language model, and the
 * bar for adding one is that a language model is the only thing that can answer
 * it. If the answer is a threshold, a count, a date comparison, a lookup, a
 * sort, or anything else the application already knows, compute it — it will be
 * cheaper, exact, identical on a re-run, and incapable of naming a record that
 * was never retrieved.
 *
 * `potentially_stale` was on this schema and is the worked example: "which
 * items are older than N days" was being asked of a model that had to be told
 * the ages in the first place. It now lives in EvidenceStaleness, and the brief
 * is better for it in all four of those ways.
 *
 * What is left here is what actually needs judgement over prose: what the
 * evidence says, where it contradicts itself, what is missing, and what a human
 * now has to decide.
 */
class OutputSchema
{
    /**
     * @param  list<AgentTool>  $tools  Declared tools, if this agent may execute.
     */
    public static function build(array $tools = []): array
    {
        $schema = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['summary', 'status', 'claims', 'decision_drafts', 'missing_evidence'],
            'properties'           => [
                'summary' => [
                    'type'        => 'string',
                    'description' => 'A short mission-state summary for the Circle overview. Lead with blockers.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['on_track', 'at_risk', 'blocked', 'insufficient_evidence'],
                    'description' => 'Overall mission state as supported by the evidence supplied.',
                ],
                'uncertainty' => [
                    'type'        => 'string',
                    'description' => 'What you could not determine and why. State this plainly.',
                ],
                'claims'           => self::claims(),
                'decision_drafts'  => self::decisionDrafts(),
                'missing_evidence' => self::missingEvidence(),

                // No `potentially_stale`. Asking the model which items are
                // older than N days spent model tokens on a date comparison
                // the retrieval layer had already made, and let it answer with
                // an item id it could get wrong. EvidenceStaleness computes it
                // from the same sources after the run, for nothing.
            ],
        ];

        if ($tools !== []) {
            $schema['properties']['tool_calls'] = self::toolCalls($tools);
        }

        return $schema;
    }

    private static function claims(): array
    {
        return [
            'type'  => 'array',
            'items' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['statement', 'claim_type', 'confidence', 'citations'],
                'properties'           => [
                    'statement'  => ['type' => 'string'],
                    'claim_type' => [
                        'type' => 'string',
                        'enum' => ['factual', 'technical_assessment', 'commercial_assessment', 'risk', 'recommendation'],
                    ],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'citations'  => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'items'    => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['evidence_version_id'],
                            'properties'           => [
                                'evidence_version_id' => ['type' => 'string'],
                                'excerpt'             => ['type' => 'string'],
                                // Closed, and spelling out the three shapes
                                // rather than accepting any object.
                                //
                                // It was an open object, which structured
                                // outputs refuses outright — `additionalProperties`
                                // may only be false. Closing it is the better
                                // schema regardless: these five keys are exactly
                                // what citationTypeFor() reads, so an open object
                                // was inviting the model to invent a locator the
                                // application would then ignore.
                                'locator'             => [
                                    'type'                 => 'object',
                                    'additionalProperties' => false,
                                    'description'          => 'Precise location. Documents use page; spreadsheets use sheet and range; audio and video use seconds.',
                                    'properties'           => [
                                        'page'          => ['type' => 'integer'],
                                        'sheet'         => ['type' => 'string'],
                                        'range'         => ['type' => 'string', 'description' => 'e.g. B7:D7'],
                                        'start_seconds' => ['type' => 'number'],
                                        'end_seconds'   => ['type' => 'number'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function decisionDrafts(): array
    {
        return [
            'type'  => 'array',
            'items' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['title', 'description'],
                'properties'           => [
                    'title'                   => ['type' => 'string'],
                    'description'             => ['type' => 'string'],
                    'suggested_approver_role' => ['type' => 'string'],
                    'blocking'                => ['type' => 'boolean'],
                ],
            ],
        ];
    }

    private static function missingEvidence(): array
    {
        return [
            'type'  => 'array',
            'items' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['description'],
                'properties'           => [
                    'description'    => ['type' => 'string'],
                    'why_it_matters' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * What the agent wants to *do*, as opposed to what it wants to say.
     *
     * `tool_key` is constrained to an enum of the tools this blueprint actually
     * declares, so a model cannot name a command that was never approved for
     * it. The runner re-checks the key against the database regardless — a
     * schema is a hint to the model, never a security boundary.
     *
     * Conspicuously absent: any way to name the party the action runs for. The
     * agent acts for the party that owns it, resolved by the runner from the
     * blueprint's organisation. Letting the model choose would let an agent
     * move liability onto a company that never agreed to carry it, which is the
     * one thing the ledger exists to prevent.
     *
     * @param  list<AgentTool>  $tools
     */
    private static function toolCalls(array $tools): array
    {
        return [
            'type'        => 'array',
            'description' => 'Actions to propose. Every one is recorded, and most need a named human to approve before anything happens.',
            'items'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['tool_key', 'intent', 'arguments'],
                'properties'           => [
                    'tool_key' => [
                        'type' => 'string',
                        'enum' => array_values(array_map(fn (AgentTool $t) => $t->key, $tools)),
                    ],
                    'intent' => [
                        'type'        => 'string',
                        'description' => 'One sentence an approver can judge without reading the arguments.',
                    ],
                    // A JSON object, carried as a string.
                    //
                    // Every other field on this schema has a shape we control;
                    // this one's shape belongs to whichever tool is being
                    // called, and there is no way to express "any object" —
                    // structured outputs requires `additionalProperties: false`
                    // everywhere, which for a property-less object means "no
                    // arguments at all". A string the runner decodes keeps the
                    // tool's own contract intact, and a tool whose arguments
                    // will not parse is refused at the ledger rather than
                    // executed with half of them.
                    'arguments' => [
                        'type'        => 'string',
                        'description' => 'The arguments for this tool as a JSON object, serialised to a string. '
                            . 'For example: {"goal_id":"01H...","progress":40}',
                    ],
                ],
            ],
        ];
    }
}
