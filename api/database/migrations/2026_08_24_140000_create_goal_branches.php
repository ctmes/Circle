<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposing a change to the plan without making it.
     *
     * Until now the goal tree had exactly one state and anyone with `goal.update`
     * changed it in place. In a single company that is fine. Across companies it
     * is the whole problem: a contractor who thinks three dates should move has
     * no way to say so except to move them, and a client who disagrees discovers
     * it after the fact. The negotiation happened in email, and the tree became
     * a record of who edited last rather than of what was agreed.
     *
     * A branch is a named set of *proposed* edits. Deliberately not a copy of the
     * tree.
     *
     * Copying would give you literal git — a parallel world you can look at whole
     * — and it would fork every id in it. Comments, claims, commitments and the
     * audit chain all point at goal ids; a duplicated subtree means every one of
     * those has to answer "which copy?", and merge has to decide whether a
     * comment left on the branch belongs to the surviving node. There is no
     * answer to that which is right more than half the time.
     *
     * A change row instead points at the real goal, names the field, and carries
     * both the value it wants and the value it was looking at. That last part is
     * what makes this safe: if main moved underneath while the branch was open,
     * the change is a conflict rather than a silent overwrite of somebody else's
     * work.
     */
    public function up(): void
    {
        Schema::create('goal_branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();

            $table->string('name');
            $table->text('intent')->nullable();

            /**
             * The party proposing. A branch belongs to a company before it
             * belongs to a person — the same reason goals carry a responsible
             * party. Whoever opened it may leave; the proposal is still theirs.
             */
            $table->foreignUlid('circle_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();
            $table->foreignUlid('created_by_user_id')->constrained('users');

            // draft | open | merged | withdrawn
            //
            // No `rejected`. A branch somebody refused is still open — the
            // author narrows it and asks again, which is what actually happens
            // in a negotiation. Closing it on the first refusal would make
            // refusing feel like killing the proposal rather than answering it.
            $table->string('status')->default('draft')->index();

            // Opened for review. Until then the changes are the author's alone.
            $table->timestamp('proposed_at')->nullable();

            // Merging reuses the decision machinery rather than inventing a
            // second one, so a merged branch lands in the packet as the thing
            // it is: a decision several companies signed.
            $table->foreignUlid('decision_id')->nullable()
                ->constrained('decisions')->nullOnDelete();

            $table->foreignUlid('merged_by_user_id')->nullable()->constrained('users');
            $table->timestamp('merged_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['circle_id', 'status']);
        });

        Schema::create('goal_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('goal_branch_id')->constrained('goal_branches')->cascadeOnDelete();

            // add | update | remove
            $table->string('change_type')->index();

            /**
             * The goal on main this touches. Null for an add, which has no
             * subject yet.
             *
             * `nullOnDelete` rather than cascade: if the goal is deleted while
             * a branch proposing changes to it is open, the change should
             * survive long enough to be reported as a conflict. A change row
             * that silently vanishes is how a reviewer ends up approving a
             * different proposal from the one they read.
             */
            $table->foreignUlid('goal_id')->nullable()->constrained('goals')->nullOnDelete();

            /**
             * For adds: a key unique within the branch, so a change can nest
             * under another add that does not have an id yet. "Add a package,
             * and three sub-goals under it" is one coherent proposal, not four
             * that only work if merged in order.
             */
            $table->string('temp_key')->nullable();
            $table->string('parent_temp_key')->nullable();
            $table->foreignUlid('parent_goal_id')->nullable()->constrained('goals')->nullOnDelete();

            /** The proposed values. For an update, only the fields it touches. */
            $table->jsonb('attributes_json')->nullable();

            /**
             * What those same fields held on main when the change was written.
             *
             * The entire conflict story. Without it a merge is last-write-wins
             * dressed up as agreement, and the party that signed off is
             * endorsing a diff that no longer describes what will happen.
             */
            $table->jsonb('base_json')->nullable();

            // A date move still needs its reason. Branch or no branch, a date
            // that can move without one carries no weight.
            $table->text('reason')->nullable();

            $table->integer('position')->default(0);
            $table->foreignUlid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['goal_branch_id', 'position']);
            $table->unique(['goal_branch_id', 'temp_key']);
        });

        Schema::table('decision_approvals', function (Blueprint $table) {
            /**
             * Which company signed, not only which person.
             *
             * A merge needs agreement from every party the branch touches, and
             * "Rae approved" does not answer whether Beam Rail did — Rae might
             * hold a seat for a different party, or none. Stored rather than
             * derived because membership changes and the record must still read
             * correctly in a year.
             */
            $table->foreignUlid('circle_party_id')->nullable()->after('actor_user_id')
                ->constrained('circle_parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('decision_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('circle_party_id');
        });

        Schema::dropIfExists('goal_changes');
        Schema::dropIfExists('goal_branches');
    }
};
