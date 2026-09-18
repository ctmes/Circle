<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mandate somebody else can hire (spec §21.6).
     *
     * §20.6 established that only a blueprint's author may edit it: the client
     * can refuse a contractor's agent or throw it out, but cannot quietly
     * widen its mandate and leave the contractor carrying what it does.
     *
     * Hiring inverts the risk. Once a counterparty is *paying* for an agent,
     * the author editing it silently is worth money — the hirer approved a
     * mandate, a tool list and an execution mode, and every one of those can
     * be changed underneath them by the party being paid.
     *
     * So a version is an immutable snapshot, and an engagement names a version
     * rather than a blueprint. The blueprint stays the living thing its author
     * edits; the version is what was agreed. Same relationship as an evidence
     * version to its item (§6.2), and for exactly the same reason: a citation
     * made last month must still resolve to the bytes it cited.
     */
    public function up(): void
    {
        Schema::create('agent_blueprint_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_blueprint_id')->constrained('agent_blueprints')->cascadeOnDelete();
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            /**
             * The snapshot. Copied rather than joined — the whole point is
             * that editing the blueprint does not change this row.
             */
            $table->string('name');
            $table->text('mandate');
            $table->text('instructions')->nullable();
            $table->string('execution_mode');
            $table->string('prompt_version')->nullable();
            $table->jsonb('allowed_actions')->nullable();
            $table->jsonb('prohibited_actions')->nullable();

            /**
             * The declared tools, with their side-effect classification.
             *
             * Frozen alongside the mandate because a tool added after the
             * hire is the same problem as a mandate widened after the hire,
             * and §20.4's rule that classification is by consequence means
             * this list is what the approval bar was set from.
             */
            $table->jsonb('tools_json');

            /**
             * What a hirer is shown before they agree, in one string.
             *
             * SHA256 over the canonical form of everything above. A hirer can
             * be told "this is the same mandate you approved in March" without
             * anybody reading two pages of JSON — and the check is mechanical
             * rather than a promise.
             */
            $table->string('content_hash');

            // private | network | public — whether this version is offerable
            // outside its own organisation at all.
            $table->string('listing_status')->default('private')->index();

            $table->text('release_note')->nullable();
            $table->foreignUlid('published_by_user_id')->constrained('users');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['agent_blueprint_id', 'version_number']);
            $table->index(['organisation_id', 'listing_status']);
        });

        /**
         * The two references written before this table existed.
         *
         * `work_applications` and `engagements` both name a version, and both
         * were created in earlier migrations in this batch. The columns were
         * declared there without a constraint so the migration order stays
         * readable — openings before engagements before this — and the
         * constraints are added here, where the target finally exists.
         */
        Schema::table('work_applications', function (Blueprint $table) {
            $table->foreign('agent_blueprint_version_id')
                ->references('id')->on('agent_blueprint_versions')->nullOnDelete();
        });

        Schema::table('engagements', function (Blueprint $table) {
            $table->foreign('agent_blueprint_version_id')
                ->references('id')->on('agent_blueprint_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropForeign(['agent_blueprint_version_id']);
        });

        Schema::table('work_applications', function (Blueprint $table) {
            $table->dropForeign(['agent_blueprint_version_id']);
        });

        Schema::dropIfExists('agent_blueprint_versions');
    }
};
