<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-text search over extracted evidence text (spec §7, §12).
 *
 * The text is already sitting in derived_artifacts.content_json — pages from
 * ExtractDocumentText, sheets from ExtractSpreadsheet, segments from
 * TranscribeMedia. This adds the index that makes it findable, as a generated
 * column so it can never drift out of step with the artifact it summarises.
 *
 * The `left(...)` guard matters: to_tsvector refuses inputs whose lexeme
 * output exceeds 1 MB, and a generated column that throws would fail the
 * *extraction insert itself*. Truncating the indexed copy keeps a pathological
 * document searchable up to that point instead of unprocessable entirely.
 */
return new class extends Migration
{
    private const INDEXED_CHARS = 900000;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            "ALTER TABLE derived_artifacts
                ADD COLUMN search_vector tsvector
                GENERATED ALWAYS AS (
                    to_tsvector('english', left(coalesce(content_json->>'text', ''), %d))
                ) STORED",
            self::INDEXED_CHARS,
        ));

        DB::statement('CREATE INDEX derived_artifacts_search_idx ON derived_artifacts USING gin (search_vector)');

        // Search is always scoped to one Circle first; nothing is searchable
        // across Circle boundaries, so the scope belongs in the index.
        DB::statement('CREATE INDEX derived_artifacts_circle_type_idx ON derived_artifacts (circle_id, artifact_type)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS derived_artifacts_circle_type_idx');
        DB::statement('DROP INDEX IF EXISTS derived_artifacts_search_idx');

        Schema::table('derived_artifacts', function ($table) {
            $table->dropColumn('search_vector');
        });
    }
};
