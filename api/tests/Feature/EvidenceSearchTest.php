<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\Permission;
use App\Enums\ProcessingStatus;
use App\Models\DerivedArtifact;
use App\Models\ResourceAccessOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Search that returns a citable locator (spec §8, §12).
 *
 * The point of these tests is not that search finds the file. It is that the
 * answer says *where* — page 4, Load Schedule!C2:D2, 00:02:13 — and that the
 * locator it hands back is one the claim composer accepts unchanged.
 */
class EvidenceSearchTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
    }

    public function test_a_document_hit_resolves_to_the_page_that_matched(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $pages = [
            ['page' => 1, 'text' => 'Cover sheet and revision history for the access package.'],
            ['page' => 2, 'text' => 'Site constraints, welfare arrangements and parking.'],
            ['page' => 4, 'text' => 'The lifting operation requires a 95 tonne crawler crane positioned east of the abutment.'],
        ];

        $this->makeEvidence(
            $circle,
            $owner,
            'Crane method statement',
            extractedText: implode("\f", array_column($pages, 'text')),
            extractionExtra: ['pages' => $pages, 'page_count' => count($pages)],
        );

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/circles/{$circle->id}/search?q=crawler+crane")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.citation_type', 'document_page')
            ->assertJsonPath('data.0.locator.page', 4)
            ->assertJsonPath('data.0.locator_label', 'page 4')
            ->assertJsonPath('data.0.name', 'Crane method statement')
            ->assertJsonPath('data.0.machine_read', false);

        // The snippet arrives with the matched words wrapped rather than as
        // markup, so the client marks them without rendering anything.
        $this->assertStringContainsString('[[HL]]', $response->json('data.0.snippet'));
        $this->assertStringContainsString('crawler', strtolower($response->json('data.0.snippet')));
    }

    public function test_a_spreadsheet_hit_resolves_to_the_sheet_and_the_a1_range(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $sheets = [[
            'name' => 'Load Schedule',
            'rows' => [
                ['row' => 1, 'cells' => ['C1' => 'Item', 'D1' => 'Capacity']],
                ['row' => 2, 'cells' => ['C2' => 'Crawler crane', 'D2' => '95 t']],
                ['row' => 3, 'cells' => ['C3' => 'Telehandler', 'D3' => '4 t']],
            ],
        ]];

        $this->makeEvidence(
            $circle,
            $owner,
            'Load schedule',
            extractedText: "# Sheet: Load Schedule\nC1=Item\tD1=Capacity\nC2=Crawler crane\tD2=95 t\nC3=Telehandler\tD3=4 t",
            extractionExtra: ['sheets' => $sheets, 'sheet_count' => 1],
            filename: 'load-schedule.xlsx',
        );

        Sanctum::actingAs($owner);

        // The row is the unit that matched, but the citation is narrowed to the
        // cell that carried the word — not the whole populated row.
        $this->getJson("/api/circles/{$circle->id}/search?q=crawler")
            ->assertOk()
            ->assertJsonPath('data.0.citation_type', 'spreadsheet_cell')
            ->assertJsonPath('data.0.locator.sheet', 'Load Schedule')
            ->assertJsonPath('data.0.locator.range', 'C2')
            ->assertJsonPath('data.0.locator_label', 'Load Schedule!C2');

        // Two terms landing in two cells widen the range to span them.
        $this->getJson("/api/circles/{$circle->id}/search?q=crawler+95")
            ->assertOk()
            ->assertJsonPath('data.0.locator.range', 'C2:D2')
            ->assertJsonPath('data.0.locator_label', 'Load Schedule!C2:D2');
    }

    public function test_a_transcript_hit_resolves_to_a_timestamp_and_is_marked_machine_read(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $item    = $this->makeEvidence($circle, $owner, 'Site meeting recording', filename: 'meeting.m4a');
        $version = $item->currentVersion();

        $version->forceFill([
            'metadata_json'     => ['lane' => 'audio'],
            'mime_type'         => 'audio/mp4',
            'transcript_status' => ProcessingStatus::Ready,
        ])->save();

        $segments = [
            ['start' => 4.0, 'end' => 11.5, 'text' => 'Right, welcome everyone, let us start with the programme.'],
            ['start' => 133.2, 'end' => 141.0, 'text' => 'We agreed the crawler crane would be the ninety five tonne machine.'],
        ];

        DerivedArtifact::create([
            'circle_id'            => $circle->id,
            'parent_resource_type' => 'evidence_version',
            'parent_resource_id'   => $version->id,
            'artifact_type'        => 'transcript',
            'content_json'         => [
                'text'     => implode(' ', array_column($segments, 'text')),
                'segments' => $segments,
                'provider' => 'test',
            ],
            'status'               => 'ready',
            'source_manifest_json' => ['evidence_version_id' => $version->id],
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/circles/{$circle->id}/search?q=crawler+crane")
            ->assertOk()
            ->assertJsonPath('data.0.citation_type', 'audio_timestamp')
            ->assertJsonPath('data.0.locator.start_seconds', 133.2)
            ->assertJsonPath('data.0.locator.end_seconds', 141)
            ->assertJsonPath('data.0.locator_label', '00:02:13–00:02:21')
            // A transcript is a model's account of the recording, not the
            // recording. The result has to say so (spec §5).
            ->assertJsonPath('data.0.machine_read', true);
    }

    public function test_search_returns_nothing_from_evidence_the_reader_cannot_view(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $reader = $this->makeUser('Ines', 'ines@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $this->addMember($circle, $reader, CircleRole::Reviewer);

        $item = $this->makeEvidence(
            $circle,
            $owner,
            'Confidential rate card',
            extractedText: 'Rates for the crawler crane and the associated lifting crew.',
        );

        ResourceAccessOverride::create([
            'resource_id'        => $item->resource_id,
            'user_id'            => $reader->id,
            'permission'         => Permission::ResourceView->value,
            'allow'              => false,
            'granted_by_user_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/circles/{$circle->id}/search?q=crawler")->assertOk()->assertJsonCount(1, 'data');

        // Search is not a side door around the gate.
        Sanctum::actingAs($reader);
        $this->getJson("/api/circles/{$circle->id}/search?q=crawler")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_never_reaches_across_circles(): void
    {
        $owner = $this->makeUser('Dana', 'dana@jwamats.test');
        $org   = $this->makeOrganisation();

        $mine  = $this->makeCircle($org, $owner, ['name' => 'Rail Access Package']);
        $other = $this->makeCircle($org, $owner, ['name' => 'Depot Refurbishment']);

        $this->makeEvidence($other, $owner, 'Other circle method statement', extractedText: 'A 95 tonne crawler crane is required.');

        Sanctum::actingAs($owner);

        $this->getJson("/api/circles/{$mine->id}/search?q=crawler")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/circles/{$other->id}/search?q=crawler")->assertOk()->assertJsonCount(1, 'data');
    }

    /** The whole point: find to assert, with the evidence attached, in one step. */
    public function test_a_search_hit_can_be_cited_on_a_claim_unchanged(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $pages = [
            ['page' => 1, 'text' => 'Cover sheet.'],
            ['page' => 4, 'text' => 'The lifting operation requires a 95 tonne crawler crane.'],
        ];

        $this->makeEvidence(
            $circle,
            $owner,
            'Crane method statement',
            extractedText: implode("\f", array_column($pages, 'text')),
            extractionExtra: ['pages' => $pages],
        );

        Sanctum::actingAs($owner);

        $hit = $this->getJson("/api/circles/{$circle->id}/search?q=crawler+crane")->assertOk()->json('data.0');

        $claim = $this->postJson("/api/circles/{$circle->id}/claims", [
            'statement'  => 'The method statement specifies a 95 tonne crawler crane.',
            'claim_type' => 'factual',
            'citations'  => [[
                'evidence_version_id' => $hit['evidence_version_id'],
                'citation_type'       => $hit['citation_type'],
                'locator'             => $hit['locator'],
                'excerpt'             => str_replace(['[[HL]]', '[[/HL]]'], '', $hit['snippet']),
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('document_page', $claim['citations'][0]['citation_type']);
        $this->assertSame(4, $claim['citations'][0]['locator']['page']);
    }

    public function test_a_query_that_matches_nothing_is_an_empty_answer_not_an_error(): void
    {
        $owner  = $this->makeUser('Dana', 'dana@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $this->makeEvidence($circle, $owner, 'Crane method statement', extractedText: 'A 95 tonne crawler crane.');

        Sanctum::actingAs($owner);

        // websearch_to_tsquery tolerates operators a user might type; nothing
        // a person can put in the box should reach Postgres as a syntax error.
        foreach (['piling rig', 'crane -crawler', '"never written"', 'or or or'] as $query) {
            $this->getJson("/api/circles/{$circle->id}/search?q=" . urlencode($query))
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }
}
