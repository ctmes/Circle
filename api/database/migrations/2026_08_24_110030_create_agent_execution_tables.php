<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agents that do things.
     *
     * The MVP ran one system agent that could only read and could only produce
     * drafts. Going AI-first means two changes it explicitly ruled out: people
     * author their own agents, and agents execute.
     *
     * This does not discard the verification machinery — it is what makes
     * execution sellable. Two companies who do not fully trust each other will
     * not let the other side's agent touch a shared project unless every action
     * it takes is attributable, bounded by a declared mandate, and refusable.
     * Claims, decisions and the audit chain stop being paperwork at exactly the
     * moment agents start acting.
     *
     * The rule these tables enforce: an agent proposes, and a side effect needs
     * a human holding the authority of the party that bears it.
     */
    public function up(): void
    {
        Schema::table('agent_blueprints', function (Blueprint $table) {
            // Null organisation = system blueprint (the Steward). Otherwise the
            // org that authored it. `key` stays globally unique; author keys are
            // namespaced by org slug at creation.
            $table->foreignUlid('organisation_id')->nullable()->after('key')
                ->constrained('organisations')->cascadeOnDelete();

            // Set when an agent was built for one Circle and should not escape it.
            $table->foreignUlid('circle_id')->nullable()->after('organisation_id')
                ->constrained('circles')->cascadeOnDelete();

            $table->foreignUlid('created_by_user_id')->nullable()->after('circle_id')
                ->constrained('users');
            $table->boolean('is_system')->default(false)->after('created_by_user_id');

            /**
             * How far this agent may go, independent of its tool list:
             *
             *   read_only — reads permitted context, writes nothing
             *   propose   — may create drafts (claims, goals, decision requests)
             *   execute   — may run tools with side effects, subject to approval
             *
             * Checked in AccessGate before the tool list is consulted, so
             * downgrading an agent to read_only is one column and takes effect
             * everywhere at once.
             */
            $table->string('execution_mode')->default('read_only')->after('is_system')->index();

            // internal = runs on Circle's own model calls.
            // external = supplied by a party, reached over its connection.
            $table->string('provider')->default('internal')->after('execution_mode');

            $table->string('status')->default('active')->after('provider')->index();
            $table->text('instructions')->nullable()->after('mandate');
        });

        /**
         * The commands an agent may run.
         *
         * `side_effect` is the axis that matters — not what the tool is called,
         * but what happens if it is wrong. Anything above `circle_write`
         * defaults to requiring approval, and the service layer may tighten
         * that but never loosen it below the blueprint's declaration.
         */
        Schema::create('agent_tools', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_blueprint_id')->constrained('agent_blueprints')->cascadeOnDelete();

            $table->string('key');
            $table->string('name');
            $table->text('description');

            // none | circle_write | external_read | external_write | financial
            $table->string('side_effect')->default('none')->index();

            $table->boolean('requires_approval')->default(true);
            // Which Circle role must sign off. Null falls back to the side
            // effect's default (owner for external_write and financial).
            $table->string('approval_role')->nullable();

            // Whether the approver must belong to the party bearing the effect,
            // rather than merely holding the role somewhere in the Circle.
            $table->boolean('requires_owning_party')->default(true);

            $table->jsonb('input_schema_json')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['agent_blueprint_id', 'key']);
        });

        /**
         * A party bringing its own agent.
         *
         * The contractor's agent runs under the contractor's party and its own
         * credentials. Circle never holds the raw secret — `credential_ref`
         * points at the secret store, and `key_fingerprint` is what gets shown
         * to the counterparty when they are asked to admit this agent.
         */
        Schema::create('agent_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->foreignUlid('circle_party_id')->constrained('circle_parties')->cascadeOnDelete();
            $table->foreignUlid('agent_blueprint_id')->nullable()->constrained('agent_blueprints');

            $table->string('name');
            $table->string('provider_label')->nullable();

            // delegated_token | signed_webhook | mcp
            $table->string('auth_mode')->default('signed_webhook');
            $table->text('endpoint_url')->nullable();
            $table->string('credential_ref')->nullable();
            $table->string('key_fingerprint')->nullable();

            $table->string('status')->default('pending')->index();

            // Admitting another party's agent is a decision, not a setting.
            $table->foreignUlid('admitted_via_decision_id')->nullable()
                ->constrained('decisions')->nullOnDelete();
            $table->foreignUlid('admitted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('admitted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['circle_id', 'status']);
        });

        /**
         * The execution ledger. One row per attempted action, written before
         * the attempt, so a refused or failed action is as auditable as a
         * successful one — the same reason `agent_runs` records its retrieval
         * manifest before the model call.
         */
        Schema::create('agent_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->foreignUlid('agent_instance_id')->constrained('agent_instances')->cascadeOnDelete();
            $table->foreignUlid('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignUlid('agent_tool_id')->nullable()->constrained('agent_tools')->nullOnDelete();

            // Kept flat so the ledger still reads correctly after a blueprint
            // is edited or a tool is removed.
            $table->string('tool_key');
            $table->string('side_effect')->index();

            // proposed | awaiting_approval | approved | rejected
            //          | executing | executed | failed | cancelled | expired
            $table->string('status')->default('proposed')->index();

            $table->text('intent')->nullable();
            $table->jsonb('arguments_json')->nullable();
            $table->jsonb('result_json')->nullable();
            $table->text('error')->nullable();

            /**
             * Whose authority this action runs under. An agent never acts on
             * behalf of "the Circle" — it acts for a party, and that party
             * carries the consequence.
             */
            $table->foreignUlid('on_behalf_of_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();

            // Approval reuses the decision machinery rather than inventing a
            // second one, so an agent's action lands in the same record as
            // every other thing a human signed off on.
            $table->foreignUlid('decision_id')->nullable()->constrained('decisions')->nullOnDelete();
            $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('rejected_by_user_id')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // An unapproved action does not wait forever.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            // Retries and duplicate tool calls must not double-execute.
            $table->string('idempotency_key')->nullable()->unique();

            $table->timestamps();

            $table->index(['circle_id', 'status']);
            $table->index(['agent_instance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_actions');
        Schema::dropIfExists('agent_connections');
        Schema::dropIfExists('agent_tools');

        Schema::table('agent_blueprints', function (Blueprint $table) {
            $table->dropColumn([
                'is_system', 'execution_mode', 'provider', 'status', 'instructions',
            ]);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('circle_id');
            $table->dropConstrainedForeignId('organisation_id');
        });
    }
};
