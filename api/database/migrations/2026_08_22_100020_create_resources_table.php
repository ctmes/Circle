<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Polymorphic base registry for any Circle resource (spec §14).
        // Access policy hangs off this row, so one gate covers every type.
        Schema::create('resources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->string('resource_type')->index();
            $table->string('name');
            $table->string('created_by_type')->default('user');
            $table->ulid('created_by_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['circle_id', 'resource_type']);
        });

        Schema::create('evidence_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('origin_status')->default('authenticated_upload');
            $table->string('integrity_status')->default('unknown');
            $table->string('review_status')->default('unreviewed');
            $table->string('classification')->default('internal');
            $table->string('source_label')->nullable();
            $table->text('source_url')->nullable();
            $table->foreignUlid('uploader_user_id')->nullable()->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->ulid('superseded_by_id')->nullable();
            // Agent read is opt-in per item, never inherited (spec §10).
            $table->boolean('agent_read')->default(false)->index();
            $table->boolean('downloadable')->default(true);
            $table->timestamp('stale_at')->nullable();
            $table->timestamps();
        });

        Schema::create('evidence_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('evidence_item_id')->constrained('evidence_items')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('storage_key');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('sha256', 64)->nullable()->index();
            $table->jsonb('metadata_json')->nullable();
            $table->string('extracted_text_status')->default('pending');
            $table->string('preview_status')->default('pending');
            $table->string('transcript_status')->default('pending');
            $table->string('processing_status')->default('pending')->index();
            $table->text('processing_error')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users');
            $table->ulid('supersedes_version_id')->nullable();
            $table->timestamps();

            $table->unique(['evidence_item_id', 'version_number']);
        });

        Schema::create('derived_artifacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->string('parent_resource_type');
            $table->ulid('parent_resource_id')->nullable();
            $table->string('artifact_type')->index();
            $table->jsonb('content_json')->nullable();
            $table->string('storage_key')->nullable();
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->string('prompt_version')->nullable();
            $table->ulid('agent_run_id')->nullable()->index();
            // Exactly which source artifacts produced this output.
            $table->jsonb('source_manifest_json')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['parent_resource_type', 'parent_resource_id']);
        });

        Schema::table('evidence_items', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('evidence_items');
        });

        Schema::table('evidence_versions', function (Blueprint $table) {
            $table->foreign('supersedes_version_id')->references('id')->on('evidence_versions');
        });

        // Explicit per-user overrides on top of the resource default policy.
        Schema::create('resource_access_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('permission');
            $table->boolean('allow');
            $table->foreignUlid('granted_by_user_id')->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['resource_id', 'user_id', 'permission'], 'rao_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_access_overrides');
        Schema::dropIfExists('derived_artifacts');
        Schema::dropIfExists('evidence_versions');
        Schema::dropIfExists('evidence_items');
        Schema::dropIfExists('resources');
    }
};
