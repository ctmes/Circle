<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a run actually cost, rather than the part of it that was cheapest.
 *
 * `input_tokens` is the *uncached* input only. A run that reads a warm prompt
 * cache reports a small number there and says nothing about the tokens it was
 * billed for at the cache rate — so agent metering, which reads these, was
 * quietly under-counting every cached run.
 *
 * Nullable rather than defaulted to zero, because "this provider did not tell
 * us" and "nothing was cached" are different facts and only one of them is
 * evidence about the cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->unsignedInteger('cache_read_input_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('cache_creation_input_tokens')->nullable()->after('cache_read_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['cache_read_input_tokens', 'cache_creation_input_tokens']);
        });
    }
};
