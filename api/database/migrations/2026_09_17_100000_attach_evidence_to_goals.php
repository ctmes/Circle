<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidence filed against a node of the plan.
     *
     * Until now evidence carried no goal at all: it reached the tree only
     * through the claims that cited it. That is the right rule for what a
     * claim *rests on*, and the wrong rule for what a piece of work simply
     * has attached to it — the signed drawing, the photograph of the pour,
     * the delivery note. Asking somebody to author a claim before they can
     * put a file on a job gets one of two outcomes, and neither is a claim
     * anybody meant: a sentence written to satisfy the form, or the file
     * left in the general vault where nobody looking at the job will find it.
     *
     * A join table rather than a column, because one document routinely
     * belongs to several pieces of work — a programme covers the whole tree,
     * a variation covers the two packages it moves.
     *
     * It is an index, not an access boundary. Party scope stays on the item
     * (see the restricted_to_party_id migration): attaching cannot widen who
     * may see a file, and every read here is filtered through the same
     * visibility rule the register uses.
     */
    public function up(): void
    {
        Schema::create('evidence_item_goal', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Both cascade: the link is a statement about a pair, and it means
            // nothing once either end is gone. Neither end is deleted in the
            // ordinary course — evidence is superseded and goals are abandoned
            // — so this is the tidy-up after a Circle is torn down, not a path
            // the application walks.
            $table->foreignUlid('evidence_item_id')->constrained('evidence_items')->cascadeOnDelete();
            $table->foreignUlid('goal_id')->constrained('goals')->cascadeOnDelete();

            // Who filed it here, which is not always who uploaded it: pulling
            // an existing document onto a job is its own act and the packet
            // should be able to say who performed it.
            $table->foreignUlid('attached_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->useCurrent();

            // Attaching twice is the same statement made twice, and a drag
            // onto a job somebody already filed it against is a normal thing
            // to do by accident.
            $table->unique(['evidence_item_id', 'goal_id']);
            $table->index(['goal_id', 'attached_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_item_goal');
    }
};
