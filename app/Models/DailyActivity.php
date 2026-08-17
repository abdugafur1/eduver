<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user per local day, enforced by
 * daily_activities_user_id_activity_date_unique.
 *
 * activity_date is a DATE, so which day a study session lands on depends on the
 * user's timezone — never App::timezone(). See dateFor().
 */
class DailyActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'activity_date',
        'cards_studied',
        'quizzes_completed',
        'points_earned',
        'counted_for_streak',
    ];

    protected $attributes = [
        'cards_studied' => 0,
        'quizzes_completed' => 0,
        'points_earned' => 0,
        'counted_for_streak' => false,
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'cards_studied' => 'integer',
            'quizzes_completed' => 'integer',
            'points_earned' => 'integer',
            'counted_for_streak' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The calendar date a moment falls on *for this user*. Getting this wrong
     * is how streaks break for anyone east of UTC — which, given the legacy
     * userbase, is not a hypothetical.
     */
    public static function dateFor(User $user, mixed $moment = null): string
    {
        return ($moment ? \Illuminate\Support\Carbon::parse($moment) : now())
            ->setTimezone($user->timezone ?: 'UTC')
            ->toDateString();
    }

    public function scopeOnDate(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('activity_date', $date);
    }

    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('activity_date', [$from, $to]);
    }

    public function scopeCountedForStreak(Builder $query): Builder
    {
        return $query->where('counted_for_streak', true);
    }

    /** Rides daily_activities_activity_date_points_earned_index. */
    public function scopeLeaderboard(Builder $query, mixed $date): Builder
    {
        return $query->onDate($date)->orderByDesc('points_earned');
    }

    /**
     * Idempotent increment against the unique key. Safe to call more than once
     * for the same event only if you're happy adding the deltas again.
     *
     * The lookup is whereDate(), not firstOrCreate(). firstOrCreate() would use
     * activity_date verbatim as an equality match, and the 'date' cast
     * serialises through the model's datetime format on write — so on a driver
     * with no native DATE type the stored value carries 00:00:00, the match on
     * 'Y-m-d' misses, and the second call of the day inserts straight into
     * daily_activities_user_id_activity_date_unique. whereDate() casts the
     * column in SQL instead, which matches on every driver.
     */
    public static function accrue(User $user, array $deltas, mixed $moment = null): self
    {
        $date = static::dateFor($user, $moment);

        $row = $user->dailyActivities()->whereDate('activity_date', $date)->first()
            ?? $user->dailyActivities()->create(['activity_date' => $date]);

        foreach ($deltas as $column => $delta) {
            $row->increment($column, $delta);
        }

        return $row->refresh();
    }
}
