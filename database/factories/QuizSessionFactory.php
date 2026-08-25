<?php

namespace Database\Factories;

use App\Models\QuizSession;
use App\Models\StudySet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizSession>
 */
class QuizSessionFactory extends Factory
{
    /**
     * An issued but unplayed session: no questions, nothing answered,
     * completed_at null. Tests that need a finished one pass completed_at.
     *
     * 'multiple_choice' is the column default from the migration. If
     * QuizSession declares mode constants, use those here instead of the
     * literal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'study_set_id' => StudySet::factory(),
            'mode' => 'multiple_choice',
            'questions_total' => 0,
            'questions_answered' => 0,
            'correct_count' => 0,
            'points_awarded' => 0,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
