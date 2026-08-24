<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goals and sub-goals: the workflow spine.
     *
     * The MVP had five peer collections (evidence, claims, decisions,
     * commitments, people) and no structure saying which came first. The goal
     * tree is that structure. Everything else hangs off a node:
     *
     *     goal
     *       ├── sub-goal ── commitments (who does the work)
     *       │                └── decision (sign-off on the result)
     *       └── sub-goal ── claims (what we believe, and on what evidence)
     *
     * `responsible_party_id` is the load-bearing column for inter-company work.
     * A person can leave the project; the company still owes the deliverable.
     */
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();

            // Self-reference makes sub-goals the same object at another depth.
            // Depth is capped in the service layer, not the schema: two levels
            // is the useful case and unbounded nesting becomes a WBS nobody reads.
            // The constraint itself is added after the table exists — Postgres
            // will not reference a primary key the statement is still creating.
            $table->ulid('parent_goal_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();

            // The person doing it, and the organisation answerable for it.
            // Both nullable: unowned work is a real and visible state.
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users');
            $table->foreignUlid('responsible_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();

            // What "done" means, agreed in advance. Without it, completion is
            // self-certified and the counterparty has nothing to point at.
            $table->text('acceptance_condition')->nullable();
            $table->foreignUlid('accepted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('accepted_at')->nullable();

            // The decision that carried the sign-off, when acceptance was
            // formal rather than a click.
            $table->foreignUlid('accepted_via_decision_id')->nullable()
                ->constrained('decisions')->nullOnDelete();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();

            // Reported progress, distinct from derived progress. A number a
            // human asserts is a claim about the work, not a measurement of it.
            $table->unsignedTinyInteger('progress')->default(0);
            $table->foreignUlid('progress_set_by_user_id')->nullable()->constrained('users');
            $table->timestamp('progress_set_at')->nullable();

            // Ordering within a parent; agents may propose goals, so authorship
            // follows the same actor convention as every other created object.
            $table->unsignedInteger('position')->default(0);
            $table->string('created_by_type')->default('user');
            $table->ulid('created_by_id')->nullable();

            $table->timestamps();

            $table->index(['circle_id', 'parent_goal_id', 'position']);
            $table->index(['circle_id', 'status']);
            $table->index(['responsible_party_id', 'status']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->foreign('parent_goal_id')->references('id')->on('goals')->cascadeOnDelete();
        });

        /**
         * Every movement of a due date, with a reason and who agreed.
         *
         * In inter-company work a slipped date is the most common thing anyone
         * ends up arguing about. If dates can be edited silently they carry no
         * weight, so the trail is a first-class table rather than an audit-log
         * grep — and a change that affects another party can require its assent.
         */
        Schema::create('goal_schedule_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('goal_id')->constrained('goals')->cascadeOnDelete();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->timestamp('from_due_at')->nullable();
            $table->timestamp('to_due_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignUlid('changed_by_user_id')->constrained('users');

            // Set when the move needed the counterparty to agree.
            $table->foreignUlid('requires_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();
            $table->foreignUlid('agreed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('agreed_at')->nullable();

            $table->timestamps();

            $table->index(['goal_id', 'created_at']);
        });

        // Wire the existing collections onto the tree. All nullable: a Circle
        // that never builds a goal tree keeps working exactly as it does today.
        Schema::table('commitments', function (Blueprint $table) {
            $table->foreignUlid('goal_id')->nullable()->after('circle_id')
                ->constrained('goals')->nullOnDelete();
            $table->foreignUlid('owner_party_id')->nullable()->after('owner_user_id')
                ->constrained('circle_parties')->nullOnDelete();
        });

        Schema::table('decisions', function (Blueprint $table) {
            $table->foreignUlid('goal_id')->nullable()->after('circle_id')
                ->constrained('goals')->nullOnDelete();
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->foreignUlid('goal_id')->nullable()->after('circle_id')
                ->constrained('goals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('claims', fn (Blueprint $t) => $t->dropConstrainedForeignId('goal_id'));
        Schema::table('decisions', fn (Blueprint $t) => $t->dropConstrainedForeignId('goal_id'));
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_party_id');
            $table->dropConstrainedForeignId('goal_id');
        });

        Schema::dropIfExists('goal_schedule_changes');
        Schema::dropIfExists('goals');
    }
};
