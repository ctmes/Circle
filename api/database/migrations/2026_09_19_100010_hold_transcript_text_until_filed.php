<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a transcript waits between arriving and being filed (spec §24).
     *
     * A transcript is received by a company before it is routed to a Circle,
     * and routing is a model call that belongs in a queued job, not in a
     * webhook the sender is holding open. The text has to live somewhere in
     * between. It lives here, and only until it is filed: once the transcript
     * is evidence in a Circle — stored, hashed and behind the gate — this is
     * cleared, and a transcript routed to nothing is not kept at all.
     *
     * Never returned by the API. This is a buffer, not a copy.
     */
    public function up(): void
    {
        Schema::table('transcript_imports', function (Blueprint $table) {
            $table->longText('content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transcript_imports', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }
};
