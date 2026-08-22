<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\AgentBlueprint;
use App\Services\Agent\StewardPrompt;
use Illuminate\Database\Seeder;

/**
 * The Circle Steward's declared mandate (spec §9).
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
                'name'    => 'Circle Steward',
                'version' => '1.0.0',
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
    }
}
