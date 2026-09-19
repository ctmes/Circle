<?php

namespace Database\Seeders;

use App\Enums\AgentExecutionMode;
use App\Enums\Permission;
use App\Models\AgentBlueprint;
use App\Services\Agent\StewardPrompt;
use App\Models\AgentTool;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Convening\ConveningPrompt;
use App\Services\Transcripts\TranscriptPrompt;
use Illuminate\Database\Seeder;

/**
 * The mandates of the two agents that ship with the product (spec §9, §23).
 *
 * `allowed_actions` is enforced by AccessGate, not merely documentation — the
 * gate checks this list on every agent request. `prohibited_actions` records the
 * boundary in the form the "what this agent can access" UI shows to people, and
 * those capabilities have no implementation to reach in the first place.
 */
class AgentBlueprintSeeder extends Seeder
{
    public function run(): void
    {
        AgentBlueprint::updateOrCreate(
            ['key' => AgentBlueprint::STEWARD],
            [
                'name'      => 'Circle Steward',
                'version'   => '1.1.0',
                'is_system' => true,
                'status'    => 'active',
                'provider'  => 'internal',

                // The Steward drafts; it does not execute. Stated explicitly
                // rather than left to the column default, because the default
                // is read_only and that would silently strip the claim and
                // decision drafting its whole purpose depends on.
                'execution_mode' => AgentExecutionMode::Propose->value,
                'mandate' => 'Maintains mission clarity. Reads approved Circle context, identifies gaps and '
                    . 'contradictions, prepares sourced summaries, and drafts decision requests for humans '
                    . 'to resolve. Strictly read-only: it cannot act outside the Circle and everything it '
                    . 'produces is a draft requiring human review.',
                'allowed_actions' => [
                    Permission::CircleView->value,
                    Permission::ResourceAgentRead->value,
                    Permission::ClaimCreate->value,
                    Permission::DecisionCreate->value,
                    Permission::CommitmentCreate->value,
                ],
                'prohibited_actions' => [
                    'external_communication',
                    'email_chat_crm_api_writes',
                    'invite_users',
                    'change_permissions',
                    'delete_resources',
                    'approve_on_behalf_of_a_person',
                    'read_outside_its_circle',
                    'use_raw_user_or_connector_credentials',
                ],
                'prompt_version' => StewardPrompt::VERSION,
            ],
        );

        // The Circle Convener (spec 23). Its mandate is two permissions, which
        // is the whole of what ReadOnly's ceiling allows — it produces a
        // derived artifact and nothing else. The plan it proposes enters the
        // Circle only when a person accepts it, under that person's name, so
        // the agent needs no write permission to do its job and is given none.
        AgentBlueprint::updateOrCreate(
            ['key' => AgentBlueprint::CONVENER],
            [
                'name'      => 'Circle Convener',
                'version'   => '1.0.0',
                'is_system' => true,
                'status'    => 'active',
                'provider'  => 'internal',
                'execution_mode' => AgentExecutionMode::ReadOnly->value,
                'mandate' => 'Reads an engagement of terms - a contract, scope of works or letter of '
                    . 'appointment - and proposes the Circle it describes: a name, a purpose, the parties, '
                    . 'and a dated tree of work. It writes nothing. Every element is marked stated or '
                    . 'inferred and cited where the document supports it, and none of it enters the record '
                    . 'until a person accepts it.',
                'allowed_actions' => [
                    Permission::CircleView->value,
                    Permission::ResourceAgentRead->value,
                ],
                'prohibited_actions' => [
                    'create_goals',
                    'create_claims',
                    'create_decisions',
                    'invite_parties',
                    'external_communication',
                    'change_permissions',
                    'delete_resources',
                    'read_outside_its_circle',
                    'use_raw_user_or_connector_credentials',
                ],
                'prompt_version' => ConveningPrompt::VERSION,
            ],
        );

        // The Circle Scribe (spec 24). The only shipped agent that acts: it
        // reads meeting transcripts and keeps the plan current, with nobody
        // approving each change. Execute mode and autonomous — but autonomy
        // reaches in-Circle writes only, by SideEffect rather than by anything
        // here, so the five tools below are the whole of what it can do and a
        // tool that reached outside the Circle would still wait for a person.
        $scribe = AgentBlueprint::updateOrCreate(
            ['key' => AgentBlueprint::SCRIBE],
            [
                'name'           => 'Circle Scribe',
                'version'        => '1.0.0',
                'is_system'      => true,
                'status'         => 'active',
                'provider'       => 'internal',
                'execution_mode' => AgentExecutionMode::Execute->value,
                'autonomous'     => true,
                'mandate' => 'Reads meeting transcripts and keeps the plan current: adds the work a meeting agreed, '
                    . 'updates what it changed, closes what it reported done and drops what it decided against. '
                    . 'Acts without review, within this Circle only. Every change is on the action ledger with the '
                    . 'words from the meeting it rests on.',
                'allowed_actions' => [
                    Permission::CircleView->value,
                    Permission::ResourceAgentRead->value,
                    Permission::GoalCreate->value,
                    Permission::GoalUpdate->value,
                    Permission::CommitmentCreate->value,
                    Permission::AgentExecute->value,
                ],
                'prohibited_actions' => [
                    'external_communication',
                    'invite_users',
                    'change_permissions',
                    'accept_on_behalf_of_a_person',
                    'delete_records',
                    'read_outside_its_circle',
                    'use_raw_user_or_connector_credentials',
                ],
                'prompt_version' => TranscriptPrompt::VERSION,
            ],
        );

        // Its tools, declared from the handlers themselves so the schema the
        // model is shown can never drift from the one the handler reads.
        $registry = app(ToolRegistry::class);

        foreach (['create_goal', 'update_goal', 'complete_goal', 'abandon_goal', 'create_commitment'] as $key) {
            $handler = $registry->get($key);

            AgentTool::updateOrCreate(
                ['agent_blueprint_id' => $scribe->id, 'key' => $key],
                [
                    'name'                  => $handler->name(),
                    'description'           => $handler->description(),
                    'side_effect'           => $handler->sideEffect()->value,
                    'requires_approval'     => false,
                    'approval_role'         => null,
                    'requires_owning_party' => false,
                    'input_schema_json'     => $handler->inputSchema(),
                    'enabled'               => true,
                ],
            );
        }
    }
}
