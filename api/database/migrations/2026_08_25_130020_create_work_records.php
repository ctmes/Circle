<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a principal carries away (spec §21.3).
     *
     * Everything of value produced inside a Circle is scoped to `circle_id`
     * and ends at the export packet. For an operating environment that is
     * right; for a place where companies engage temporary people and agents it
     * is fatal, because nothing compounds and every engagement starts at zero.
     *
     * This is the one table in the product that is deliberately *not* scoped
     * to a Circle. `circle_id` is nullable and denormalised alongside a stored
     * `circle_name`, so a record still reads correctly after the Circle it
     * came from has been closed, exported and deleted.
     *
     * Three properties are the reason it is worth anything, and each is
     * enforced here rather than described:
     *
     *   - It is attested by the counterparty. `attested_by_party_id` is the
     *     *engaging* party, and the service refuses a signature from the
     *     principal's own side. A self-written record is a CV.
     *
     *   - It has its own hash chain, keyed on the principal rather than the
     *     Circle, so a record verifies without the Circle. Same construction
     *     as §11 and deliberately a separate chain.
     *
     *   - Publication is per record, and the numbers are not editable. Hiding
     *     a bad engagement is allowed; editing one is not. A gap in an
     *     otherwise-published run is itself legible, which is the correct
     *     trade against nobody ever agreeing to be measured at all.
     */
    public function up(): void
    {
        Schema::create('work_records', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // user | organisation | agent_blueprint
            //
            // A blueprint rather than an instance: an instance is bound to one
            // Circle and dies with it, and a record that died with its Circle
            // is the exact failure this table exists to fix.
            $table->string('principal_type');
            $table->ulid('principal_id');

            /** The company the principal was working for at the time. */
            $table->foreignUlid('principal_organisation_id')->nullable()
                ->constrained('organisations')->nullOnDelete();

            /**
             * The counterparty, denormalised.
             *
             * `counterparty_name` is stored rather than joined because the
             * whole point is that this row outlives the Circle, and a name
             * that resolves through three foreign keys does not.
             */
            $table->foreignUlid('counterparty_organisation_id')->nullable()
                ->constrained('organisations')->nullOnDelete();
            $table->string('counterparty_name');

            $table->foreignUlid('circle_id')->nullable()->constrained('circles')->nullOnDelete();
            $table->string('circle_name');
            $table->foreignUlid('engagement_id')->nullable()
                ->constrained('engagements')->nullOnDelete();

            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('party_role')->nullable();
            $table->string('fee_basis')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // completed | terminated | expired | abandoned
            $table->string('outcome')->index();

            /**
             * The compiled figures, from the audit chain rather than reported.
             *
             * A jsonb column rather than fifteen integers: the vocabulary will
             * grow as the ledger does, and a record written last year must
             * keep verifying against the hash it was signed with even after
             * this year's counters are added. Fixed columns would force a
             * migration that rewrites signed rows.
             */
            $table->jsonb('metrics_json');

            /**
             * The unflattering half, kept where it cannot be quietly dropped.
             *
             * An agent that keeps proposing actions its counterparty refuses
             * is the single thing a prospective hirer most needs to see, and
             * it is already in the ledger. Splitting it out of `metrics_json`
             * means a renderer cannot omit it by accident.
             */
            $table->jsonb('refusals_json')->nullable();

            /** The counterparty's signature, and their words. */
            $table->foreignUlid('attested_by_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();
            $table->foreignUlid('attested_by_user_id')->nullable()->constrained('users');
            $table->timestamp('attested_at')->nullable();
            $table->text('attestation_note')->nullable();

            // party | circle | network | public. Null means unpublished, which
            // is the default: a record is compiled whether or not its subject
            // ever wants it seen.
            $table->string('visibility')->nullable()->index();
            $table->timestamp('published_at')->nullable();

            /**
             * The chain. `sequence` is per principal, assigned by the service
             * under a lock, for the same reason §11 serialises its appends:
             * two concurrent writers reading the same head fork the chain.
             */
            $table->unsignedBigInteger('sequence');
            $table->string('previous_hash')->nullable();
            $table->string('record_hash');

            $table->timestamps();

            $table->unique(['principal_type', 'principal_id', 'sequence']);
            $table->unique(['engagement_id', 'principal_type', 'principal_id']);
            $table->index(['principal_type', 'principal_id', 'visibility']);
        });

        /**
         * Who has worked with whom (spec §21.5).
         *
         * Derived from completed engagements rather than declared, so the
         * network is a fact about work done rather than a list somebody
         * curated. This is what `network` visibility resolves against, and the
         * reason it is worth defaulting to.
         *
         * Stored with the pair ordered so one relationship is one row —
         * "a has worked with b" and "b has worked with a" are the same fact,
         * and two rows would let them disagree.
         */
        Schema::create('organisation_relationships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_a_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUlid('organisation_b_id')->constrained('organisations')->cascadeOnDelete();

            $table->unsignedInteger('engagements_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->timestamp('first_engaged_at')->nullable();
            $table->timestamp('last_engaged_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_a_id', 'organisation_b_id']);
            $table->index('organisation_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_relationships');
        Schema::dropIfExists('work_records');
    }
};
