<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Append-only ledger plus a daily rollup.
 |
 | The old site stored only a running total, so a disputed balance could not
 | be reconstructed and cheating left no trace. Here every movement of points
 | or credits is a row that names its cause, and users.total_points is a cache
 | of SUM(amount) written in the same transaction.
 |
 | daily_activities exists because the old streak logic sat in a
 | do { updateStreak() } while (...) loop in pointsProcess.php that spins
 | forever if the UPDATE fails. A streak is not a counter to be incremented;
 | it is a property of which days have rows here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // points | credits | xp
            $table->string('currency', 16)->default('points');

            // Signed: wagers and purchases are negative.
            $table->integer('amount');

            // quiz | streak_bonus | quest | wager | contest | admin_adjustment
            // | legacy_import
            $table->string('reason', 32);

            // Polymorphic pointer at whatever caused it (a quiz_session, a
            // quest completion, and so on). Nullable for adjustments and the
            // legacy import, which have no originating row.
            $table->nullableMorphs('source');

            // Balance after this row, for O(1) statement rendering and as a
            // consistency check against the cached counter on users.
            $table->bigInteger('balance_after');

            $table->timestamps();

            $table->index(['user_id', 'currency', 'created_at']);
            $table->index(['reason', 'created_at']);
        });

        Schema::create('daily_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The user's local date, derived from users.timezone at write
            // time. This is what makes streaks correct across timezones.
            $table->date('activity_date');

            $table->unsignedSmallInteger('cards_studied')->default(0);
            $table->unsignedSmallInteger('quizzes_completed')->default(0);
            $table->unsignedInteger('points_earned')->default(0);

            // Whether this day counted toward the streak. A day can have
            // activity without meeting the threshold.
            $table->boolean('counted_for_streak')->default(false);

            $table->timestamps();

            // One row per user per local day. Also the natural upsert key.
            $table->unique(['user_id', 'activity_date']);
            $table->index(['activity_date', 'points_earned']); // daily boards
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activities');
        Schema::dropIfExists('point_transactions');
    }
};
