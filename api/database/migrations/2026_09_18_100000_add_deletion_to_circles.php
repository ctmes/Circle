<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a Circle, as a state rather than a DELETE.
     *
     * A real row delete is not available to the application. Every table that
     * hangs off a Circle cascades, so the claims, decisions and evidence would
     * go with it — and the audit chain would not go at all: `audit_events` is
     * nullOnDelete, which would splice this Circle's events into the global
     * chain and break verification of both. The record a Circle leaves behind
     * is the reason it existed, so deletion takes it out of everyone's reach
     * and leaves it standing, which also means it can be brought back.
     */
    public function up(): void
    {
        Schema::table('circles', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->index();
            $table->foreignUlid('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('circles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropColumn('deleted_at');
        });
    }
};
