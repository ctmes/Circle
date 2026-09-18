<?php

namespace Database\Seeders;

use App\Enums\AgentExecutionMode;
use App\Enums\Permission;
use App\Models\AgentBlueprint;
use App\Services\Agent\StewardPrompt;
use App\Services\Convening\ConveningPrompt;
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
    }
}
