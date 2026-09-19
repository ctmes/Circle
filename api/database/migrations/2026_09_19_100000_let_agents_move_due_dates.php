<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A due date moved by a transcript was moved by an agent (spec §24).
     *
     * `changed_by_user_id` was mandatory because until now only a person could
     * move a date. Filling it with whoever owns the connector would put a
     * person's name on a date they never looked at, which is the one thing a
     * schedule change must not do — its whole purpose is that a moved deadline
     * always says who moved it.
     */
    public function up(): void
    {
        Schema::table('goal_schedule_changes', function (Blueprint $table) {
            // ulid() rather than foreignUlid(): change() alters the column and
            // leaves the existing foreign key alone, where foreignUlid() would
            // try to add a second one.
            $table->ulid('changed_by_user_id')->nullable()->change();
            $table->ulid('changed_by_agent_instance_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('goal_schedule_changes', function (Blueprint $table) {
            $table->dropColumn('changed_by_agent_instance_id');
        });
    }
};
