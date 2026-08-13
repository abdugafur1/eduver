<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The legacy per-set card tables tracked numTested / numWrong across every
 | user who ever studied that card. That is real behavioural data and the one
 | thing in the old schema worth keeping that the new one had no slot for.
 |
 | Aggregate, not per-user: it says "this card is hard", not "this user finds
 | it hard". Per-user mastery is a separate concern and belongs in its own
 | table if it ever gets built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->unsignedInteger('times_tested')->default(0)->after('hint');
            $table->unsignedInteger('times_wrong')->default(0)->after('times_tested');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['times_tested', 'times_wrong']);
        });
    }
};
