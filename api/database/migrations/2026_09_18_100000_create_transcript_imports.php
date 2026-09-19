<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meeting transcripts arriving from outside, and what was done with each
     * (spec §24).
     *
     * This is the log the connector writes to. A transcript can arrive with no
     * Circle named — a note-taker pushing every meeting through a webhook has
     * no idea which piece of work each one was about — so an import belongs to
     * an organisation first and gets a Circle when it has been routed.
     *
     * Two changes to existing tables ride along, because both exist only so
     * that transcripts can act without a person:
     *
     *  - `agent_blueprints.autonomous`, which lets an agent's in-Circle writes
     *    be approved by policy rather than by somebody pressing a button.
     *  - `goals.completed_by_agent_run_id`, so a goal a transcript closed can
     *    say which reading closed it, separately from who accepted it.
     */
    public function up(): void
    {
        Schema::create('transcript_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();

            // Whoever's token pushed it. Every write the import causes is the
            // agent's, but the decision to connect a note-taker to this company
            // was a person's, and the record should be able to name them.
            $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Null until routed; set whether it went to an existing Circle or
            // opened a new one.
            $table->foreignUlid('circle_id')->nullable()->constrained('circles')->nullOnDelete();

            // Where it came from, as the sender describes it: "granola",
            // "zapier", "manual". Free text because the list of note-takers is
            // not ours to enumerate.
            $table->string('source', 60)->default('manual');

            // The sender's own id for this meeting. A webhook that retries is
            // the ordinary case, and processing the same meeting twice would
            // write every goal twice — so this is unique per organisation.
            $table->string('external_id', 200)->nullable();

            $table->string('title', 300)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->jsonb('participants_json')->nullable();

            // The transcript itself is filed as evidence in the Circle it was
            // routed to; this is the digest of what arrived, so an import can
            // be matched to its evidence item and a resend detected even
            // without an external id.
            $table->string('content_sha256', 64);
            $table->unsignedInteger('content_chars');

            // A Circle the sender asked for by id. When set, routing is
            // skipped: the sender knew and we do not second-guess it.
            $table->foreignUlid('requested_circle_id')->nullable()->constrained('circles')->nullOnDelete();

            $table->string('status', 20)->default('pending')->index();
            // routed_to_existing | opened_new | pinned
            $table->string('routing', 30)->nullable();
            $table->text('routing_reason')->nullable();

            $table->foreignUlid('evidence_item_id')->nullable()->constrained('evidence_items')->nullOnDelete();
            $table->ulid('agent_run_id')->nullable()->index();

            // What the transcript changed, in counts and in a list of the
            // actions it took. The ledger is authoritative; this is the log
            // line a person reads.
            $table->jsonb('result_json')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'external_id']);
            $table->index(['organisation_id', 'created_at']);
            $table->index(['organisation_id', 'content_sha256']);
        });

        Schema::table('agent_blueprints', function (Blueprint $table) {
            // Off for every agent that exists, including every one a customer
            // has written. Turning it on is a decision about one agent, and
            // it only ever reaches in-Circle writes — see SideEffect.
            $table->boolean('autonomous')->default(false);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->ulid('completed_by_agent_run_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('goals', fn (Blueprint $table) => $table->dropColumn('completed_by_agent_run_id'));
        Schema::table('agent_blueprints', fn (Blueprint $table) => $table->dropColumn('autonomous'));
        Schema::dropIfExists('transcript_imports');
    }
};
