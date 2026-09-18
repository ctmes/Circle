<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Party-scoped evidence.
     *
     * The narrow half of what §20.5 leaves unbuilt. It is not the branch-scoped
     * tree permission that section calls a real design question — it is the one
     * case that stops a Circle spanning more than one counterparty at all:
     * every subcontractor can read every other subcontractor's rates, so nobody
     * puts their rates in.
     *
     * Null means the whole Circle and stays the default. Evidence is what a
     * Circle exists to pool, so it defaults the opposite way from comment
     * threads: a party working out its position in front of a counterparty is
     * private by nature, while a rate card submitted to the convener is not
     * private, merely not every other bidder's business.
     */
    public function up(): void
    {
        Schema::table('evidence_items', function (Blueprint $table) {
            // restrictOnDelete, not nullOnDelete: nulling this column widens
            // the item to the whole Circle, which is the precise leak the
            // column exists to prevent, and it would happen silently. Parties
            // are withdrawn rather than deleted, so nothing in the application
            // reaches this.
            $table->foreignUlid('restricted_to_party_id')->nullable()->after('classification')
                ->constrained('circle_parties')->restrictOnDelete();

            $table->index('restricted_to_party_id');
        });
    }

    public function down(): void
    {
        Schema::table('evidence_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restricted_to_party_id');
        });
    }
};
