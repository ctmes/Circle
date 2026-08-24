<?php

namespace Tests\Feature;

use App\Enums\CircleRole;
use App\Enums\CommentVisibility;
use App\Enums\GoalStatus;
use App\Models\Comment;
use App\Models\CommentThread;
use App\Models\CircleParty;
use App\Models\Goal;
use App\Models\GoalScheduleChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * A Circle that spans companies.
 *
 * The pilot had one organisation and guests. Contractor work has neither — the
 * parties do not report to each other, and the two things that decide whether
 * they will use the tool at all are whether their own working notes stay their
 * own, and whether a moved deadline leaves a trail.
 */
class PartiesAndCommentsTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    public function test_a_party_can_be_named_before_its_organisation_exists(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('GM', 'gm@jwamats.test'));

        // The counterparty is in the plan before anyone from it has signed in.
        $party = CircleParty::create([
            'circle_id'          => $circle->id,
            'display_name'       => 'Bay Junction Rail',
            'party_role'         => 'principal',
            'status'             => 'invited',
            'external_reference' => 'PO-88121',
        ]);

        $this->assertNull($party->organisation_id);
        $this->assertSame('Bay Junction Rail', $party->label());
        $this->assertFalse($party->isActive());

        // Once its organisation is provisioned the real name takes over.
        $org = $this->makeOrganisation('Bay Junction Rail Ltd');
        $party->update(['organisation_id' => $org->id, 'status' => 'active', 'joined_at' => now()]);

        $this->assertSame('Bay Junction Rail Ltd', $party->fresh()->label());
        $this->assertTrue($party->fresh()->isActive());
    }

    public function test_a_party_scoped_thread_is_invisible_to_the_other_party_including_the_convener(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('GM', 'gm@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $contractor = CircleParty::create([
            'circle_id' => $circle->id, 'display_name' => 'Groundworks Co',
            'party_role' => 'contractor', 'status' => 'active',
        ]);

        $gmMembership = $circle->memberships()->where('user_id', $owner->id)->first();
        $gmMembership->update(['circle_party_id' => $convener->id]);

        $sub = $this->makeUser('Sub Lead', 'lead@groundworks.test');
        $subMembership = $this->addMember($circle, $sub, CircleRole::Contributor, external: true);
        $subMembership->update(['circle_party_id' => $contractor->id]);

        // The contractor works out its position in a thread of its own.
        $thread = CommentThread::create([
            'circle_id'           => $circle->id,
            'subject_type'        => 'claim',
            'subject_id'          => $circle->id, // stand-in subject
            'visibility'          => CommentVisibility::Party->value,
            'visible_to_party_id' => $contractor->id,
            'created_by_id'       => $sub->id,
        ]);

        $this->assertTrue($thread->isReadableBy($subMembership));

        // The convener holds every permission in the Circle and still cannot
        // read it. Otherwise the contractor never writes anything candid here.
        $this->assertFalse($thread->isReadableBy($gmMembership));

        $shared = CommentThread::create([
            'circle_id'     => $circle->id,
            'subject_type'  => 'decision',
            'subject_id'    => $circle->id,
            'visibility'    => CommentVisibility::Circle->value,
            'created_by_id' => $owner->id,
        ]);

        $this->assertTrue($shared->isReadableBy($gmMembership));
        $this->assertTrue($shared->isReadableBy($subMembership));
    }

    public function test_the_mention_picker_offers_only_people_the_thread_would_actually_reach(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('GM', 'gm@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $org->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $contractor = CircleParty::create([
            'circle_id' => $circle->id, 'display_name' => 'Groundworks Co',
            'party_role' => 'contractor', 'status' => 'active',
        ]);

        $circle->memberships()->where('user_id', $owner->id)->first()
            ->update(['circle_party_id' => $convener->id]);

        $sub = $this->makeUser('Sub Lead', 'lead@groundworks.test');
        $this->addMember($circle, $sub, CircleRole::Contributor, external: true)
            ->update(['circle_party_id' => $contractor->id]);

        Sanctum::actingAs($sub);

        // Composing privately, the picker must not name the client — suggesting
        // someone who cannot open the thread promises a notification the
        // parser would then drop.
        $private = $this->getJson("/api/circles/{$circle->id}/mentionable?visibility=party")
            ->assertOk()
            ->json('data');

        $this->assertSame(['lead'], array_column($private, 'handle'));
        $this->assertSame('Groundworks Co', $private[0]['party']);

        $shared = $this->getJson("/api/circles/{$circle->id}/mentionable?visibility=circle")
            ->assertOk()
            ->json('data');

        $this->assertSame(['gm', 'lead'], array_column($shared, 'handle'));

        $thread = CommentThread::create([
            'circle_id'           => $circle->id,
            'subject_type'        => 'claim',
            'subject_id'          => $circle->id,
            'visibility'          => CommentVisibility::Party->value,
            'visible_to_party_id' => $contractor->id,
            'created_by_id'       => $sub->id,
        ]);

        $this->assertSame(
            ['lead'],
            array_column($this->getJson("/api/threads/{$thread->id}/mentionable")->assertOk()->json('data'), 'handle'),
        );

        // Asking who is in a thread you cannot read is itself a leak, so the
        // picker route refuses it the same way the reply route does.
        Sanctum::actingAs($owner);
        $this->getJson("/api/threads/{$thread->id}/mentionable")->assertNotFound();
    }

    public function test_external_is_derived_from_the_party_rather_than_a_stored_flag(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('GM', 'gm@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $contractor = CircleParty::create([
            'circle_id' => $circle->id, 'display_name' => 'Groundworks Co',
            'party_role' => 'contractor', 'status' => 'active',
        ]);

        $user = $this->makeUser('Engineer', 'eng@groundworks.test');

        // Stored flag says internal; the party says otherwise. In a Circle that
        // spans companies the party is the honest answer.
        $membership = $this->addMember($circle, $user, CircleRole::Contributor, external: false);
        $this->assertFalse($membership->isExternal());

        $membership->update(['circle_party_id' => $contractor->id]);
        $this->assertTrue($membership->fresh()->isExternal());
    }

    public function test_only_comments_carrying_an_action_or_marked_for_the_record_reach_the_packet(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('GM', 'gm@jwamats.test'));

        $thread = CommentThread::create([
            'circle_id'    => $circle->id,
            'subject_type' => 'claim',
            'subject_id'   => $circle->id,
            'visibility'   => CommentVisibility::Circle->value,
        ]);

        $aside = Comment::create([
            'comment_thread_id' => $thread->id,
            'circle_id'         => $circle->id,
            'body'              => 'Which revision is the client working from?',
        ]);

        $lever = Comment::create([
            'comment_thread_id' => $thread->id,
            'circle_id'         => $circle->id,
            'body'              => 'Confirmed against RFQ p.18.',
            'action_type'       => 'claim_review',
            'action_id'         => $circle->id,
        ]);

        $deliberate = Comment::create([
            'comment_thread_id' => $thread->id,
            'circle_id'         => $circle->id,
            'body'              => 'We accept the revised ground-bearing figure.',
            'for_the_record'    => true,
        ]);

        // Candid discussion stays out of a packet that is discoverable in a
        // dispute; anything that carried a state change is part of the record.
        $this->assertFalse($aside->isOnRecord());
        $this->assertTrue($lever->isOnRecord());
        $this->assertTrue($deliberate->isOnRecord());
    }

    public function test_a_parent_goal_reports_its_children_rather_than_its_own_optimism(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('GM', 'gm@jwamats.test'));

        $parent = Goal::create([
            'circle_id' => $circle->id,
            'title'     => 'Bid package ready to submit',
            'status'    => GoalStatus::Active->value,
            'progress'  => 80,
        ]);

        Goal::create([
            'circle_id' => $circle->id, 'parent_goal_id' => $parent->id,
            'title' => 'Geotechnical review complete',
            'status' => GoalStatus::Active->value, 'progress' => 20, 'position' => 0,
        ]);

        Goal::create([
            'circle_id' => $circle->id, 'parent_goal_id' => $parent->id,
            'title' => 'Freight confirmed',
            'status' => GoalStatus::Active->value, 'progress' => 40, 'position' => 1,
        ]);

        // The parent claimed 80% while its sub-goals sit at 20 and 40.
        $this->assertSame(30, $parent->fresh()->effectiveProgress());
    }

    public function test_a_met_leaf_reports_complete_regardless_of_its_stored_figure(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('GM', 'gm@jwamats.test'));

        $goal = Goal::create([
            'circle_id' => $circle->id,
            'title'     => 'Load schedule signed off',
            'status'    => GoalStatus::Met->value,
            'progress'  => 60,
        ]);

        $this->assertSame(100, $goal->effectiveProgress());
        $this->assertFalse($goal->status->isOpen());
    }

    public function test_an_overdue_goal_stops_being_overdue_once_settled(): void
    {
        $circle = $this->makeCircle($this->makeOrganisation(), $this->makeUser('GM', 'gm@jwamats.test'));

        $goal = Goal::create([
            'circle_id' => $circle->id,
            'title'     => 'Confirm stock availability',
            'status'    => GoalStatus::Active->value,
            'due_at'    => now()->subDays(3),
        ]);

        $this->assertTrue($goal->isOverdue());

        $goal->update(['status' => GoalStatus::Abandoned->value]);
        $this->assertFalse($goal->fresh()->isOverdue());
    }

    public function test_a_slipped_deadline_records_who_moved_it_and_whether_the_counterparty_agreed(): void
    {
        $org    = $this->makeOrganisation();
        $owner  = $this->makeUser('GM', 'gm@jwamats.test');
        $circle = $this->makeCircle($org, $owner);

        $principal = CircleParty::create([
            'circle_id' => $circle->id, 'display_name' => 'Bay Junction Rail',
            'party_role' => 'principal', 'status' => 'active',
        ]);

        $goal = Goal::create([
            'circle_id' => $circle->id,
            'title'     => 'Mobilisation complete',
            'status'    => GoalStatus::Active->value,
            'due_at'    => now()->addDays(7),
        ]);

        $change = GoalScheduleChange::create([
            'goal_id'            => $goal->id,
            'circle_id'          => $circle->id,
            'from_due_at'        => $goal->due_at,
            'to_due_at'          => $goal->due_at->copy()->addDays(10),
            'reason'             => 'Freight confirmation still pending.',
            'changed_by_user_id' => $owner->id,
            'requires_party_id'  => $principal->id,
        ]);

        $goal->update(['due_at' => $change->to_due_at]);

        // A move that affects the other party is not settled until they say so.
        $this->assertSame(10, $change->daysMoved());
        $this->assertTrue($change->isAwaitingAgreement());

        $principalLead = $this->makeUser('Client PM', 'pm@bayjunction.test');
        $change->update(['agreed_by_user_id' => $principalLead->id, 'agreed_at' => now()]);

        $this->assertFalse($change->fresh()->isAwaitingAgreement());
        $this->assertCount(1, $goal->fresh()->scheduleChanges);
    }
}
