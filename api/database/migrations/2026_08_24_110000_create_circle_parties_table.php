<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parties: the organisations a Circle spans.
     *
     * The MVP assumed one organisation with "external" guests hanging off it.
     * Contractor and company-to-company work has no such centre — a Circle is a
     * joint venture between parties who do not report to each other, and the
     * question "who owes this" is answered by an organisation before it is
     * answered by a person.
     *
     * `circles.organisation_id` survives as the *convener*: the party that
     * opened the Circle, holds the closure right and receives the packet. It no
     * longer implies that everyone else is a guest.
     */
    public function up(): void
    {
        Schema::create('circle_parties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();

            // Nullable on purpose: a counterparty is usually named in the plan
            // before its organisation exists on the platform. The row is bound
            // to a real organisation when its first member accepts an invite.
            $table->foreignUlid('organisation_id')->nullable()->constrained('organisations');
            $table->string('display_name');

            // What this party is here to do. Drives default permissions and,
            // more importantly, reads correctly in the export packet.
            $table->string('party_role')->default('contractor')->index();
            $table->string('status')->default('invited')->index();

            // Exactly one convener per Circle, enforced in the service layer.
            $table->boolean('is_convener')->default(false);

            // A party's own reference for this engagement — PO number, contract
            // ref, matter number. Every counterparty files it under something.
            $table->string('external_reference')->nullable();

            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['circle_id', 'organisation_id']);
            $table->index(['circle_id', 'status']);
        });

        Schema::table('circle_memberships', function (Blueprint $table) {
            // Which party this person represents. `is_external` stays as the
            // fast path the gate already reads, but it becomes derived: true
            // whenever the member's party is not the convener.
            $table->foreignUlid('circle_party_id')->nullable()->after('user_id')
                ->constrained('circle_parties')->nullOnDelete();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignUlid('circle_party_id')->nullable()->after('circle_id')
                ->constrained('circle_parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('circle_party_id');
        });

        Schema::table('circle_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('circle_party_id');
        });

        Schema::dropIfExists('circle_parties');
    }
};
