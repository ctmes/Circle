<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('circle_id')->constrained('circles')->cascadeOnDelete();
            $table->foreignUlid('requested_by_user_id')->constrained('users');
            $table->string('status')->default('pending')->index();
            $table->string('storage_key')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->jsonb('manifest_json')->nullable();
            $table->boolean('audit_chain_valid')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
