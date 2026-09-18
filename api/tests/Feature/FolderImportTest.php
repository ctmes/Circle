<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Models\Claim;
use App\Models\ClaimCitation;
use App\Models\Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Dropping a folder, and asking what a revision affected.
 *
 * A tender pack is 140 files that already sit in a folder. Registering them one
 * at a time is asking somebody to do the filing twice, and the usual outcome is
 * that they upload the twelve that matter and leave the rest on the drive —
 * which makes the Circle an incomplete record, quietly.
 */
class FolderImportTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
        Mail::fake();
        Storage::fake('evidence');
    }

    public function test_a_folder_is_signed_in_one_request_and_junk_is_reported_not_fatal(): void
    {
        $owner  = $this->makeUser('Nadia', 'nadia@specialist.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/circles/{$circle->id}/uploads/sign-batch", [
            'files' => [
                ['filename' => 'RFQ.pdf', 'relative_path' => '01 Tender/RFQ.pdf'],
                ['filename' => 'Load Schedule.xlsx', 'relative_path' => '01 Tender/Load Schedule.xlsx'],
                ['filename' => 'site.jpg', 'relative_path' => '02 Site/site.jpg'],
                // Every real folder has one of these in it.
                ['filename' => '.DS_Store', 'relative_path' => '.DS_Store'],
            ],
        ])->assertOk();

        $this->assertCount(3, $response->json('data.signed'));
        $this->assertCount(1, $response->json('data.rejected'));
        $this->assertSame('unsupported_type', $response->json('data.rejected.0.reason'));

        // Keys are minted server-side under this Circle's prefix, batched or not.
        foreach ($response->json('data.signed') as $signed) {
            $this->assertStringStartsWith("circles/{$circle->id}/evidence/", $signed['storage_key']);
        }
    }

    public function test_a_folder_registers_in_one_request_and_keeps_its_shape(): void
    {
        $owner  = $this->makeUser('Nadia', 'nadia@specialist.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $signed = $this->postJson("/api/circles/{$circle->id}/uploads/sign-batch", [
            'files' => [
                ['filename' => 'RFQ.pdf', 'relative_path' => '01 Tender/RFQ.pdf'],
                ['filename' => 'Addendum 3.pdf', 'relative_path' => '01 Tender/Addenda/Addendum 3.pdf'],
            ],
        ])->json('data.signed');

        foreach ($signed as $file) {
            Storage::disk('evidence')->put($file['storage_key'], 'bytes');
        }

        $response = $this->postJson("/api/circles/{$circle->id}/evidence/batch", [
            'items' => [
                ['storage_key' => $signed[0]['storage_key'], 'filename' => 'RFQ.pdf', 'relative_path' => '01 Tender/RFQ.pdf'],
                ['storage_key' => $signed[1]['storage_key'], 'filename' => 'Addendum 3.pdf', 'relative_path' => '01 Tender/Addenda/Addendum 3.pdf'],
            ],
            'classification' => 'confidential',
        ])->assertCreated();

        $this->assertSame(2, $response->json('data.created_count'));

        // The folder shape is how the person filed it and how they will look
        // for it. A flat list of 140 filenames is not a record anybody browses.
        $names = collect($response->json('data.created'))->pluck('name');
        $this->assertTrue($names->contains('01 Tender / RFQ'));
        $this->assertTrue($names->contains('01 Tender/Addenda / Addendum 3'));
    }

    public function test_a_batch_refuses_keys_from_another_circle_and_files_never_uploaded(): void
    {
        $owner  = $this->makeUser('Nadia', 'nadia@specialist.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $other  = $this->makeCircle($org, $owner, ['name' => 'Another mission']);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/circles/{$circle->id}/evidence/batch", [
            'items' => [
                ['storage_key' => "circles/{$other->id}/evidence/stolen.pdf", 'filename' => 'stolen.pdf'],
                ['storage_key' => "circles/{$circle->id}/evidence/ghost.pdf", 'filename' => 'ghost.pdf'],
            ],
        ])->assertCreated();

        $this->assertSame(0, $response->json('data.created_count'));

        $reasons = collect($response->json('data.rejected'))->pluck('reason', 'filename');
        $this->assertSame('wrong_circle', $reasons['stolen.pdf']);
        $this->assertSame('not_uploaded', $reasons['ghost.pdf']);
    }

    public function test_a_batch_that_half_works_keeps_the_half_that_did(): void
    {
        $owner  = $this->makeUser('Nadia', 'nadia@specialist.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $signed = $this->postJson("/api/circles/{$circle->id}/uploads/sign-batch", [
            'files' => [['filename' => 'RFQ.pdf']],
        ])->json('data.signed');

        Storage::disk('evidence')->put($signed[0]['storage_key'], 'bytes');

        $response = $this->postJson("/api/circles/{$circle->id}/evidence/batch", [
            'items' => [
                ['storage_key' => $signed[0]['storage_key'], 'filename' => 'RFQ.pdf'],
                ['storage_key' => "circles/{$circle->id}/evidence/missing.pdf", 'filename' => 'missing.pdf'],
            ],
        ])->assertCreated();

        // Discarding what did land because one file did not would send people
        // back to uploading one at a time.
        $this->assertSame(1, $response->json('data.created_count'));
        $this->assertCount(1, $response->json('data.rejected'));
    }

    public function test_impact_answers_what_relied_on_a_version_before_it_is_replaced(): void
    {
        $owner     = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle    = $this->makeCircle($this->makeOrganisation(), $owner);
        $estimator = $this->makeUser('Tayla', 'commercial@jwamats.test');
        $this->addMember($circle, $estimator, CircleRole::Contributor);

        $item    = $this->makeEvidence($circle, $owner, 'Load Schedule');
        $version = $item->currentVersion();

        $claim = Claim::create([
            'circle_id'   => $circle->id,
            'author_type' => 'user',
            'author_id'   => $estimator->id,
            'statement'   => 'Package 3 is priced against the 95 t basis.',
            'claim_type'  => 'commercial_assessment',
            'status'      => 'attested',
        ]);

        ClaimCitation::create([
            'claim_id'            => $claim->id,
            'evidence_version_id' => $version->id,
            'citation_type'       => 'document_page',
            'locator_json'        => ['page' => 1],
        ]);

        Decision::create([
            'circle_id'          => $circle->id,
            'title'              => 'Approve the load basis',
            'status'             => 'approved',
            'created_by_user_id' => $owner->id,
            'approver_user_id'   => $owner->id,
            'subject_type'       => 'evidence_item',
            'subject_id'         => $item->id,
            'subject_version'    => (string) $version->version_number,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/evidence-versions/{$version->id}/impact")->assertOk();

        $this->assertCount(1, $response->json('data.claims'));
        $this->assertCount(1, $response->json('data.decisions'));
        $this->assertTrue($response->json('data.is_current'));

        // Asked before replacing it, so the answer can go in the covering note.
        $this->assertContains('Tayla', $response->json('data.would_notify'));
    }

    public function test_impact_is_refused_to_somebody_who_cannot_see_the_item(): void
    {
        $owner   = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle  = $this->makeCircle($this->makeOrganisation(), $owner);
        $item    = $this->makeEvidence($circle, $owner, 'Load Schedule');
        $version = $item->currentVersion();

        Sanctum::actingAs($this->makeUser('Stranger', 'stranger@elsewhere.test'));

        $this->getJson("/api/evidence-versions/{$version->id}/impact")->assertForbidden();
    }
}
