<?php

namespace Tests\Feature;

use App\Services\Agent\EvidenceStaleness;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Staleness used to be question 5 on the Steward's task list. It is a date
 * comparison, so it is now code, and these are the properties that made it
 * worth moving: it is exact, it repeats, and it cannot cite an item that was
 * never retrieved.
 */
class EvidenceStalenessTest extends TestCase
{
    private function source(string $id, ?int $ageDays, string $name = 'Load schedule'): array
    {
        return [
            'evidence_item_id'    => $id,
            'evidence_version_id' => "ver_{$id}",
            'name'                => $name,
            'age_days'            => $ageDays,
        ];
    }

    #[Test]
    public function it_flags_only_what_is_past_the_threshold(): void
    {
        $flagged = EvidenceStaleness::detect([
            $this->source('a', 10),
            $this->source('b', 45),
            $this->source('c', 31),
        ], afterDays: 30);

        $this->assertSame(['b', 'c'], array_column($flagged, 'evidence_item_id'));
    }

    #[Test]
    public function the_threshold_itself_is_not_stale(): void
    {
        // Exactly N days old is "N days untouched", not "past N days". An
        // off-by-one here would flag every item the day it becomes eligible.
        $this->assertSame([], EvidenceStaleness::detect([$this->source('a', 30)], afterDays: 30));
        $this->assertCount(1, EvidenceStaleness::detect([$this->source('a', 31)], afterDays: 30));
    }

    #[Test]
    public function it_reports_oldest_first(): void
    {
        $flagged = EvidenceStaleness::detect([
            $this->source('a', 40),
            $this->source('b', 120),
            $this->source('c', 60),
        ], afterDays: 30);

        $this->assertSame(['b', 'c', 'a'], array_column($flagged, 'evidence_item_id'));
        $this->assertSame([120, 60, 40], array_column($flagged, 'age_days'));
    }

    #[Test]
    public function an_undated_item_is_not_treated_as_old(): void
    {
        $flagged = EvidenceStaleness::detect([
            $this->source('a', null),
            $this->source('b', 90),
        ], afterDays: 30);

        $this->assertSame(['b'], array_column($flagged, 'evidence_item_id'));
    }

    #[Test]
    public function a_threshold_of_zero_turns_it_off_rather_than_flagging_everything(): void
    {
        $sources = [$this->source('a', 5), $this->source('b', 500)];

        $this->assertSame([], EvidenceStaleness::detect($sources, afterDays: 0));
        $this->assertSame([], EvidenceStaleness::detect($sources, afterDays: -1));
    }

    #[Test]
    public function it_carries_the_version_id_so_a_flag_can_be_traced(): void
    {
        $flagged = EvidenceStaleness::detect([$this->source('a', 90)], afterDays: 30);

        $this->assertSame('ver_a', $flagged[0]['evidence_version_id']);
        $this->assertStringContainsString('90 days old', $flagged[0]['reason']);
        $this->assertStringContainsString('threshold 30 days', $flagged[0]['reason']);
    }

    #[Test]
    public function the_same_evidence_gives_the_same_answer_every_time(): void
    {
        $sources = [$this->source('a', 90), $this->source('b', 12), $this->source('c', 400)];

        $first = EvidenceStaleness::detect($sources, afterDays: 30);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, EvidenceStaleness::detect($sources, afterDays: 30));
        }
    }

    #[Test]
    public function it_falls_back_to_the_configured_threshold(): void
    {
        config(['circle.staleness.after_days' => 7]);

        $flagged = EvidenceStaleness::detect([$this->source('a', 10)]);

        $this->assertCount(1, $flagged);
        $this->assertStringContainsString('threshold 7 days', $flagged[0]['reason']);
    }
}
