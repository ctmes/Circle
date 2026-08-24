<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversation, attached to the thing being discussed.
     *
     * Circle already had comments — welded to levers. `ClaimReview.comment`,
     * `DecisionApproval.comment` and `CommitmentUpdate.note` all let a person
     * speak only while changing a state. That is why the real conversation
     * leaves for email: there was no way to ask a question without committing
     * to an action.
     *
     * These tables unbundle the two. A thread hangs off any object; a comment
     * may carry an action (`action_type`/`action_id`) or carry nothing. The
     * existing lever-notes become comments of the first kind, so a claim's
     * history reads as one conversation rather than two parallel records.
     *
     * There is deliberately no Circle-wide channel. Once a general room exists
     * the substance migrates into it and the structured record becomes a thing
     * someone updates afterwards out of duty.
     */
    public function up(): void
    {
        Schema::create('comment_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();

            // goal | claim | decision | commitment | evidence_item
            $table->string('subject_type')->index();
            $table->ulid('subject_id');

            /**
             * Who can read this thread.
             *
             *   circle  — every party in the Circle
             *   party   — one party only (`visible_to_party_id`)
             *
             * `party` is the default for new threads. The opposite default from
             * evidence, and deliberately so: a contractor working out its
             * position in front of the client is how a Circle stops being used.
             */
            $table->string('visibility')->default('party')->index();
            $table->foreignUlid('visible_to_party_id')->nullable()
                ->constrained('circle_parties')->cascadeOnDelete();

            $table->string('status')->default('open')->index();
            $table->foreignUlid('resolved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();

            $table->string('created_by_type')->default('user');
            $table->ulid('created_by_id')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();

            $table->index(['circle_id', 'subject_type', 'subject_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('comment_thread_id')->constrained('comment_threads')->cascadeOnDelete();
            // Denormalised so the access gate and Circle-wide queries never
            // need the join.
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();

            $table->string('author_type')->default('user');
            $table->ulid('author_id')->nullable();
            $table->foreignUlid('author_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();

            $table->text('body');

            /**
             * Export inclusion, chosen per comment.
             *
             * The packet is the attributable record, and in a dispute it is
             * discoverable. If every aside lands in it people stop speaking
             * candidly and the feature dies. So: comments that carry an action
             * are always in the packet because they are part of the decision;
             * plain discussion is out unless someone marks it for the record.
             */
            $table->boolean('for_the_record')->default(false)->index();

            // Set when this comment carried a state change — the reviewer's
            // note, the approver's reason, the reason a commitment blocked.
            $table->string('action_type')->nullable();
            $table->ulid('action_id')->nullable();

            $table->timestamp('edited_at')->nullable();
            $table->foreignUlid('deleted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['comment_thread_id', 'created_at']);
            $table->index(['circle_id', 'created_at']);
        });

        /**
         * Mentions. The cheapest honest answer to "nothing tells anyone
         * anything" — and the reason a mention exists at all is that it is the
         * one signal strong enough to replace the side-channel.
         */
        Schema::create('comment_mentions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->foreignUlid('mentioned_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['comment_id', 'mentioned_user_id']);
            $table->index(['mentioned_user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_mentions');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('comment_threads');
    }
};
