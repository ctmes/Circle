<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CommitmentStatus;
use App\Enums\EngagementStatus;
use App\Enums\FeeBasis;
use App\Enums\GoalStatus;
use App\Enums\PrincipalType;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Commitment;
use App\Models\Engagement;
use App\Models\Goal;
use App\Models\User;
use App\Models\WorkRecord;
use App\Services\Work\EngagementService;
use App\Services\Work\WorkRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * What a contractor carries away (spec §21.3).
 *
 * Three parties, three jobs, and the tests are mostly about none of them being
 * able to do another's: the platform compiles the numbers, the counterparty
 * signs them, and the subject decides who sees them. A record whose subject
 * could write it is a CV, and everybody reading one knows it.
 */
class WorkRecordTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    /**
     * A finished engagement with a mixed record: one deliverable accepted on
     * time, one accepted late, one never done at all.
     *
     * @return array{client: User, rae: User, circle: Circle, engagement: Engagement}
     */
    private function finished(EngagementStatus $ending = EngagementStatus::Completed): array
    {
        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation('JWA Mats');
        $circle = $this->makeCircle($org, $dana);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $circle->memberships()->where('user_id', $dana->id)
            ->update(['circle_party_id' => $convener->id]);

        $beamOrg = $this->makeOrganisation('Beam Rail');
        $beam    = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $beamOrg->id,
            'display_name' => 'Beam Rail', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $goal = Goal::create([
            'circle_id' => $circle->id, 'title' => 'Bogie package',
            'status' => GoalStatus::Active, 'position' => 1,
            'created_by_type' => 'user', 'created_by_id' => $dana->id,
        ]);

        $rae = $this->makeUser('Rae Nkomo', 'rae@beamrail.test');

        $engagements = app(EngagementService::class);

        $engagement = $engagements->propose(
            circle: $circle, actor: $dana, engaging: $convener, contractor: $beam,
            principalType: PrincipalType::User, principalId: $rae->id,
            title: 'Bogie package inspection', scope: $goal, feeBasis: FeeBasis::Fixed,
        );

        $engagements->issueSeat($engagement, $rae, CircleRole::Contributor);

        $engagement->forceFill([
            'status' => EngagementStatus::Active, 'activated_at' => now()->subDays(30),
            'starts_at' => now()->subDays(30),
        ])->save();

        $make = function (string $title, ?CommitmentStatus $status, $due, $completed) use ($circle, $goal, $engagement, $rae, $dana) {
            Commitment::create([
                'circle_id'          => $circle->id,
                'goal_id'            => $goal->id,
                'engagement_id'      => $engagement->id,
                'title'              => $title,
                'status'             => $status,
                'owner_user_id'      => $rae->id,
                'owner_party_id'     => $engagement->contractor_party_id,
                'created_by_user_id' => $dana->id,
                'created_by_type'    => 'user',
                'due_at'             => $due,
                'completed_at'       => $completed,
            ]);
        };

        $make('Joint register', CommitmentStatus::Done, now()->subDays(20), now()->subDays(21));
        $make('NDT report', CommitmentStatus::Done, now()->subDays(10), now()->subDays(4));
        $make('Punch list closeout', CommitmentStatus::Open, now()->subDays(2), null);

        // Completing over an outstanding deliverable is refused unless forced;
        // a termination has no such rule, because that is the situation it is
        // for.
        if ($ending === EngagementStatus::Completed) {
            $engagements->complete($engagement->fresh(), $dana, 'Package accepted.', force: true);
        } else {
            $engagements->terminate($engagement->fresh(), $dana, 'Insurance lapsed and was not reinstated.');
        }

        return ['client' => $dana, 'rae' => $rae, 'circle' => $circle, 'engagement' => $engagement->fresh()];
    }

    // ------------------------------------------------------- compilation

    public function test_a_record_is_compiled_from_the_ledger_rather_than_reported(): void
    {
        $scene  = $this->finished();
        $record = app(WorkRecordService::class)->compile($scene['engagement']);

        $this->assertNotNull($record);
        $this->assertSame('completed', $record->outcome->value);

        // Every one of these is a count over rows the counterparty wrote or
        // accepted. Nothing here can be set by its subject, which is the whole
        // reason the total is worth reading.
        $this->assertSame(3, $record->metric('deliverables'));
        $this->assertSame(2, $record->metric('deliverables_accepted'));
        $this->assertSame(1, $record->metric('deliverables_late'));
        $this->assertSame(1, $record->metric('deliverables_open'));

        // Denormalised, because the point of the row is that it keeps reading
        // after the Circle is closed and gone.
        $this->assertSame('JWA Mats', $record->counterparty_name);
        $this->assertSame($scene['circle']->name, $record->circle_name);
    }

    public function test_a_termination_does_not_read_like_a_completion(): void
    {
        $scene  = $this->finished(EngagementStatus::Terminated);
        $record = app(WorkRecordService::class)->compile($scene['engagement']);

        $this->assertSame('terminated', $record->outcome->value);
        $this->assertSame('terminated early', $record->outcome->label());

        // Who ended it and why, kept where a renderer cannot drop it. "The
        // client cut it short" and "the contractor walked" are the two facts a
        // record most needs to tell apart.
        $this->assertSame(
            'Insurance lapsed and was not reinstated.',
            $record->refusals_json['end_reason'],
        );
        $this->assertSame('JWA Mats', $record->refusals_json['ended_by']);
    }

    // -------------------------------------------------------- attestation

    public function test_a_contractor_cannot_attest_their_own_record(): void
    {
        $scene  = $this->finished();
        $record = app(WorkRecordService::class)->compile($scene['engagement']);

        Sanctum::actingAs($scene['rae']);

        // Not "not yourself" — "not your side". A colleague signing for you is
        // exactly as worthless as signing for yourself.
        $this->postJson("/api/records/{$record->id}/attest", ['note' => 'Went well.'])
            ->assertForbidden();

        $this->assertFalse($record->fresh()->isAttested());
    }

    public function test_the_counterparty_signs_and_only_then_can_it_be_published(): void
    {
        $scene  = $this->finished();
        $record = app(WorkRecordService::class)->compile($scene['engagement']);

        // Unsigned, a record is only our arithmetic.
        Sanctum::actingAs($scene['rae']);
        $this->postJson("/api/records/{$record->id}/publish", ['visibility' => 'network'])
            ->assertStatus(422);

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/records/{$record->id}/attest", ['note' => 'Thorough, and late once.'])
            ->assertOk()
            ->assertJsonPath('data.attested', true)
            ->assertJsonPath('data.attested_by', 'JWA Mats');

        Sanctum::actingAs($scene['rae']);
        $this->postJson("/api/records/{$record->id}/publish", ['visibility' => 'public'])
            ->assertOk()
            ->assertJsonPath('data.published', true);
    }

    public function test_a_signed_record_does_not_move_when_it_is_recompiled(): void
    {
        $scene   = $this->finished();
        $records = app(WorkRecordService::class);
        $record  = $records->compile($scene['engagement']);

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/records/{$record->id}/attest")->assertOk();

        $sealed = $record->fresh()->record_hash;

        // Somebody accepts a fourth deliverable after the signature. The
        // numbers must not move underneath a name that was already put to
        // them — which is the single thing this chain exists to detect.
        Commitment::create([
            'circle_id'          => $scene['circle']->id,
            'engagement_id'      => $scene['engagement']->id,
            'title'              => 'Added afterwards',
            'status'             => CommitmentStatus::Done,
            'created_by_user_id' => $scene['client']->id,
            'created_by_type'    => 'user',
            'completed_at'       => now(),
        ]);

        $records->compile($scene['engagement']->fresh());

        $this->assertSame($sealed, $record->fresh()->record_hash);
        $this->assertSame(3, $record->fresh()->metric('deliverables'));
    }

    // -------------------------------------------------------- the chain

    public function test_the_chain_verifies_and_notices_a_record_that_was_altered(): void
    {
        $scene   = $this->finished();
        $records = app(WorkRecordService::class);
        $record  = $records->compile($scene['engagement']);

        $verification = $records->verify(PrincipalType::User, $scene['rae']->id);
        $this->assertTrue($verification['ok']);
        $this->assertSame(1, $verification['checked']);

        // Somebody edits the figures directly in the database.
        WorkRecord::where('id', $record->id)->update([
            'metrics_json' => json_encode(['deliverables_accepted' => 99]),
        ]);

        $broken = $records->verify(PrincipalType::User, $scene['rae']->id);
        $this->assertFalse($broken['ok']);
        $this->assertSame($record->id, $broken['broken_at']);
    }

    public function test_hiding_a_record_does_not_disturb_its_signature(): void
    {
        $scene   = $this->finished();
        $records = app(WorkRecordService::class);
        $record  = $records->compile($scene['engagement']);

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/records/{$record->id}/attest")->assertOk();

        $sealed = $record->fresh()->record_hash;

        Sanctum::actingAs($scene['rae']);
        $this->postJson("/api/records/{$record->id}/publish", ['visibility' => 'public'])->assertOk();
        $this->postJson("/api/records/{$record->id}/hide")->assertOk();

        // Publication is deliberately outside the digest. If it were inside,
        // every hide would look like tampering and the one thing the chain
        // exists to detect would drown in noise.
        $this->assertSame($sealed, $record->fresh()->record_hash);
        $this->assertTrue($records->verify(PrincipalType::User, $scene['rae']->id)['ok']);
    }

    // -------------------------------------------------------- visibility

    public function test_a_stranger_sees_only_what_was_published(): void
    {
        $scene   = $this->finished();
        $records = app(WorkRecordService::class);
        $record  = $records->compile($scene['engagement']);

        $stranger = $this->makeUser('Prospective Client', 'buyer@elsewhere.test');
        $url      = "/api/records/user/{$scene['rae']->id}";

        Sanctum::actingAs($stranger);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($scene['client']);
        $this->postJson("/api/records/{$record->id}/attest")->assertOk();

        Sanctum::actingAs($scene['rae']);
        $this->postJson("/api/records/{$record->id}/publish", ['visibility' => 'public'])->assertOk();

        Sanctum::actingAs($stranger);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterparty', 'JWA Mats')
            ->assertJsonPath('data.0.outcome', 'completed')
            ->assertJsonPath('meta.chain.ok', true)
            // The summary is computed over the visible records only, so hiding
            // one cannot be detected by watching a total move.
            ->assertJsonPath('meta.summary.deliverables_late', 1);
    }

    public function test_the_subject_sees_their_own_record_before_anybody_signs_it(): void
    {
        $scene = $this->finished();
        app(WorkRecordService::class)->compile($scene['engagement']);

        Sanctum::actingAs($scene['rae']);

        $this->getJson('/api/my/record')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attested', false)
            ->assertJsonPath('data.0.published', false);
    }

    public function test_closing_a_circle_compiles_what_everyone_earned(): void
    {
        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $org    = $this->makeOrganisation('JWA Mats');
        $circle = $this->makeCircle($org, $dana);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $circle->memberships()->where('user_id', $dana->id)
            ->update(['circle_party_id' => $convener->id]);

        $beamOrg = $this->makeOrganisation('Beam Rail');
        $beam    = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $beamOrg->id,
            'display_name' => 'Beam Rail', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $rae         = $this->makeUser('Rae Nkomo', 'rae@beamrail.test');
        $engagements = app(EngagementService::class);

        $engagement = $engagements->propose(
            circle: $circle, actor: $dana, engaging: $convener, contractor: $beam,
            principalType: PrincipalType::User, principalId: $rae->id,
            title: 'Ongoing inspection',
        );

        $engagement->forceFill(['status' => EngagementStatus::Active, 'activated_at' => now()])->save();

        Sanctum::actingAs($dana);
        $this->postJson("/api/circles/{$circle->id}/close", ['reason' => 'Bid submitted.'])->assertOk();

        $record = WorkRecord::where('engagement_id', $engagement->id)->firstOrFail();

        // Nobody said how it finished, so nobody gets to say it was completed.
        // Inventing that on their behalf would be writing somebody's history
        // for them.
        $this->assertSame('expired', $record->outcome->value);
        $this->assertSame('The Circle was closed.', $engagement->fresh()->end_reason);
    }
}
