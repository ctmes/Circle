<?php

namespace Tests\Feature;

use App\Enums\AgentActionStatus;
use App\Enums\AgentExecutionMode;
use App\Enums\CircleRole;
use App\Enums\EngagementStatus;
use App\Enums\FeeBasis;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\SideEffect;
use App\Models\AgentAction;
use App\Models\AgentBlueprint;
use App\Models\AgentBlueprintVersion;
use App\Models\AgentInstance;
use App\Models\AgentTool;
use App\Models\Circle;
use App\Models\CircleParty;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\User;
use App\Services\Agent\BlueprintRegistry;
use App\Services\Work\EngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCircles;
use Tests\TestCase;

/**
 * Hiring somebody else's agent (spec §21.6).
 *
 * §20.6 established that only a blueprint's author may edit it. Hiring inverts
 * the risk: once a counterparty is *paying*, the author editing it silently is
 * worth money. A version is the answer, and most of what follows tests that
 * the version really is frozen and that the meter really does distinguish an
 * agent asking from an agent doing.
 */
class AgentForHireTest extends TestCase
{
    use BuildsCircles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBlueprint();
    }

    /** An agent-authoring company, and someone who works there. */
    private function author(): array
    {
        $org = $this->makeOrganisation('Inspection Robotics');
        $kim = $this->makeUser('Kim Alvarez', 'kim@inspectionrobotics.test');

        OrganisationMembership::create([
            'organisation_id' => $org->id, 'user_id' => $kim->id, 'org_role' => 'member',
        ]);

        $blueprint = AgentBlueprint::create([
            'key'                => 'weld_reader_' . uniqid(),
            'organisation_id'    => $org->id,
            'created_by_user_id' => $kim->id,
            'name'               => 'Weld Report Reader',
            'mandate'            => 'Read NDT reports and flag inconsistent joint numbers.',
            'version'            => '1.0.0',
            'status'             => 'active',
            'is_system'          => false,
            'execution_mode'     => AgentExecutionMode::Execute->value,
            'provider'           => 'internal',
            'allowed_actions'    => [Permission::CircleView->value, Permission::CommentCreate->value],
            'prohibited_actions' => [],
            'prompt_version'     => 'v1',
        ]);

        AgentTool::create([
            'agent_blueprint_id'    => $blueprint->id,
            'key'                   => 'post_comment',
            'name'                  => 'Post a comment',
            'description'           => 'Leaves a note on the object it read.',
            'side_effect'           => SideEffect::CircleWrite->value,
            'requires_approval'     => true,
            'approval_role'         => CircleRole::Reviewer->value,
            'requires_owning_party' => false,
        ]);

        return ['org' => $org, 'kim' => $kim, 'blueprint' => $blueprint];
    }

    // ------------------------------------------------------ frozen mandates

    public function test_a_published_version_does_not_move_when_the_blueprint_does(): void
    {
        $author   = $this->author();
        $registry = app(BlueprintRegistry::class);

        $version = $registry->publishVersion($author['blueprint'], $author['kim'], 'network', 'First release.');

        $this->assertSame(1, $version->version_number);
        $this->assertSame('Read NDT reports and flag inconsistent joint numbers.', $version->mandate);
        $this->assertCount(1, $version->tools());
        $this->assertSame('circle_write', $version->highestSideEffect());

        // The author widens the mandate and adds a tool that moves money.
        $author['blueprint']->forceFill([
            'mandate' => 'Read anything, and pay anybody.',
        ])->save();

        AgentTool::create([
            'agent_blueprint_id'    => $author['blueprint']->id,
            'key'                   => 'issue_payment',
            'name'                  => 'Issue payment',
            'description'           => 'Releases a progress payment.',
            'side_effect'           => SideEffect::Financial->value,
            'requires_approval'     => true,
            'approval_role'         => CircleRole::Owner->value,
            'requires_owning_party' => true,
        ]);

        // Which is exactly what a hirer must be protected from. What was
        // agreed is still what was agreed.
        $version->refresh();
        $this->assertSame('Read NDT reports and flag inconsistent joint numbers.', $version->mandate);
        $this->assertCount(1, $version->tools());
        $this->assertSame('circle_write', $version->highestSideEffect());
    }

    public function test_republishing_identical_content_does_not_invent_a_second_version(): void
    {
        $author   = $this->author();
        $registry = app(BlueprintRegistry::class);

        $first  = $registry->publishVersion($author['blueprint'], $author['kim'], 'network');
        $second = $registry->publishVersion($author['blueprint'], $author['kim'], 'network');

        // Two versions with the same hash would give a hirer two things to
        // choose between that are the same thing, and "which one did we agree
        // to" would stop having an answer.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AgentBlueprintVersion::where('agent_blueprint_id', $author['blueprint']->id)->count());

        // A real edit does make a new one.
        $author['blueprint']->forceFill(['mandate' => 'Read NDT reports only.'])->save();

        $third = $registry->publishVersion($author['blueprint']->fresh(), $author['kim'], 'network');

        $this->assertSame(2, $third->version_number);
        $this->assertNotSame($first->content_hash, $third->content_hash);
    }

    public function test_the_steward_is_not_for_hire(): void
    {
        $author  = $this->author();
        $steward = AgentBlueprint::where('key', AgentBlueprint::STEWARD)->firstOrFail();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(BlueprintRegistry::class)->publishVersion($steward, $author['kim'], 'public');
    }

    public function test_only_the_authoring_company_may_publish_a_version(): void
    {
        $author   = $this->author();
        $outsider = $this->makeUser('Not Their Employee', 'nope@elsewhere.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(BlueprintRegistry::class)->publishVersion($author['blueprint'], $outsider, 'public');
    }

    public function test_the_register_shows_one_row_per_agent_not_a_changelog(): void
    {
        $author   = $this->author();
        $registry = app(BlueprintRegistry::class);

        $registry->publishVersion($author['blueprint'], $author['kim'], 'public');
        $author['blueprint']->forceFill(['mandate' => 'Read NDT reports only.'])->save();
        $registry->publishVersion($author['blueprint']->fresh(), $author['kim'], 'public');

        $buyer = $this->makeUser('Prospective Hirer', 'buyer@jwamats.test');

        Sanctum::actingAs($buyer);

        $this->getJson('/api/agent-registry')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', 2)
            // Named individually rather than counted. A hirer approving an
            // agent is approving these, and a number says nothing about which.
            ->assertJsonPath('data.0.tools.0.side_effect', 'circle_write')
            // Stated rather than implied: nothing here rents software somebody
            // else operates (spec §21.7).
            ->assertJsonPath('data.0.runs_where', 'Circle, under the authoring company\'s authority');
    }

    public function test_a_private_version_stays_inside_its_own_company(): void
    {
        $author = $this->author();

        app(BlueprintRegistry::class)->publishVersion($author['blueprint'], $author['kim'], 'private');

        $buyer = $this->makeUser('Prospective Hirer', 'buyer@jwamats.test');

        Sanctum::actingAs($buyer);
        $this->getJson('/api/agent-registry')->assertOk()->assertJsonCount(0, 'data');

        // Its author still sees it.
        Sanctum::actingAs($author['kim']);
        $this->getJson('/api/agent-registry')->assertOk()->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------ the meter

    public function test_an_agent_is_billed_for_what_it_did_and_not_for_what_it_asked(): void
    {
        $author = $this->author();

        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $client = $this->makeOrganisation('JWA Mats');
        $circle = $this->makeCircle($client, $dana);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $client->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $circle->memberships()->where('user_id', $dana->id)
            ->update(['circle_party_id' => $convener->id]);

        // The agent's *author* is the contractor party. §20.4's liability rule
        // is untouched by hiring: an agent acts for the company that wrote it,
        // and hiring one does not move that.
        $robotics = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $author['org']->id,
            'display_name' => 'Inspection Robotics', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $instance = AgentInstance::create([
            'agent_blueprint_id' => $author['blueprint']->id,
            'circle_id'          => $circle->id,
            'status'             => 'active',
        ]);

        $version = app(BlueprintRegistry::class)
            ->publishVersion($author['blueprint'], $author['kim'], 'network');

        $engagements = app(EngagementService::class);

        $engagement = $engagements->propose(
            circle: $circle, actor: $dana, engaging: $convener, contractor: $robotics,
            principalType: PrincipalType::AgentInstance, principalId: $instance->id,
            title: 'Weld report reading', feeBasis: FeeBasis::PerAction,
            unitCap: 2, blueprintVersionId: $version->id,
        );

        $engagement->forceFill([
            'status' => EngagementStatus::Active, 'activated_at' => now(), 'starts_at' => now(),
        ])->save();

        $propose = fn (AgentActionStatus $status) => AgentAction::create([
            'circle_id'         => $circle->id,
            'agent_instance_id' => $instance->id,
            'tool_key'          => 'post_comment',
            'side_effect'       => SideEffect::CircleWrite->value,
            'status'            => $status,
            'intent'            => 'Flag joint 14.',
            'executed_at'       => $status === AgentActionStatus::Executed ? now() : null,
        ]);

        $meter = fn (AgentAction $a) => $engagements->meterAgentAction($a);

        // Asked and was refused. Asked and is still waiting. Neither is work.
        $this->assertNull($meter($propose(AgentActionStatus::Rejected)));
        $this->assertNull($meter($propose(AgentActionStatus::AwaitingApproval)));
        $this->assertSame(0.0, $engagement->fresh()->unitsUsed());

        // Did something.
        $executed = $propose(AgentActionStatus::Executed);
        $this->assertNotNull($meter($executed));
        $this->assertSame(1.0, $engagement->fresh()->unitsUsed());

        // Re-draining the same action must not bill twice. The unique index on
        // the source is what guarantees that under a concurrent worker.
        $meter($executed->fresh());
        $this->assertSame(1.0, $engagement->fresh()->unitsUsed());

        // The cap is real. A per-action contract with a ceiling that can be
        // exceeded has no ceiling.
        $meter($propose(AgentActionStatus::Executed));
        $this->assertSame(2.0, $engagement->fresh()->unitsUsed());
        $this->assertTrue($engagement->fresh()->isOverCap());

        $meter($propose(AgentActionStatus::Executed));
        $this->assertSame(2.0, $engagement->fresh()->unitsUsed());
    }

    public function test_an_agent_carries_a_record_against_its_blueprint_not_its_instance(): void
    {
        $author = $this->author();

        $dana   = $this->makeUser('Dana Okafor', 'dana@jwamats.test');
        $client = $this->makeOrganisation('JWA Mats');
        $circle = $this->makeCircle($client, $dana);

        $convener = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $client->id,
            'display_name' => 'JWA Mats', 'party_role' => 'convener',
            'status' => 'active', 'is_convener' => true,
        ]);

        $circle->memberships()->where('user_id', $dana->id)
            ->update(['circle_party_id' => $convener->id]);

        $robotics = CircleParty::create([
            'circle_id' => $circle->id, 'organisation_id' => $author['org']->id,
            'display_name' => 'Inspection Robotics', 'party_role' => 'contractor',
            'status' => 'active', 'is_convener' => false,
        ]);

        $instance = AgentInstance::create([
            'agent_blueprint_id' => $author['blueprint']->id,
            'circle_id'          => $circle->id,
            'status'             => 'active',
        ]);

        $engagements = app(EngagementService::class);

        $engagement = $engagements->propose(
            circle: $circle, actor: $dana, engaging: $convener, contractor: $robotics,
            principalType: PrincipalType::AgentInstance, principalId: $instance->id,
            title: 'Weld report reading', feeBasis: FeeBasis::PerAction,
        );

        $engagement->forceFill([
            'status' => EngagementStatus::Active, 'activated_at' => now(), 'starts_at' => now(),
        ])->save();

        foreach ([AgentActionStatus::Executed, AgentActionStatus::Rejected, AgentActionStatus::Rejected] as $status) {
            AgentAction::create([
                'circle_id'         => $circle->id,
                'agent_instance_id' => $instance->id,
                'tool_key'          => 'post_comment',
                'side_effect'       => SideEffect::CircleWrite->value,
                'status'            => $status,
                'intent'            => 'Flag joint 14.',
            ]);
        }

        $engagements->complete($engagement->fresh(), $dana, 'Reading finished.');

        $record = app(\App\Services\Work\WorkRecordService::class)->compile($engagement->fresh());

        // A blueprint, not an instance. An instance dies with its Circle, and
        // a record that died with its Circle is the exact failure §21.3 exists
        // to fix.
        $this->assertSame(PrincipalType::AgentBlueprint, $record->principal_type);
        $this->assertSame($author['blueprint']->id, $record->principal_id);

        $this->assertSame(3, $record->metric('actions_proposed'));
        $this->assertSame(1, $record->metric('actions_executed'));

        // The unflattering half, kept where a renderer cannot drop it. An
        // agent that keeps proposing actions its counterparty refuses is the
        // single thing a prospective hirer most needs to see.
        $this->assertSame(2, $record->refusals_json['actions_rejected']);
        $this->assertSame(['post_comment' => 2], $record->refusals_json['rejected_tools']);
    }
}
