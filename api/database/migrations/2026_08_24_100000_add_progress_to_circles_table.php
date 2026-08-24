<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How far along the mission is, as a whole percent.
     *
     * Deliberately a stated figure rather than a derived one: the Circle owner
     * says where the work stands, and the ring in the header reports exactly
     * that. Counting closed commitments would be a different — and quietly
     * wrong — claim about progress.
     */
    public function up(): void
    {
        Schema::table('circles', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->timestamp('progress_set_at')->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('circles', function (Blueprint $table) {
            $table->dropColumn(['progress', 'progress_set_at']);
        });
    }
};
