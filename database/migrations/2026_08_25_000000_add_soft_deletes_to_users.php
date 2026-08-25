<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Soft deletes on users — for the blast radius as much as for the tombstone.
 |
 | Every user-facing FK in this schema is cascadeOnDelete: point_transactions,
 | daily_activities, quiz_sessions, study_sets, set_user. A hard $user->delete()
 | therefore does not fail loudly; it succeeds, and takes the ledger, every set
 | the user owns, every card in those sets, and every *other* user's set_user
 | row pointing at them. ProfileTest passes today only because its factory user
 | owns none of that.
 |
 | With deleted_at present that same call writes one column and touches nothing
 | else, and SoftDeletingScope drops the row out of User::scopeLeaderboard(),
 | out of StudySet::scopeSearch()'s orWhereHas('user'), and out of the auth
 | provider's credential lookup — none of which have to know about deletion.
 |
 | No index: nothing queries users *by* deleted_at. The scope's
 | `deleted_at is null` is a filter applied to rows already located by
 | users_total_points_index, users_level_xp_index, or a unique key.
 |
 | Note what this does NOT cover. sessions.user_id is nullable()->index() with
 | no constrained(), so there is no FK there at all: session rows outlive any
 | form of user deletion and have to be removed by hand, or a cookie on the
 | user's phone stays authenticated as the tombstone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
