<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The security fix worth talking about in an interview.
 |
 | In the old site the browser decided whether an answer was right and then
 | POSTed to pointsProcess.php to claim +10. Nothing tied a claim to a
 | question, so a curl loop could farm the leaderboard indefinitely.
 |
 | Here every question is *issued* by the server before it is shown, and the
 | correct answer never leaves the database. A submission is accepted only
 | when the row belongs to the authenticated user's session, has
 | answered_at IS NULL, and has not expired. Answering is therefore
 | idempotent and replay is structurally impossible rather than merely
 | checked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_set_id')->constrained()->cascadeOnDelete();

            // multiple_choice | written | true_false
            $table->string('mode', 24)->default('multiple_choice');

            $table->unsignedSmallInteger('questions_total')->default(0);
            $table->unsignedSmallInteger('questions_answered')->default(0);
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedInteger('points_awarded')->default(0);

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
            $table->index('study_set_id');
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position');

            // Distractors and prompt actually rendered, so a session can be
            // replayed for review and audited after the fact.
            //
            // MariaDB note: json() here compiles to LONGTEXT with a validity
            // CHECK, not a native type as on MySQL 8. That is fine because we
            // only ever read this column whole. Do NOT add queries that index
            // into it (->'$.foo') without revisiting this decision.
            $table->json('presented_options')->nullable();

            // Never serialised to the client before submission.
            $table->text('expected_answer');

            $table->text('submitted_answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->unsignedSmallInteger('points_awarded')->default(0);

            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            $table->unique(['quiz_session_id', 'position']);
            $table->index(['quiz_session_id', 'answered_at']);
            $table->index('card_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quiz_sessions');
    }
};
