<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place to say something that is not yet about anything.
 *
 * §20.3 refused a Circle-wide channel, and the reasoning was sound: once a
 * general room exists the substance migrates into it and the structured record
 * decays into something somebody updates afterwards out of duty. Every thread
 * therefore had to hang off a goal, a claim, a decision, a commitment or an
 * evidence item.
 *
 * What that misses is the beginning of the work. Before there is a goal to
 * attach to, there is a question — "can you send last year's scope?", "are we
 * bidding this?" — and a product with nowhere to ask it does not prevent the
 * question. It sends the question to email, and then the answer is in email
 * too, and the thing §20.3 was protecting is lost anyway, to a worse place.
 *
 * So the room exists, and the original objection is answered in two ways rather
 * than by being overruled:
 *
 *   `title` gives a circle-scoped thread the name an object would have given
 *   it. A general room whose contents are one undifferentiated log is exactly
 *   the room §20.3 feared; a list of named topics is a table of contents.
 *
 *   `attached_*` records that a thread was moved onto an object, and when. That
 *   is the load-bearing part. Drift is not prevented by design here — it is
 *   made recoverable, and the move is one action that leaves its own trace.
 *   "The record decays" becomes a chore somebody can do rather than a fact.
 *
 * Both columns are nullable and object-scoped threads are untouched: a Circle
 * that never opens a general thread behaves exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comment_threads', function (Blueprint $table) {
            // What the thread is about, where no subject object says so.
            // Null for object-scoped threads, which take their label from the
            // thing they hang on.
            $table->string('title', 200)->nullable()->after('subject_id');

            // Where it came from, once it has been moved onto an object. Kept
            // rather than overwritten so a decision's conversation can still
            // say it began as a question nobody had filed yet.
            $table->timestamp('attached_at')->nullable();
            $table->foreignUlid('attached_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comment_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attached_by_user_id');
            $table->dropColumn(['title', 'attached_at']);
        });
    }
};
