<?php

namespace App\Services\Agent;

/**
 * Which retrieved evidence has gone untouched long enough to be worth a second
 * look (spec §9).
 *
 * This used to be question 5 on the Steward's task list: "flag items older than
 * N days". That is a date comparison, and it was being paid for at model rates,
 * answered non-deterministically, and returned with an `evidence_item_id` the
 * model could get wrong — three costs for arithmetic the retrieval layer had
 * already done. `age_days` is computed in AgentRetrieval before the prompt is
 * even built.
 *
 * So it is computed here instead. The rule is a threshold, the threshold is
 * configuration, and the same Circle asked twice now gets the same answer.
 *
 * What is deliberately *not* here: any judgement about whether a given document
 * actually went out of date. A drawing from 2019 may be perfectly current and a
 * price list from last week may not be. That question needs a person who knows
 * the job, and neither a clock nor a model should pretend to answer it — which
 * is why what comes out of here is phrased as "unchanged for N days", a fact,
 * rather than "stale", a conclusion.
 */
class EvidenceStaleness
{
    /**
     * @param  list<array>  $sources  As built by AgentRetrieval::gather().
     * @return list<array{evidence_item_id: string, evidence_version_id: string, name: string, age_days: int, reason: string}>
     */
    public static function detect(array $sources, ?int $afterDays = null): array
    {
        $afterDays ??= (int) config('circle.staleness.after_days');

        // A threshold of zero or less would flag everything, which is the same
        // as flagging nothing. Treat it as the off switch it obviously is.
        if ($afterDays <= 0) {
            return [];
        }

        $flagged = [];

        foreach ($sources as $source) {
            $age = $source['age_days'] ?? null;

            // Retrieval could not date it. Unknown is not old, and guessing
            // here would put an item in front of somebody for no reason.
            if (! is_numeric($age)) {
                continue;
            }

            $age = (int) $age;

            if ($age <= $afterDays) {
                continue;
            }

            $flagged[] = [
                'evidence_item_id'    => $source['evidence_item_id'],
                'evidence_version_id' => $source['evidence_version_id'],
                'name'                => $source['name'] ?? 'unnamed item',
                'age_days'            => $age,
                'reason'              => sprintf(
                    'Current version is %d days old and has not been superseded (threshold %d days). '
                    . 'Confirm it still reflects the work.',
                    $age,
                    $afterDays,
                ),
            ];
        }

        // Oldest first: if somebody only works the top of the list, that is the
        // half of it most likely to have drifted.
        usort($flagged, fn (array $a, array $b) => $b['age_days'] <=> $a['age_days']);

        return $flagged;
    }
}
