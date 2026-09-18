<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CommentVisibility;
use App\Enums\NotificationKind;
use App\Mail\CircleNotification;
use App\Models\CircleMembership;
use App\Models\CircleParty;
use App\Models\CommentMention;
use App\Services\Comments\CommentService;
use App\Services\Decisions\DecisionService;
use App\Services\Evidence\EvidenceService;
use App\Services\Goals\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * The transport that did not exist.
 *
 * Until these, nothing left the browser: every deadline, assignment and moved
 * date was state somebody had to remember to go and look at. The tests that
 * matter most here are not the ones proving a message is sent — they are the
 * ones proving it is *not*, because a notification is a disclosure that lands
 * outside the system where no access check will ever run again.
 */
class NotificationsTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
        Mail::fake();
    }

    /**
     * Queued messages of one kind.
     *
     * Mail::queued() rather than Mail::assertQueued(), because half the tests
     * below are about a message *not* being sent and an assertion helper
     * cannot answer that question without failing first.
     *
     * @return \Illuminate\Support\Collection<int, CircleNotification>
     */
    private function sent(NotificationKind $kind)
    {
        return Mail::queued(CircleNotification::class)
            ->filter(fn (CircleNotification $mail) => $mail->kind === $kind)
            ->values();
    }

    private function queuedTo(string $email, NotificationKind $kind): bool
    {
        return $this->sent($kind)->contains(fn (CircleNotification $mail) => $mail->hasTo($email));
    }

    // ------------------------------------------------------------- invitations

    public function test_an_invitation_carries_the_token_to_the_person_who_needs_it(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/invitations", [
            'email'       => 'jules@geotech.test',
            'circle_role' => 'contributor',
        ])->assertCreated();

        $this->assertTrue($this->queuedTo('jules@geotech.test', NotificationKind::Invited));

        $mail = $this->sent(NotificationKind::Invited)->first();

        // The link has to be the front end. A notification whose link lands on
        // a JSON endpoint is one nobody can act on.
        $this->assertStringContainsString('/invitations/', $mail->actionUrl);
        $this->assertStringNotContainsString('/api/', $mail->actionUrl);

        // An invitation says come and look, not here is what is inside.
        $this->assertSame($circle->name, $mail->circleName);
        $this->assertArrayNotHasKey('Purpose', $mail->facts);
    }

    // --------------------------------------------------------------- decisions

    public function test_the_assigned_approver_is_told_because_only_they_can_resolve_it(): void
    {
        $owner    = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle   = $this->makeCircle($this->makeOrganisation(), $owner);
        $approver = $this->makeUser('Tomas', 'technical@jwamats.test');
        $this->addMember($circle, $approver, CircleRole::Approver);

        app(DecisionService::class)->create(
            circle: $circle,
            creator: $owner,
            title: 'Confirm crane load basis',
            approver: $approver,
            subjectType: 'evidence_item',
            subjectId: 'irrelevant-for-this-test',
            subjectVersion: '2',
        );

        $this->assertTrue($this->queuedTo('technical@jwamats.test', NotificationKind::DecisionAssigned));
    }

    public function test_a_decision_with_nobody_named_tells_nobody(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        app(DecisionService::class)->create(
            circle: $circle,
            creator: $owner,
            title: 'Go / no-go on the bid',
        );

        Mail::assertNothingQueued();
    }

    // ---------------------------------------------------------------- evidence

    public function test_superseding_a_document_tells_the_people_who_relied_on_it(): void
    {
        Storage::fake('evidence');

        $owner    = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle   = $this->makeCircle($this->makeOrganisation(), $owner);
        $estimator = $this->makeUser('Tayla', 'commercial@jwamats.test');
        $this->addMember($circle, $estimator, CircleRole::Contributor);

        $item    = $this->makeEvidence($circle, $owner, 'Load Schedule', extractedText: '95 t crane');
        $version = $item->currentVersion();

        // The estimator priced against this version and said so.
        $claim = \App\Models\Claim::create([
            'circle_id'   => $circle->id,
            'author_type' => 'user',
            'author_id'   => $estimator->id,
            'statement'   => 'Package 3 is priced against the 95 t basis.',
            'claim_type'  => 'commercial_assessment',
            'status'      => 'attested',
        ]);

        \App\Models\ClaimCitation::create([
            'claim_id'            => $claim->id,
            'evidence_version_id' => $version->id,
            'citation_type'       => 'document_page',
            'locator_json'        => ['page' => 1],
        ]);

        Storage::disk('evidence')->put("circles/{$circle->id}/evidence/rev-b.pdf", 'new bytes');

        app(EvidenceService::class)->addVersion(
            item: $item,
            uploader: $owner,
            storageKey: "circles/{$circle->id}/evidence/rev-b.pdf",
            originalFilename: 'rev-b.pdf',
        );

        $this->assertTrue($this->queuedTo('commercial@jwamats.test', NotificationKind::EvidenceSuperseded));

        $mail = $this->sent(NotificationKind::EvidenceSuperseded)->first();
        $this->assertSame('v1', $mail->facts['Was']);
        $this->assertSame('v2', $mail->facts['Now']);
    }

    public function test_superseding_a_document_nothing_relied_on_tells_nobody(): void
    {
        Storage::fake('evidence');

        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $item   = $this->makeEvidence($circle, $owner, 'Site photo', filename: 'photo.jpg');

        Storage::disk('evidence')->put("circles/{$circle->id}/evidence/photo-2.jpg", 'new bytes');

        app(EvidenceService::class)->addVersion(
            item: $item,
            uploader: $owner,
            storageKey: "circles/{$circle->id}/evidence/photo-2.jpg",
            originalFilename: 'photo-2.jpg',
        );

        // Most revisions land on documents nobody cited. A message saying so
        // would teach everybody to filter these.
        Mail::assertNotQueued(CircleNotification::class);
    }

    public function test_somebody_whose_access_was_revoked_is_not_told_what_changed(): void
    {
        Storage::fake('evidence');

        $owner     = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle    = $this->makeCircle($this->makeOrganisation(), $owner);
        $departed  = $this->makeUser('Jules', 'jules@geotech.test');
        $membership = $this->addMember($circle, $departed, CircleRole::Contributor, external: true);

        $item    = $this->makeEvidence($circle, $owner, 'Geotechnical report');
        $version = $item->currentVersion();

        $claim = \App\Models\Claim::create([
            'circle_id'   => $circle->id,
            'author_type' => 'user',
            'author_id'   => $departed->id,
            'statement'   => 'Ground bearing is adequate for the 95 t basis.',
            'claim_type'  => 'technical_assessment',
            'status'      => 'attested',
        ]);

        \App\Models\ClaimCitation::create([
            'claim_id'            => $claim->id,
            'evidence_version_id' => $version->id,
            'citation_type'       => 'document_page',
            'locator_json'        => ['page' => 4],
        ]);

        // They have since left the project.
        $membership->forceFill(['revoked_at' => now(), 'invite_status' => 'revoked'])->save();

        Storage::disk('evidence')->put("circles/{$circle->id}/evidence/geo-rev-c.pdf", 'new bytes');

        app(EvidenceService::class)->addVersion(
            item: $item,
            uploader: $owner,
            storageKey: "circles/{$circle->id}/evidence/geo-rev-c.pdf",
            originalFilename: 'geo-rev-c.pdf',
        );

        // The citation is still theirs and still resolves. The message is not:
        // an inbox is outside the system, and no gate runs on it later.
        $this->assertFalse($this->queuedTo('jules@geotech.test', NotificationKind::EvidenceSuperseded));
    }

    // ------------------------------------------------------------------- dates

    public function test_a_moved_date_reaches_the_party_whose_agreement_it_waits_on(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $contractorOrg = $this->makeOrganisation('Northern Rail');
        $party = CircleParty::create([
            'circle_id'       => $circle->id,
            'organisation_id' => $contractorOrg->id,
            'display_name'    => 'Northern Rail',
            'party_role'      => 'contractor',
            'status'          => 'active',
            'is_convener'     => false,
        ]);

        $contractor = $this->makeUser('Hema', 'hema@northernrail.test');
        $membership = $this->addMember($circle, $contractor, CircleRole::Contributor, external: true);
        $membership->forceFill(['circle_party_id' => $party->id])->save();

        $goal = app(GoalService::class)->create(
            circle: $circle,
            creator: $owner,
            title: 'Mobilise temporary access',
        );

        app(GoalService::class)->reschedule(
            goal: $goal,
            actor: $owner,
            dueAt: now()->addDays(14),
            reason: 'Freight confirmation is pending.',
            requiresParty: $party,
        );

        $this->assertTrue($this->queuedTo('hema@northernrail.test', NotificationKind::ScheduleChangeProposed));

        // Never the person who moved it. They already know.
        $this->assertFalse($this->queuedTo('gm@jwamats.test', NotificationKind::ScheduleChangeProposed));

        $mail = $this->sent(NotificationKind::ScheduleChangeProposed)->first();
        $this->assertSame('Freight confirmation is pending.', $mail->facts['Reason']);
    }

    // ---------------------------------------------------------------- mentions

    public function test_a_mention_is_sent_once_and_records_that_it_was(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $tech   = $this->makeUser('Tomas', 'technical@jwamats.test');
        $this->addMember($circle, $tech, CircleRole::Reviewer);

        $goal = app(GoalService::class)->create(
            circle: $circle,
            creator: $owner,
            title: 'Confirm crane basis',
        );

        app(CommentService::class)->openThread(
            circle: $circle,
            author: $owner,
            subjectType: 'goal',
            subjectId: $goal->id,
            body: '@technical can you confirm the load basis before Friday?',
            visibility: CommentVisibility::Circle,
            party: null,
        );

        $this->assertTrue($this->queuedTo('technical@jwamats.test', NotificationKind::Mentioned));

        // notified_at now means "was told", not "was named".
        $mention = CommentMention::where('mentioned_user_id', $tech->id)->firstOrFail();
        $this->assertNotNull($mention->notified_at);
    }

    public function test_a_closed_circle_sends_nothing(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $tech   = $this->makeUser('Tomas', 'technical@jwamats.test');
        $this->addMember($circle, $tech, CircleRole::Approver);

        $circle->forceFill(['status' => 'archived', 'closed_at' => now()])->save();

        app(DecisionService::class)->create(
            circle: $circle->fresh(),
            creator: $owner,
            title: 'Something after the fact',
            approver: $tech,
        );

        // The gate refuses DecisionApprove on a closed Circle, so there is
        // nothing legitimate to tell them about.
        $this->assertFalse($this->queuedTo('technical@jwamats.test', NotificationKind::DecisionAssigned));
    }

    public function test_notifications_can_be_turned_off_entirely(): void
    {
        config(['circle.notifications.enabled' => false]);

        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/invitations", [
            'email'       => 'jules@geotech.test',
            'circle_role' => 'contributor',
        ])->assertCreated();

        Mail::assertNothingQueued();

        // The invitation itself still exists — the token is in the response and
        // can be sent by hand. Only the telling was switched off.
        $this->assertDatabaseHas('invitations', ['email' => 'jules@geotech.test']);
    }
}
