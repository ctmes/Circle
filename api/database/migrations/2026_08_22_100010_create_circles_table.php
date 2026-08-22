<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('name');
            $table->text('purpose');
            $table->string('status')->default('draft')->index();
            $table->foreignUlid('owner_user_id')->constrained('users');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('circle_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('circle_role');
            $table->boolean('is_external')->default(false);
            $table->string('invite_status')->default('active');
            // A membership can expire independently of the Circle.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['circle_id', 'user_id']);
            $table->index(['circle_id', 'circle_role']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->string('email')->index();
            $table->string('circle_role');
            $table->boolean('is_external')->default(true);
            $table->string('token', 64)->unique();
            $table->foreignUlid('invited_by_user_id')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUlid('accepted_user_id')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Per-Circle, per-user additions or removals on top of the role default.
        Schema::create('role_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission');
            $table->boolean('allow')->default(true);
            $table->foreignUlid('granted_by_user_id')->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['circle_id', 'user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_grants');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('circle_memberships');
        Schema::dropIfExists('circles');
    }
};
