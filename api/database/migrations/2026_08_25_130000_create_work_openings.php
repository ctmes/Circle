<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work nobody has been found for yet, and the offers to do it (spec §21.1).
     *
     * A goal with no `responsible_party_id` is not a defect in the plan. It is
     * work somebody has to be found for — and it is the only thing in this
     * product that a person outside the Circle has any business seeing.
     *
     * This table is therefore the single deliberate exception to §15's rule
     * that nothing is reachable except through a Circle the caller belongs to.
     * The exception is bounded here rather than in a controller: an opening
     * carries its own copy of everything an applicant is shown. Nothing on it
     * is a foreign key into the Circle's substance, so there is no join a
     * future endpoint could follow to leak a tree, an evidence item or a
     * thread. `goal_id` exists to bind the award, and only the goal's title is
     * ever rendered outside.
     *
     * Applying does not grant access. Shortlisting does, and it is a separate
     * act by an owner — see WorkApplicationService::shortlist(). A company
     * that receives forty applications exposes its Circle to none of them.
     */
    public function up(): void
    {
        Schema::create('work_openings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();

            /**
             * The party doing the hiring. A posting belongs to a company
             * before it belongs to a person, for the same reason a goal's
             * responsibility does: whoever posted it may leave, and the
             * obligation to pay does not leave with them.
             */
            $table->foreignUlid('posted_by_party_id')->constrained('circle_parties')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->constrained('users');

            /**
             * The work, where the plan has been drawn. Nullable because a
             * Circle still being scoped can post before it has a tree, and
             * refusing that would push the earliest and most useful postings
             * off the platform.
             *
             * `nullOnDelete`: an opening survives its goal being abandoned, so
             * applicants are told the work is gone rather than finding a page
             * that vanished.
             */
            $table->foreignUlid('goal_id')->nullable()->constrained('goals')->nullOnDelete();

            $table->string('title');
            $table->text('brief')->nullable();

            /** What the acceptance test is, in the poster's words. */
            $table->text('acceptance_condition')->nullable();

            // human | agent | either
            $table->string('principal_kind')->default('either')->index();

            // draft | open | closed | filled | withdrawn
            $table->string('status')->default('draft')->index();

            // party | circle | network | public — defaults to network, which
            // is the organisations this one has already completed an
            // engagement with. See spec §21.5 for why that is the default
            // rather than public.
            $table->string('visibility')->default('network')->index();

            // fixed | hourly | daily | per_deliverable | per_action
            $table->string('fee_basis')->default('fixed');

            /**
             * Money is recorded and never moved (spec §21.7). An amount and a
             * currency with no transaction table anywhere is the schema saying
             * so, rather than a README paragraph nobody reads.
             *
             * Integer minor units. A rate stored as a float is a rounding
             * argument waiting to happen between two companies.
             */
            $table->bigInteger('fee_amount_minor')->nullable();
            $table->string('currency', 3)->default('AUD');

            /** The term being offered, before either side has agreed to one. */
            $table->timestamp('term_starts_at')->nullable();
            $table->timestamp('term_ends_at')->nullable();
            $table->integer('estimated_units')->nullable();

            $table->timestamp('closes_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();

            $table->index(['circle_id', 'status']);
            $table->index(['status', 'visibility']);
        });

        Schema::create('work_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_opening_id')->constrained('work_openings')->cascadeOnDelete();

            /**
             * The applicant, as a company. Applying binds the company to the
             * offer, not the individual who typed it — the same rule as
             * everywhere else in §20.
             *
             * There is no `circle_party_id` here, and that is the point: an
             * applicant has no party row until they are shortlisted. The party
             * is written by shortlist() and recorded on `admitted_party_id`
             * below, so "did this applicant ever get in" has a direct answer.
             */
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUlid('applicant_user_id')->constrained('users');

            /**
             * For an agent application: the exact mandate being offered.
             *
             * A version rather than a blueprint, so an author cannot widen the
             * mandate of an agent after a counterparty read it and decided
             * (spec §21.6).
             */
            $table->foreignUlid('agent_blueprint_version_id')->nullable();

            /**
             * The branch that would assign the work.
             *
             * Written when the applicant is shortlisted, not when they apply —
             * a branch needs Circle membership, and an applicant has none. Null
             * until then, which is exactly the shape of the admission rule.
             */
            $table->foreignUlid('goal_branch_id')->nullable()
                ->constrained('goal_branches')->nullOnDelete();

            // submitted | shortlisted | declined | withdrawn | awarded
            $table->string('status')->default('submitted')->index();

            /** The offer. The branch says what would change; this says on what terms. */
            $table->string('fee_basis')->nullable();
            $table->bigInteger('fee_amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('statement')->nullable();
            $table->text('availability')->nullable();

            /** The party row shortlisting created, if it got that far. */
            $table->foreignUlid('admitted_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('shortlisted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignUlid('decided_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            /**
             * One live application per company per opening. A company that
             * wants to change its offer narrows the one it has — the same rule
             * §20.7 applies to a refused branch, and for the same reason:
             * re-applying to dodge a refusal turns a negotiation into a queue.
             */
            $table->unique(['work_opening_id', 'organisation_id']);
            $table->index(['organisation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_applications');
        Schema::dropIfExists('work_openings');
    }
};
