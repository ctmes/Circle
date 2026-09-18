<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three gaps the schema implied but never let anyone reach.
     *
     * `role_grants` has been read by AccessGate since the first migration and
     * written by nothing, so the per-user override the gate documents was
     * unreachable through the product. A grant now records why it was made,
     * because "who can author an agent here" is the kind of question that gets
     * asked six months later by someone who was not in the room.
     *
     * `resource_access_overrides` could name a user or nobody. It could not name
     * a party — so "the subcontractor's people cannot see the commercials" had
     * no expression, and the answer was to not put the file in the Circle. A
     * party-scoped override is the smallest thing that fixes that without
     * inventing a second permission system.
     */
    public function up(): void
    {
        Schema::table('role_grants', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('allow');
        });

        Schema::table('resource_access_overrides', function (Blueprint $table) {
            $table->foreignUlid('circle_party_id')->nullable()->after('user_id')
                ->constrained('circle_parties')->cascadeOnDelete();
            $table->text('reason')->nullable()->after('allow');
        });

        // The old constraint could not tell a party override apart from a
        // resource-wide one, since both leave user_id null.
        Schema::table('resource_access_overrides', function (Blueprint $table) {
            $table->dropUnique('rao_unique');
            $table->unique(['resource_id', 'user_id', 'circle_party_id', 'permission'], 'rao_unique');
        });
    }

    public function down(): void
    {
        Schema::table('resource_access_overrides', function (Blueprint $table) {
            $table->dropUnique('rao_unique');
            $table->dropConstrainedForeignId('circle_party_id');
            $table->dropColumn('reason');
            $table->unique(['resource_id', 'user_id', 'permission'], 'rao_unique');
        });

        Schema::table('role_grants', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
