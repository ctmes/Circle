<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A blueprint is the versioned, declarative mandate. There is exactly
        // one in the MVP (circle_steward) and no agent-creation UI (spec §12).
        Schema::create('agent_blueprints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('mandate');
            $table->string('version');
            $table->jsonb('allowed_actions');
            $table->jsonb('prohibited_actions');
            $table->string('prompt_version');
            $table->timestamps();
        });

        // An instance binds a blueprint to one Circle. Its identity is what the
        // policy gate authorises — never a user's credentials.
        Schema::create('agent_instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_blueprint_id')->constrained('agent_blueprints');
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_blueprint_id', 'circle_id']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_instance_id')->constrained('agent_instances')->cascadeOnDelete();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->foreignUlid('triggered_by_user_id')->nullable()->constrained('users');
            $table->string('run_type')->default('steward_brief');
            $table->string('status')->default('pending')->index();
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->string('prompt_version')->nullable();
            // Exactly what was placed in front of the model, recorded before
            // the call so a failed run is still auditable.
            $table->jsonb('retrieval_manifest_json')->nullable();
            $table->jsonb('output_json')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // One row per resource the agent actually retrieved (spec §16).
        Schema::create('agent_resource_accesses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->foreignUlid('resource_id')->nullable()->constrained('resources');
            $table->ulid('evidence_version_id')->nullable();
            $table->string('access_type')->default('read');
            $table->boolean('permitted')->default(true);
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_resource_accesses');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_instances');
        Schema::dropIfExists('agent_blueprints');
    }
};
