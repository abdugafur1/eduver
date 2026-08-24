<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per quest a user has been paid for on a given local day.
 *
 * The unique on (user_id, quest_key, activity_date) is the whole point: it is
 * the accept-once device, the same job whereNull('completed_at') does in
 * QuizRewards::settle() and whereNull('answered_at') does in
 * QuizController::answer(). Quests::claim() inserts here *before* it mints, so
 * two simultaneous claims of the same tier can only get one row between them
 * and the loser pays out nothing.
 *
 * activity_date is a DATE in the user's own timezone, matching
 * daily_activities — a quest earned at 23:59 and claimed at 00:01 settles
 * against the day it was earned, not the day the click landed.
 *
 * reward and currency are recorded as paid rather than looked up later. The
 * definitions live in App\Services\Quests and can be retuned; what someone was
 * actually given shouldn't change retroactively when they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quest_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Matches point_transactions.reason, which carries the same key.
            $table->string('quest_key', 32);
            $table->date('activity_date');

            $table->unsignedInteger('reward');
            $table->string('currency', 16)->default('credits');

            $table->timestamps();

            $table->unique(['user_id', 'quest_key', 'activity_date']);

            // The read the quests page makes: every claim for one user on one
            // day, to mark which tiers are already taken.
            $table->index(['user_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_claims');
    }
};
