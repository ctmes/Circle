<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CommentVisibility;
use App\Models\CircleParty;
use App\Models\CommentThread;
use App\Services\Comments\CommentService;
use App\Services\Goals\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * The general room, and the thing that makes it safe to have.
 *
 * §20.3 refused a Circle-wide channel because substance migrates into it and
 * the structured record decays. The refusal did not stop the migration, it only
 * sent it to email. So the room exists and the objection is answered by making
 * drift recoverable: every general thread is named, and any of them can be
 * filed against the object it turns out to be about, whole.
 *
 * The tests that matter most are the ones proving the visibility rules did not
 * loosen when the room appeared.
 */
class GeneralDiscussionTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
        Mail::fake();
    }

    public function test_a_discussion_can_be_opened_about_the_circle_itself(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'circle',
            'title'        => 'Are we bidding the rail package?',
            'body'         => 'Closes in three weeks. Worth it?',
            'visibility'   => 'circle',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_general', true)
            ->assertJsonPath('data.title', 'Are we bidding the rail package?')
            // The Circle is its own subject, so subject_id is never null and
            // every query that already reads these columns keeps working.
            ->assertJsonPath('data.subject.type', 'circle')
            ->assertJsonPath('data.subject.id', $circle->id);
    }

    public function test_a_general_discussion_must_be_named(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        Sanctum::actingAs($owner);

        // An unnamed general room is the undifferentiated log §20.3 was right
        // to refuse. The title is what makes it a table of contents.
        $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'circle',
            'body'         => 'Anyone about?',
        ])->assertStatus(422);
    }

    public function test_a_thread_about_an_object_still_takes_no_title(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $goal   = app(GoalService::class)->create($circle, $owner, 'Mobilise access');

        Sanctum::actingAs($owner);

        $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'goal',
            'subject_id'   => $goal->id,
            'body'         => 'Freight is the risk here.',
            'visibility'   => 'circle',
            'title'        => 'ignored',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_general', false)
            // The subject names it. Two sources of truth for what a
            // conversation is called is how they end up disagreeing.
            ->assertJsonPath('data.title', null);
    }

    public function test_a_general_discussion_is_still_party_scoped_by_default(): void
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

        Sanctum::actingAs($contractor);

        $this->postJson("/api/circles/{$circle->id}/threads", [
            'subject_type' => 'circle',
            'title'        => 'Our margin on this',
            'body'         => 'We should not go below 12%.',
        ])
            ->assertCreated()
            // Nothing about having a general room loosens who can read what.
            ->assertJsonPath('data.visibility', 'party')
            ->assertJsonPath('data.party', 'Northern Rail');

        // And the convener cannot see it, exactly as with an object thread.
        Sanctum::actingAs($owner);

        $this->getJson("/api/circles/{$circle->id}/threads?subject_type=circle&subject_id={$circle->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ filing it

    public function test_filing_a_discussion_moves_the_whole_conversation(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $thread = app(CommentService::class)->openThread(
            circle: $circle,
            author: $owner,
            subjectType: 'circle',
            subjectId: $circle->id,
            body: 'What is the crane basis meant to be?',
            visibility: CommentVisibility::Circle,
            party: null,
            title: 'Crane basis',
        );

        app(CommentService::class)->post($thread, $owner, 'Drawing says 95 t, schedule says 120 t.');

        // The goal did not exist when the question was asked. That is the
        // normal case, and the reason the general room has to exist at all.
        $goal = app(GoalService::class)->create($circle, $owner, 'Confirm crane load basis');

        Sanctum::actingAs($owner);

        $this->postJson("/api/threads/{$thread->id}/attach", [
            'subject_type' => 'goal',
            'subject_id'   => $goal->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_general', false)
            ->assertJsonPath('data.subject.type', 'goal')
            ->assertJsonPath('data.subject.id', $goal->id)
            ->assertJsonPath('data.title', null)
            ->assertJsonPath('data.attached.by', 'Dana')
            // Both comments came with it. Nothing about a conversation lives on
            // its subject, which is why the move is whole.
            ->assertJsonPath('data.comment_count', 2);

        // It now appears under the goal, and no longer in the general room.
        $this->getJson("/api/circles/{$circle->id}/threads?subject_type=goal&subject_id={$goal->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/circles/{$circle->id}/threads?subject_type=circle&subject_id={$circle->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('audit_events', [
            'circle_id'  => $circle->id,
            'event_type' => 'comment.thread_attached',
        ]);
    }

    public function test_a_thread_already_about_an_object_cannot_be_refiled(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);
        $goal   = app(GoalService::class)->create($circle, $owner, 'Mobilise access');
        $other  = app(GoalService::class)->create($circle, $owner, 'Reinstatement');

        $thread = app(CommentService::class)->openThread(
            circle: $circle, author: $owner, subjectType: 'goal', subjectId: $goal->id,
            body: 'Freight is the risk.', visibility: CommentVisibility::Circle, party: null,
        );

        Sanctum::actingAs($owner);

        // One direction only. Shuffling settled conversation between objects is
        // the decay this was built to undo.
        $this->postJson("/api/threads/{$thread->id}/attach", [
            'subject_type' => 'goal',
            'subject_id'   => $other->id,
        ])->assertStatus(422);
    }

    public function test_a_discussion_cannot_be_filed_against_another_circles_object(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $org    = $this->makeOrganisation();
        $circle = $this->makeCircle($org, $owner);
        $other  = $this->makeCircle($org, $owner, ['name' => 'Another mission']);

        $elsewhere = app(GoalService::class)->create($other, $owner, 'Nothing to do with us');

        $thread = app(CommentService::class)->openThread(
            circle: $circle, author: $owner, subjectType: 'circle', subjectId: $circle->id,
            body: 'A question.', visibility: CommentVisibility::Circle, party: null,
            title: 'A question',
        );

        Sanctum::actingAs($owner);

        // Otherwise the thread names an object nobody in this Circle can see.
        $this->postJson("/api/threads/{$thread->id}/attach", [
            'subject_type' => 'goal',
            'subject_id'   => $elsewhere->id,
        ])->assertStatus(404);
    }

    public function test_filing_needs_moderation_rights_not_merely_the_ability_to_talk(): void
    {
        $owner       = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle      = $this->makeCircle($this->makeOrganisation(), $owner);
        $contributor = $this->makeUser('Tayla', 'commercial@jwamats.test');
        $this->addMember($circle, $contributor, CircleRole::Contributor);

        $goal = app(GoalService::class)->create($circle, $owner, 'Confirm crane basis');

        $thread = app(CommentService::class)->openThread(
            circle: $circle, author: $owner, subjectType: 'circle', subjectId: $circle->id,
            body: 'What is the basis?', visibility: CommentVisibility::Circle, party: null,
            title: 'Crane basis',
        );

        Sanctum::actingAs($contributor);

        // Re-filing somebody else's conversation changes where it appears for
        // everyone who can read it. That is a different act from taking part.
        $this->postJson("/api/threads/{$thread->id}/attach", [
            'subject_type' => 'goal',
            'subject_id'   => $goal->id,
        ])->assertForbidden();
    }

    public function test_a_general_thread_is_invisible_to_someone_who_cannot_read_it(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $partyOrg = $this->makeOrganisation('Northern Rail');
        $party = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $partyOrg->id,
            'display_name' => 'Northern Rail', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $contractor = $this->makeUser('Hema', 'hema@northernrail.test');
        $membership = $this->addMember($circle, $contractor, CircleRole::Reviewer, external: true);
        $membership->forceFill(['circle_party_id' => $party->id])->save();

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $circle->organisation_id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $thread = app(CommentService::class)->openThread(
            circle: $circle, author: $owner, subjectType: 'circle', subjectId: $circle->id,
            body: 'Internal only.', visibility: CommentVisibility::Party,
            party: $convener,
            title: 'Our position',
        );

        Sanctum::actingAs($contractor);

        // Not-found rather than forbidden: confirming it exists is itself a leak.
        $this->postJson("/api/threads/{$thread->id}/comments", ['body' => 'Hello?'])
            ->assertNotFound();
    }

    public function test_the_packet_names_a_general_discussion_by_its_title(): void
    {
        $owner  = $this->makeUser('Dana', 'gm@jwamats.test');
        $circle = $this->makeCircle($this->makeOrganisation(), $owner);

        $first = app(CommentService::class)->openThread(
            circle: $circle, author: $owner, subjectType: 'circle', subjectId: $circle->id,
            body: 'Closes in three weeks.', visibility: CommentVisibility::Circle, party: null,
            title: 'Are we bidding?', forTheRecord: true,
        );

        app(CommentService::class)->openThread(
            circle: $circle, author: $owner, subjectType: 'circle', subjectId: $circle->id,
            body: 'Need the geotech before we price.', visibility: CommentVisibility::Circle,
            party: null, title: 'Missing inputs', forTheRecord: true,
        );

        $threads = (new \ReflectionClass(\App\Services\Exports\ExportBuilder::class))
            ->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod($threads, 'threads');
        $method->setAccessible(true);
        $payload = $method->invoke($threads, $circle);

        $labels = collect($payload['threads'])->pluck('subject.label')->all();

        // Every general thread shares the Circle as its subject, so a label
        // looked up by subject id would give them all the same name.
        $this->assertContains('Are we bidding?', $labels);
        $this->assertContains('Missing inputs', $labels);

        $this->assertNotNull($first->fresh());
    }
}
