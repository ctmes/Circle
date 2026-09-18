<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shape of a plan, with the Circle removed (spec §21.4).
     *
     * A Circle is ephemeral by design and must stay that way — making it
     * forkable would fight §1. But the *shape* of one is worth keeping: the
     * structure of a bid review, a mobilisation, a rail access package. Today
     * that shape is redrawn by hand every time, or copied by whoever remembers
     * the last one.
     *
     * A package is a goal tree with dates replaced by offsets, so instantiating
     * it against a start date produces a schedule rather than a set of dates
     * from someone else's project.
     *
     * Deliberately not carried across: evidence, claims, decisions, threads,
     * parties, and every id. A package is a form, not a copy of somebody's
     * project — a template that dragged the last client's structure into the
     * next one is a confidentiality incident, not a feature. capture() reads
     * titles, descriptions, acceptance conditions and shape; nothing else.
     */
    public function up(): void
    {
        Schema::create('work_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();

            $table->string('name');
            $table->text('summary')->nullable();

            /**
             * Where the shape came from. Kept so a package can say it was
             * captured from real work rather than invented, and dropped to
             * null if that Circle is deleted — the package outlives it, which
             * is the entire point.
             */
            $table->foreignUlid('source_circle_id')->nullable()
                ->constrained('circles')->nullOnDelete();

            /**
             * Lineage. A fork records what it came from, so "where did this
             * shape come from" is answerable three copies later — the one
             * thing a template library needs and folder-of-documents never has.
             *
             * Declared bare and constrained below: Postgres will not accept a
             * self-referencing foreign key inside the CREATE TABLE that
             * establishes the key it points at.
             */
            $table->ulid('forked_from_id')->nullable();

            $table->unsignedInteger('version')->default(1);

            // party | circle | network | public
            $table->string('visibility')->default('party')->index();
            $table->timestamp('published_at')->nullable();

            $table->foreignUlid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['organisation_id', 'visibility']);
        });

        Schema::table('work_packages', function (Blueprint $table) {
            $table->foreign('forked_from_id')->references('id')->on('work_packages')->nullOnDelete();
        });

        Schema::create('work_package_nodes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_package_id')->constrained('work_packages')->cascadeOnDelete();

            /** Self-referencing, the same shape as `goals` and capped the same way. */
            $table->ulid('parent_node_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->text('acceptance_condition')->nullable();

            /**
             * Days from the anchor date, rather than dates.
             *
             * The reason a package is a form and not a copy. A captured tree
             * whose dates came along would instantiate into next year's
             * project with last year's deadlines, and somebody would fix them
             * one at a time until they stopped using templates.
             *
             * Signed, because a node can legitimately start before the anchor
             * — mobilisation work that has to happen ahead of a nominal start.
             */
            $table->integer('starts_offset_days')->nullable();
            $table->integer('due_offset_days')->nullable();

            /**
             * Which kind of party this node is *for*, not which party.
             *
             * A role rather than an organisation: carrying the last client's
             * identity into a template is the confidentiality incident this
             * table is written to avoid.
             */
            $table->string('default_party_role')->nullable();

            /** Whether instantiating this node should also post it as open work. */
            $table->boolean('post_as_opening')->default(false);

            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['work_package_id', 'position']);
        });

        Schema::table('work_package_nodes', function (Blueprint $table) {
            $table->foreign('parent_node_id')->references('id')->on('work_package_nodes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_package_nodes');
        Schema::dropIfExists('work_packages');
    }
};
