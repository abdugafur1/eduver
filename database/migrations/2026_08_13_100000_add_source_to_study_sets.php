<?php

use App\Models\StudySet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy `notes = 'starterPack'` marker was imported into
 * study_sets.description, so ~1,130 sets carry a flag in a prose column.
 * This moves it to where it belongs and frees description for real text.
 *
 * `received` is declared but not backfilled: the legacy `givenBy` column
 * identified shared sets, and the current schema has no pivot carrying it.
 * Those ~218 rows stay `authored` until that's imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('study_sets', 'source')) {
            Schema::table('study_sets', function (Blueprint $table) {
                $table->string('source', 16)
                    ->default(StudySet::SOURCE_AUTHORED)
                    ->after('description');
            });
        }

        // Raw statements: this must reach soft-deleted rows too.
        DB::statement(
            'UPDATE study_sets SET source = ? WHERE description = ?',
            [StudySet::SOURCE_STARTER, 'starterPack']
        );

        DB::statement(
            'UPDATE study_sets SET description = NULL WHERE description = ?',
            ['starterPack']
        );

        if (! Schema::hasIndex('study_sets', 'study_sets_source_visibility_created_at_index')) {
            Schema::table('study_sets', function (Blueprint $table) {
                // Serves the default browse: non-starter, public, newest first.
                $table->index(['source', 'visibility', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        DB::statement(
            'UPDATE study_sets SET description = ? WHERE source = ?',
            ['starterPack', StudySet::SOURCE_STARTER]
        );

        if (Schema::hasIndex('study_sets', 'study_sets_source_visibility_created_at_index')) {
            Schema::table('study_sets', function (Blueprint $table) {
                $table->dropIndex('study_sets_source_visibility_created_at_index');
            });
        }

        if (Schema::hasColumn('study_sets', 'source')) {
            Schema::table('study_sets', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
