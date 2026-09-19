<?php

namespace App\Services\Transcripts;

use App\Support\WorkingCalendar;

/**
 * What the Circle Scribe is asked for when it reads a meeting (spec §24).
 *
 * A list of operations against the plan, each one mapping to exactly one tool
 * the ledger already knows how to run. Not free-form tool calls: the Scribe's
 * output is applied with nobody checking it, and a closed vocabulary of five
 * verbs over goals it was shown by id is a much smaller surface for a
 * misreading to land on than "any tool, any arguments".
 *
 * Each operation is its own variant, and that is not decoration. The first
 * version was one flat object with an `op` field and fourteen optional
 * properties, and against a live model it came back with a `complete_goal`
 * carrying no evidence, an empty ref and a reason reading "placeholder" — a
 * shape that says everything is optional tells the model nothing about what
 * any one operation needs. As variants, a completion without a goal and a
 * quotation is not a thing the model can emit.
 *
 * The same bar as ConveningSchema:
 *
 *  - **No dates.** A meeting says "push delivery back a fortnight". The model
 *    returns the period and what it is measured from; WorkingCalendar counts.
 *  - **No invented ids.** A goal from the plan by the id it was shown, or one an
 *    earlier operation created, by a label. The service maps labels to ids.
 *  - **Evidence on every operation.** An autonomous write whose log line cannot
 *    quote the meeting is a write nobody can check afterwards.
 *
 * Optional parameters count against structured outputs' limit of 24, variants
 * included; this declares 16.
 */
class TranscriptSchema
{
    public const OPERATIONS = ['create_goal', 'update_goal', 'complete_goal', 'abandon_goal', 'create_commitment'];

    public static function build(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['summary', 'operations', 'uncertainty'],
            'properties'           => [
                'summary' => [
                    'type'        => 'string',
                    'description' => 'What this meeting decided, reported and changed about the work — three or four plain sentences.',
                ],
                'operations' => [
                    'type'        => 'array',
                    'description' => 'Changes to the plan, in the order they should be applied. Empty if the meeting changed nothing.',
                    'items'       => [
                        'anyOf' => [
                            self::complete(),
                            self::abandon(),
                            self::create(),
                            self::update(),
                            self::commitment(),
                        ],
                    ],
                ],
                'uncertainty' => [
                    'type'        => 'string',
                    'description' => 'What was discussed but not settled, and what you chose not to act on. '
                        . 'Write "Nothing." if there was none — never leave a placeholder.',
                ],
            ],
        ];
    }

    private static function complete(): array
    {
        return self::variant('complete_goal', 'The meeting said this work is done.', [
            'goal_id'  => self::goalId(),
            'evidence' => self::evidence(),
        ], ['goal_id', 'evidence']);
    }

    private static function abandon(): array
    {
        return self::variant('abandon_goal', 'The meeting decided this work is no longer needed.', [
            'goal_id'  => self::goalId(),
            'evidence' => self::evidence(),
            'reason'   => ['type' => 'string', 'description' => 'Why it was dropped, in the words of the meeting.'],
        ], ['goal_id', 'evidence', 'reason']);
    }

    private static function create(): array
    {
        return self::variant('create_goal', 'New work the meeting agreed to take on.', [
            'title'    => ['type' => 'string', 'description' => 'The outcome, not the activity.'],
            'evidence' => self::evidence(),
            'ref'      => [
                'type'        => 'string',
                'description' => 'A short label, e.g. "new-1", only if a later operation builds on this goal.',
            ],
            'parent_goal_id' => [
                'type'        => 'string',
                'description' => 'The goal this sits under, by id or ref. Omit for a top-level goal.',
            ],
            'description'          => ['type' => 'string'],
            'acceptance_condition' => ['type' => 'string', 'description' => 'How it is judged done, if the meeting said.'],
            'responsible_party_id' => [
                'type'        => 'string',
                'description' => 'The party that took it on, by id from the party list. Omit if unclear.',
            ],
            'due' => self::due(),
        ], ['title', 'evidence']);
    }

    private static function update(): array
    {
        return self::variant('update_goal', 'A change to a goal that is still open: wording, status, progress or date.', [
            'goal_id'              => self::goalId(),
            'evidence'             => self::evidence(),
            'title'                => ['type' => 'string'],
            'acceptance_condition' => ['type' => 'string'],
            'status'               => ['type' => 'string', 'enum' => ['active', 'blocked', 'in_review']],
            'progress'             => [
                'type'        => 'integer',
                'description' => 'Whole percent, 0-100, only if somebody gave a figure.',
            ],
            'due'    => self::due(),
            'reason' => ['type' => 'string', 'description' => 'Why, in the words of the meeting. Always give one for a moved date.'],
            'description' => ['type' => 'string'],
        ], ['goal_id', 'evidence']);
    }

    private static function commitment(): array
    {
        return self::variant('create_commitment', 'A specific, dated obligation someone took on.', [
            'title'       => ['type' => 'string'],
            'evidence'    => self::evidence(),
            'goal_id'     => [
                'type'        => 'string',
                'description' => 'The goal this serves, by id or ref.',
            ],
            'description' => ['type' => 'string'],
            'due'         => self::due(),
        ], ['title', 'evidence']);
    }

    /**
     * @param  array<string, array>  $properties
     * @param  list<string>  $required
     */
    private static function variant(string $op, string $description, array $properties, array $required): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'description'          => $description,
            'required'             => array_values(array_merge(['op'], $required)),
            'properties'           => array_merge(['op' => ['type' => 'string', 'const' => $op]], $properties),
        ];
    }

    private static function goalId(): array
    {
        return [
            'type'        => 'string',
            'description' => 'An id from the current plan, or the ref of a goal created earlier in this list.',
        ];
    }

    private static function evidence(): array
    {
        return [
            'type'        => 'string',
            'description' => 'A short quotation from the transcript that this operation rests on. Never empty.',
        ];
    }

    /**
     * A date, the way a meeting says one — one of three shapes, each complete.
     *
     * Variants rather than three optional members, for the same reason the
     * operations are variants: "give exactly one of these" is an instruction a
     * schema can enforce, where three optionals invite none or all three.
     */
    private static function due(): array
    {
        $period = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['value', 'unit'],
            'properties'           => [
                'value' => ['type' => 'integer'],
                'unit'  => ['type' => 'string', 'enum' => WorkingCalendar::UNITS],
            ],
        ];

        return [
            'description' => 'Only if the meeting set or moved a date. Never a date you worked out yourself.',
            'anyOf'       => [
                [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['on'],
                    'properties'           => [
                        'on' => ['type' => 'string', 'description' => 'A calendar date somebody said, as YYYY-MM-DD.'],
                    ],
                ],
                [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['from_meeting'],
                    'properties'           => [
                        'from_meeting' => $period + ['description' => '"within two weeks": measured from the meeting date.'],
                    ],
                ],
                [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['shift'],
                    'properties'           => [
                        'shift' => $period + ['description' => '"push it back a week": measured from the current due date. Negative brings it forward.'],
                    ],
                ],
            ],
        ];
    }
}
