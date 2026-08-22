<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users');
            $table->foreignUlid('approver_user_id')->nullable()->constrained('users');
            // Approval binds to an exact resource_type + id + version (spec §8).
            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('subject_version')->nullable();
            $table->ulid('agent_run_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->timestamps();

            $table->index(['circle_id', 'status']);
        });

        // Immutable resolution history — rows are appended, never updated.
        Schema::create('decision_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('decision_id')->constrained('decisions')->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users');
            $table->string('outcome');   // approved | rejected
            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('subject_version')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('commitments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('acceptance_condition')->nullable();
            $table->string('status')->default('open')->index();
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users');
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users');
            $table->string('created_by_type')->default('user');
            $table->ulid('agent_run_id')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('commitment_updates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commitment_id')->constrained('commitments')->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commitment_updates');
        Schema::dropIfExists('commitments');
        Schema::dropIfExists('decision_approvals');
        Schema::dropIfExists('decisions');
    }
};
