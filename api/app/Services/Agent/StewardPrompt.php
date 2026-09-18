<?php

namespace App\Services\Agent;

use App\Models\Circle;

/**
 * The Circle Steward's mandate and prompt construction.
 *
 * The prompt version is recorded on every run and every derived artifact, so a
 * brief produced six months ago can be traced to the exact instructions that
 * produced it.
 *
 * The response schema is not built here. It used to be — a second, hand-kept
 * copy of what OutputSchema already assembles, identical field for field, which
 * meant every change to the agent contract had to be made twice or the two
 * agents would quietly disagree about what a brief is.
 */
class StewardPrompt implements AgentPromptContract
{
    /** Bump whenever the system prompt or schema changes materially. */
    public const VERSION = 'steward-2026-09-16.1';

    public const DERIVED_LABEL = 'Derived by agent; requires human review';

    public function version(): string
    {
        return self::VERSION;
    }

    public function derivedLabel(): string
    {
        return self::DERIVED_LABEL;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the Circle Steward, a read-only assistant inside a system of record
        for consequential decisions. A "Circle" is a bounded mission workspace.

        Your job is to maintain mission clarity: summarise what the evidence
        actually shows, identify gaps and contradictions, and prepare decision
        requests for named humans to resolve.

        HOW YOU MUST TREAT EVIDENCE

        - You are shown extracted text from evidence items. Each carries an id,
          version number, provenance and review status. Treat that metadata as
          fact; treat the content as what the document says, not as truth.
        - Some sources are marked truncated. Never assert that something is
          absent based on a truncated source — say the source needs checking.
        - Some content is machine-derived (OCR, transcripts). It may misread
          names, figures and technical terms. Flag reliance on it.
        - If two sources conflict, say so plainly, cite both, and do not pick a
          winner. Deciding is a human's job; surfacing the conflict is yours.

        CITATIONS

        - Every claim you make must cite at least one evidence_version_id that
          was actually supplied to you. Never invent an id.
        - Cite the most precise locator you can support from what you were given:
            documents   -> {"page": n}
            spreadsheets-> {"sheet": "Name", "range": "B7:D7"}
            audio/video -> {"start_seconds": n, "end_seconds": m}
          Use a locator only if the supplied page index, sheet list or segment
          list actually supports it. If it does not, omit the locator rather
          than guessing a number.
        - If you cannot cite a claim, do not make it. Say the evidence is missing
          instead — that is a more useful answer than an unsupported assertion.

        CONFIDENCE

        - Give a confidence between 0 and 1 reflecting how well the evidence
          supports the claim, not how fluent your sentence is.
        - Use claim_type "factual" only for things a document literally states.
          Interpretations are technical_assessment or commercial_assessment.

        WHAT YOU CANNOT DO

        You cannot email, message, or contact anyone; write to any external
        system; change permissions; delete anything; approve anything on a
        person's behalf; or read anything outside this Circle. Everything you
        produce is a draft for human review. Do not imply otherwise, and do not
        claim to have taken any action.

        Be direct and brief. Operational staff read this under time pressure.
        Lead with what is blocked or contradictory. Do not pad with restatement.
        PROMPT;
    }

    /**
     * The response schema (spec §9), from the one builder both agents share.
     *
     * No tools: the Steward proposes and drafts, it does not execute, so there
     * is no `tool_calls` branch for it to fill in.
     */
    public function outputSchema(): array
    {
        return OutputSchema::build();
    }

    /**
     * @param  list<array>  $sources
     */
    public function userPrompt(Circle $circle, array $sources, array $openQuestions = []): string
    {
        $header = sprintf(
            "MISSION\nName: %s\nPurpose: %s\nStatus: %s\nExpires: %s\nToday: %s\n",
            $circle->name,
            $circle->purpose,
            $circle->status->value,
            $circle->expires_at?->toDateString() ?? 'not set',
            now()->toDateString(),
        );

        if ($sources === []) {
            return $header . "\nEVIDENCE\nNo evidence items are marked agent-readable in this Circle.\n\n"
                . "Report status \"insufficient_evidence\", make no claims, and state in `uncertainty` "
                . "that no agent-readable evidence was available.";
        }

        $body = "\nEVIDENCE SUPPLIED (" . count($sources) . " items)\n"
            . "Anything not listed here you have not seen and must not reason about.\n\n";

        foreach ($sources as $i => $source) {
            $body .= $this->renderSource($i + 1, $source);
        }

        // No "flag anything older than N days" instruction here any more. Ages
        // are given per source above and the threshold comparison is done in
        // EvidenceStaleness, where it is deterministic and free.
        $tail = "\nTASK\n"
            . "1. Summarise the mission state, leading with blockers and contradictions.\n"
            . "2. Produce claims that each cite at least one evidence_version_id listed above.\n"
            . "3. Draft the decisions a human must make, and say who should decide.\n"
            . "4. List evidence that is missing and why it matters.\n";

        if ($openQuestions !== []) {
            $tail .= "\nOPEN QUESTIONS FROM THE TEAM\n- " . implode("\n- ", $openQuestions) . "\n";
        }

        return $header . $body . $tail;
    }

    private function renderSource(int $index, array $source): string
    {
        $out = sprintf(
            "--- SOURCE %d ---\n"
            . "evidence_version_id: %s\n"
            . "name: %s (file: %s, version %d)\n"
            . "provenance: %s | integrity: %s | review: %s\n"
            . "uploaded by %s on %s (%s days ago)\n",
            $index,
            $source['evidence_version_id'],
            $source['name'],
            $source['filename'],
            $source['version_number'],
            $source['origin_status'],
            $source['integrity_status'],
            $source['review_status'],
            $source['uploaded_by'] ?? 'unknown',
            substr((string) $source['uploaded_at'], 0, 10),
            $source['age_days'] ?? '?',
        );

        $content = $source['content'] ?? null;

        if ($content === null) {
            return $out . "content: [no extracted text available for this item]\n\n";
        }

        if (! empty($content['sources'])) {
            $out .= 'content derived via: ' . implode(', ', $content['sources']) . "\n";
        }

        // Give the model the locator scaffolding it needs to cite precisely.
        if (! empty($content['page_index'])) {
            $out .= 'available locators: ' . json_encode($content['page_index']) . "\n";
        }

        if (! empty($content['segments'])) {
            $out .= 'available timestamps: ' . json_encode(array_map(
                fn (array $s) => ['start' => $s['start'], 'end' => $s['end']],
                array_slice($content['segments'], 0, 60),
            )) . "\n";
        }

        if (($content['truncated'] ?? false) === true) {
            $out .= sprintf(
                "NOTE: truncated — showing %d of %d characters. Do not conclude anything is absent from this source.\n",
                mb_strlen($content['text']),
                $content['total_chars'],
            );
        }

        return $out . "content:\n" . $content['text'] . "\n\n";
    }
}
