<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The temp contract, and the fact that it bounds the gate (spec §21.2).
     *
     * Until now the commercial relationship between two companies in a Circle
     * was a `party_role` string. "Contractor" carries no rate, no term, no
     * scope and no end — so a contractor engaged for one deliverable in March
     * still has the same access in November, and the only thing that ever
     * ends is the Circle.
     *
     * An engagement is that relationship written down in a form the
     * authorisation layer can read. `circle_memberships.engagement_id` is what
     * makes it more than a record: AccessGate intersects a member's
     * permissions with their engagement's state and scope as check (7). Temp
     * is a property of the authorisation decision, or it is marketing.
     *
     * The check can only ever narrow. An engagement never grants anything the
     * role did not already permit, which is why it runs last and why nothing
     * above it needs to know it exists.
     */
    public function up(): void
    {
        Schema::create('engagements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();

            /** Who is paying, and who is answerable for the work. */
            $table->foreignUlid('engaging_party_id')->constrained('circle_parties')->cascadeOnDelete();
            $table->foreignUlid('contractor_party_id')->constrained('circle_parties')->cascadeOnDelete();

            /**
             * The principal actually doing it: a person, or an agent instance.
             *
             * Polymorphic rather than two nullable columns because the two are
             * genuinely alternatives and a row with both set has no meaning.
             * `principal_type` is the PrincipalType vocabulary minus
             * `organisation` — an organisation is the contractor party, and
             * expressing it twice would let the two disagree.
             */
            $table->string('principal_type')->index();
            $table->ulid('principal_id')->index();

            $table->foreignUlid('work_opening_id')->nullable()
                ->constrained('work_openings')->nullOnDelete();

            /**
             * For an agent engagement: the version hired.
             *
             * The mandate a counterparty agreed to pay for, pinned. An author
             * editing the blueprint afterwards does not change what was hired
             * (spec §21.6).
             */
            $table->foreignUlid('agent_blueprint_version_id')->nullable();

            $table->string('title');
            $table->text('terms')->nullable();

            /**
             * The subtree this engagement confines writes to.
             *
             * Null means the whole Circle, which is the honest default for a
             * contractor engaged broadly. Where it is set, AccessGate refuses
             * a write to anything outside it — so "you were hired for the
             * geotech package" is enforced rather than assumed.
             *
             * `nullOnDelete` deliberately *widens* on deletion rather than
             * orphaning the engagement, and the service refuses to leave it
             * that way: see EngagementService::scopeDecision(), which treats a
             * scope that was set and is now missing as a deny.
             */
            $table->foreignUlid('scope_goal_id')->nullable()->constrained('goals')->nullOnDelete();

            // proposed | active | suspended | completed | terminated | expired
            $table->string('status')->default('proposed')->index();

            // fixed | hourly | daily | per_deliverable | per_action
            $table->string('fee_basis')->default('fixed');
            $table->bigInteger('fee_amount_minor')->nullable();
            $table->string('currency', 3)->default('AUD');

            /**
             * A ceiling on a metered engagement, in units.
             *
             * The one number an hourly contract needs and a handshake never
             * has. Enforced by the meter rather than by anybody's diligence.
             */
            $table->integer('unit_cap')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            /**
             * The signatures. An engagement is a thing two companies agreed,
             * so it reuses the decision machinery and lands in the packet as
             * one — rather than as a settings row somebody changed.
             */
            $table->foreignUlid('agreed_via_decision_id')->nullable()
                ->constrained('decisions')->nullOnDelete();
            $table->timestamp('agreed_at')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('end_reason')->nullable();

            /**
             * Which side ended it, where it was ended rather than run out.
             *
             * "The contractor walked" and "the client cut it short" are the
             * two facts a record most needs to distinguish, and neither is
             * recoverable from a timestamp.
             */
            $table->foreignUlid('ended_by_party_id')->nullable()
                ->constrained('circle_parties')->nullOnDelete();
            $table->foreignUlid('ended_by_user_id')->nullable()->constrained('users');

            $table->foreignUlid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['circle_id', 'status']);
            $table->index(['principal_type', 'principal_id', 'status']);
        });

        /**
         * One row per billable unit, written when an action is approved and
         * executed rather than when it is proposed (spec §21.6).
         *
         * An agent does not get paid for asking. That distinction is only
         * expressible because §20.4 wrote the proposal to the ledger before
         * anything was attempted — the meter reads the half of that ledger
         * that actually happened.
         */
        Schema::create('engagement_meter_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('engagement_id')->constrained('engagements')->cascadeOnDelete();

            /** hour | day | deliverable | action — from FeeBasis::unit(). */
            $table->string('unit');

            /** Thousandths of a unit, so 1.5 hours is 1500 and never a float. */
            $table->integer('quantity_milli')->default(1000);

            $table->text('note')->nullable();

            /**
             * What this entry is evidence of. An `agent_action` for a metered
             * agent, a `commitment` for a deliverable, null for a person's
             * hours — which are asserted rather than derived, and the record
             * says which.
             */
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();

            $table->foreignUlid('recorded_by_user_id')->nullable()->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();

            /**
             * One entry per source. Re-running the sweep, or approving an
             * action that was already drained, must not bill twice — and a
             * unique index is the only place that guarantee survives a
             * concurrent worker.
             */
            $table->unique(['engagement_id', 'source_type', 'source_id']);
            $table->index(['engagement_id', 'occurred_at']);
        });

        Schema::table('circle_memberships', function (Blueprint $table) {
            /**
             * The engagement that issued this seat.
             *
             * Null for everyone who was simply invited — the ordinary case,
             * and untouched. Where it is set, the seat lives and dies with the
             * contract: AccessGate reads it as check (7).
             *
             * `restrictOnDelete`, not `nullOnDelete`. Nulling it would take a
             * seat whose contract was deleted and silently *widen* it into an
             * ordinary contributor's — a contractor confined to one package
             * would gain the run of the whole Circle the moment somebody
             * removed the contract confining them. An engagement is never
             * deleted in this product; it ends, and ending is a state with a
             * reason (§21.2). The constraint is what makes that true rather
             * than merely intended.
             */
            $table->foreignUlid('engagement_id')->nullable()->after('circle_party_id')
                ->constrained('engagements')->restrictOnDelete();
        });

        Schema::table('commitments', function (Blueprint $table) {
            /**
             * A deliverable under a contract.
             *
             * No new object: a commitment already has an owner party, an
             * acceptance condition, a due date and an update trail, which is a
             * deliverable in every respect that matters. Accepting it is what
             * closes the fee obligation.
             */
            $table->foreignUlid('engagement_id')->nullable()->after('goal_id')
                ->constrained('engagements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('engagement_id');
        });

        Schema::table('circle_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('engagement_id');
        });

        Schema::dropIfExists('engagement_meter_entries');
        Schema::dropIfExists('engagements');
    }
};
