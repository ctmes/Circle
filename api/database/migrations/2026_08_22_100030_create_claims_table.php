<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->string('author_type');           // user | agent
            $table->ulid('author_id')->nullable();
            $table->text('statement');
            $table->string('claim_type');
            $table->string('status')->default('draft')->index();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->ulid('agent_run_id')->nullable()->index();
            $table->timestamps();

            $table->index(['circle_id', 'status']);
        });

        Schema::create('claim_citations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('claim_id')->constrained('claims')->cascadeOnDelete();
            // Citations bind to an exact *version*, never to the mutable item.
            $table->foreignUlid('evidence_version_id')->constrained('evidence_versions');
            $table->string('citation_type');
            $table->jsonb('locator_json')->nullable();
            $table->text('excerpt')->nullable();
            $table->timestamps();
        });

        Schema::create('claim_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->foreignUlid('reviewer_user_id')->constrained('users');
            $table->string('outcome');   // reviewed | changes_requested | contested | rejected
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_reviews');
        Schema::dropIfExists('claim_citations');
        Schema::dropIfExists('claims');
    }
};
