<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Monotonic per-Circle position. The hash chain is verified in this
            // order, so it must not depend on timestamp collisions.
            $table->bigIncrements('sequence');
            $table->foreignUlid('circle_id')->nullable()->constrained('circles')->nullOnDelete();
            $table->string('actor_type');
            // Deliberately varchar rather than ulid/char(26). The audit log
            // references heterogeneous subjects, and CHAR pads short values to
            // the declared width — so a non-ULID identifier would hash as one
            // value on write and read back as another, silently breaking the
            // chain. An audit record must return exactly what it was given.
            $table->string('actor_id', 64)->nullable();
            $table->string('event_type')->index();
            $table->string('resource_type')->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->string('resource_version')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64);

            $table->index(['circle_id', 'sequence']);
            $table->index(['circle_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
