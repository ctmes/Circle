<?php

namespace App\Services\Convening;

use App\Services\Goals\GoalService;

/**
 * What the model is asked for when it reads an engagement of terms (spec §23).
 *
 * Separate from OutputSchema because it answers a different question. A brief
 * asks "what does this evidence show"; this asks "what shape of mission does
 * this contract describe". Forcing the second into the first would have meant
 * a plan arriving as prose inside a claim.
 *
 * The same bar applies as on OutputSchema, and it is the reason this schema
 * looks the way it does: every property here is a question put to a language
 * model, and it earns its place only if a language model is the only thing
 * that can answer it. Three consequences, each of which cost a field:
 *
 *  - **No dates are computed here.** A schedule is either a date the document
 *    literally states, or a quantity and a unit measured from commencement.
 *    "Four weeks after the commencement date" is read by the model; turning
 *    that into the 16th of October is done by PlanResolver, against a calendar,
 *    identically on every re-run. A model that returned the date itself would
 *    be doing arithmetic it cannot be audited on.
 *
 *  - **No structure is computed here.** `level` says how deeply a step is
 *    nested and nothing else: no ids, no parent references, no positions, no
 *    outline arithmetic. The tree is assembled in PHP from the order of the
 *    array, where the depth cap is enforced and a malformed jump is visible.
 *
 *  - **No party resolution.** `responsible_party` is a name as the document
 *    writes it. Matching that to a party — or failing to, and leaving the step
 *    unassigned — is a string comparison, and a string comparison does not need
 *    a model.
 *
 * `basis` is the field that most repays reading. It is not confidence and it is
 * not a percentage: it says whether the document stated this or the model
 * filled a gap, which is the one thing a reviewer needs in order to know which
 * lines to read carefully. A 0-1 float would be false precision over a
 * twenty-row table nobody can calibrate.
 */
class ConveningSchema
{
    /**
     * Structured outputs compiles a grammar from this schema and refuses one
     * with more than 24 optional parameters, counting every nesting level:
     * an optional object costs one for itself and one for each optional
     * property inside it, at every place it is inlined.
     *
     * This schema inlines `schedule` four times and `citation` twice, so it ran
     * to 34 and was refused. Three things brought it to 23, and each is an
     * improvement on its own terms rather than a concession:
     *
     *  - `basis` is required on the mission, on every party and on every step.
     *    It should always have been: a line nobody marked stated or inferred is
     *    the one line a reviewer cannot act on, and defaulting it to `inferred`
     *    in the resolver was quietly answering a question on the model's behalf.
     *  - `excerpt` is required on a citation. A page number alone is a weak
     *    citation — a quotation is one somebody can search the document for,
     *    and PlanResolver already keeps the excerpt when the page turns out to
     *    be wrong.
     *  - The mission lost its citation, being a synthesis of a whole document
     *    rather than a quotation from a clause.
     *
     * StructuredSchemaTest holds the count under the limit, so the next field
     * added here fails in a test rather than in front of somebody's contract.
     */
    public const MAX_OPTIONAL_PARAMETERS = 24;

    /**
     * The deepest a step may claim to be.
     *
     * Read from the goal tree's own cap rather than stated here, so the two can
     * never disagree: a schema that let a model return level 4 into a tree
     * configured for 2 would produce a plan the resolver had to flatten on
     * every run.
     *
     * One short of the cap, not at it. A reading that fills every level leaves
     * each of its leaves at the cap, and the first thing anybody does with a
     * plan read out of a contract is break a deliverable down into the work it
     * actually takes — which the tree would then refuse on every row the
     * machine wrote. The last level is kept for the people doing the work.
     */
    public static function maxLevel(): int
    {
        return max(1, GoalService::maxDepth() - 1);
    }

    public static function build(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['mission', 'parties', 'plan', 'open_questions', 'uncertainty'],
            'properties'           => [
                'mission'        => self::mission(),
                'parties'        => self::parties(),
                'plan'           => self::plan(),
                'open_questions' => self::openQuestions(),
                'uncertainty'    => [
                    'type'        => 'string',
                    'description' => 'What you could not determine from this document, and why. State it plainly.',
                ],
            ],
        ];
    }

    /**
     * The mission statement, and the two dates that bound it.
     *
     * `name` and `purpose` are asked for because naming an outcome from a
     * contract's recitals is judgement. Everything else about the Circle —
     * its owner, its organisation, its status — the application already knows
     * and never asks.
     */
    private static function mission(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['name', 'purpose', 'basis'],
            'properties'           => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'A short name for the outcome, not the team. Under 80 characters.',
                ],
                'purpose' => [
                    'type'        => 'string',
                    'description' => 'What has to be true for this engagement to be finished. Two or three sentences.',
                ],
                'commences'  => self::schedule('When the term starts, as the document expresses it.'),
                'concludes'  => self::schedule('When the term ends, as the document expresses it.'),
                'basis'      => self::basis(),
                // No citation on the mission. The name and the purpose are
                // syntheses of a whole document rather than quotations from a
                // clause, so the citation was the weakest of the three and it
                // is the one that pays for the optional-parameter budget below.
            ],
        ];
    }

    private static function parties(): array
    {
        return [
            'type'        => 'array',
            'description' => 'The organisations this engagement is between, as the document names them.',
            'items'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['display_name', 'party_role', 'basis'],
                'properties'           => [
                    'display_name' => [
                        'type'        => 'string',
                        'description' => 'The company name, not its defined term. "JWA Mats", not "the Supplier".',
                    ],
                    'defined_term' => [
                        'type'        => 'string',
                        'description' => 'What the document calls them throughout, e.g. "the Supplier". Used to match steps to parties.',
                    ],
                    'party_role' => [
                        'type'        => 'string',
                        // Deliberately not `convener`: which company convened
                        // the Circle is a fact the application holds, and a
                        // model reading a contract is in no position to
                        // overwrite it.
                        'enum'        => ['principal', 'contractor', 'subcontractor', 'advisor', 'observer'],
                        'description' => 'The commercial position this company holds under this document.',
                    ],
                    'basis'    => self::basis(),
                    'citation' => self::citation(),
                ],
            ],
        ];
    }

    /**
     * The plan, flat.
     *
     * Flat rather than nested for two reasons. Recursive `$ref` schemas are the
     * least well-supported corner of structured output across providers, and a
     * flat array with an explicit level is something the resolver can repair:
     * a step that claims level 3 under a level 1 parent is attached one below
     * its parent and reported, where a malformed nested object would have to be
     * thrown away whole.
     */
    private static function plan(): array
    {
        return [
            'type'        => 'array',
            'description' => 'The work, in the order the document sets it out. Use level to nest: '
                . 'a step at level 2 belongs to the most recent step at level 1 above it.',
            'items'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['level', 'title', 'basis'],
                'properties'           => [
                    'level' => [
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => self::maxLevel(),
                        'description' => 'Nesting depth. 1 is a top-level phase or deliverable.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'What has to be achieved, phrased as an outcome. Under 120 characters.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'What the document says about this step, in your own words. Omit rather than pad.',
                    ],
                    'acceptance_condition' => [
                        'type'        => 'string',
                        'description' => 'How this step is judged complete, if the document says. Quote the substance of the test.',
                    ],
                    'responsible_party' => [
                        'type'        => 'string',
                        'description' => 'The company or defined term the document makes responsible. Leave out if it does not say.',
                    ],
                    'starts'  => self::schedule('When this step starts, as the document expresses it.'),
                    'due'     => self::schedule('When this step is due, as the document expresses it.'),
                    'clause'  => [
                        'type'        => 'string',
                        'description' => 'Where this came from in the document, as it is numbered there, e.g. "Schedule 2, cl 4.3".',
                    ],
                    'basis'    => self::basis(),
                    'citation' => self::citation(),
                ],
            ],
        ];
    }

    /**
     * A date, the way a contract actually writes one.
     *
     * Both branches are optional and the instruction is to use exactly one,
     * rather than a `oneOf` — structured output handles a flat object with
     * `additionalProperties: false` far more reliably than a discriminated
     * union, and PlanResolver has to cope with an empty object regardless.
     *
     * The unit list is the point. A model asked for "days" would have to decide
     * whether three months is ninety days and whether ten business days crosses
     * a weekend. Both of those are calendar questions, so both are answered in
     * PHP.
     */
    private static function schedule(string $description): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'description'          => $description . ' Give either `on` or `after_commencement`, never both, '
                . 'and never a date you worked out yourself — if the document says "four weeks after commencement", '
                . 'say that.',
            'properties' => [
                'on' => [
                    'type'        => 'string',
                    'description' => 'A calendar date the document states outright, as YYYY-MM-DD.',
                ],
                'after_commencement' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['value', 'unit'],
                    'description'          => 'A period measured from the commencement date.',
                    'properties'           => [
                        'value' => ['type' => 'integer', 'minimum' => 0],
                        'unit'  => [
                            'type' => 'string',
                            'enum' => ['days', 'business_days', 'weeks', 'months'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Did the document say this, or did you infer it?
     *
     * The single most useful column on the review screen, and the reason this
     * whole flow can present a plan without pretending the document contained
     * one. An inferred step is not a defect — a contract that names a
     * deliverable and no milestone still implies work — but it is a different
     * kind of line and a reviewer must be able to see which is which at a
     * glance.
     */
    private static function basis(): array
    {
        return [
            'type' => 'string',
            'enum' => ['stated', 'inferred'],
            'description' => 'stated: the document says this. inferred: you concluded it from what the document says.',
        ];
    }

    /**
     * Where in the document this came from.
     *
     * `evidence_version_id` is optional on purpose. Convening is usually one
     * document, and asking a model to copy a ULID onto forty rows is forty
     * chances to mistype an identifier that the resolver would then have to
     * discard. Where exactly one source was supplied the resolver fills it in;
     * where several were, it is required and anything unresolvable is dropped —
     * the same rule AgentRunner applies to a claim.
     */
    private static function citation(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['excerpt'],
            'description'          => 'Where in the supplied document this comes from.',
            'properties'           => [
                'evidence_version_id' => [
                    'type'        => 'string',
                    'description' => 'Required only if you were given more than one source. Never invent one.',
                ],
                'page' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Page number, only if the supplied page index supports it.',
                ],
                'excerpt' => [
                    'type'        => 'string',
                    'description' => 'A short quotation from the document, under 300 characters.',
                ],
            ],
        ];
    }

    private static function openQuestions(): array
    {
        return [
            'type'        => 'array',
            'description' => 'What this document leaves unsettled that somebody has to resolve before work starts.',
            'items'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['question', 'why_it_matters'],
                'properties'           => [
                    'question'       => ['type' => 'string'],
                    'why_it_matters' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
