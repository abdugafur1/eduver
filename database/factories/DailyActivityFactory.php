<?php

namespace Database\Factories;

use App\Models\DailyActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyActivity>
 */
class DailyActivityFactory extends Factory
{
    /**
     * activity_date is the user's *local* date, not the server's — that is the
     * whole point of the column. The factory can't derive it without the user
     * (DailyActivity::dateFor($user) needs the model, and here user_id may
     * still be an unresolved factory), so it defaults to the server date and
     * tests spanning timezones must pass it explicitly.
     *
     * There is a unique key on (user_id, activity_date), so two rows for the
     * same user need distinct dates.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'activity_date' => now()->toDateString(),
            'cards_studied' => 0,
            'quizzes_completed' => 0,
            'points_earned' => 0,
            'counted_for_streak' => false,
        ];
    }
}
