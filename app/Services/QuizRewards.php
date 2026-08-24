<?php

namespace App\Services;

use App\Models\DailyActivity;
use App\Models\PointTransaction;
use App\Models\QuizQuestion;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens when a quiz session finishes: points, the ledger,
 * the daily rollup, and the streak.
 *
 * Kept out of the controller because settle() is the only place allowed to
 * write point_transactions for a quiz, and because it has to be safe to call
 * more than once — play() reaches it both when the last question is answered
 * and when a stale session is opened after expiry.
 */
class QuizRewards
{
    public const POINTS_PER_CORRECT = 10;

    /**
     * A day counts toward the streak once this many quizzes are completed.
     * Deliberately a threshold rather than "any activity": opening a set and
     * bouncing shouldn't hold a streak together.
     */
    public const STREAK_QUIZ_THRESHOLD = 1;

    /**
     * How far back the streak walk looks. A streak is derived from which days
     * have rows, not incremented — see the create_progress_tables migration —
     * so this bounds the derivation rather than the streak itself.
     */
    private const STREAK_LOOKBACK_DAYS = 400;

    /**
     * Stamp completed_at and pay out, exactly once.
     *
     * The conditional UPDATE on completed_at is the same idempotency device
     * answer() uses on answered_at: if it claims zero rows, another request
     * already settled this session and we must not write a second ledger row.
     */
    public function settle(QuizSession $session): QuizSession
    {
        return DB::transaction(function () use ($session) {
            $claimed = QuizSession::query()
                ->whereKey($session->getKey())
                ->whereNull('completed_at')
                ->update(['completed_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                return $session->refresh();
            }

            $session->refresh();

            // Locked for the rest of the transaction. balance_after is a
            // running snapshot, so two sessions settling at once would
            // otherwise read the same starting balance and write the same
            // balance_after — breaking the one consistency check the ledger
            // has against users.total_points.
            $user = User::query()->whereKey($session->user_id)->lockForUpdate()->first();

            if (! $user) {
                return $session;
            }

            $answered = $session->questions()->answered()->count();
            $points = $this->award($session, $user);

            $this->recordActivity($user, $answered, $points);

            return $session->refresh();
        });
    }

    /**
     * Pay for correct answers, up to the day's remaining allowance for this set.
     *
     * The allowance is the set's card count times the per-answer rate: a
     * 15-card set is worth 150 points a day, a 200-card set 2,000. It scales
     * with the set, so nobody is penalised for working through something large,
     * and re-running a small set stops paying once you've earned it out.
     *
     * Capping earnings rather than attempts matters. An earlier version spent
     * one slot per question answered, so a ten-question run burned two thirds
     * of a fifteen-card set's daily quota whether you scored ten or zero —
     * charging people for practice instead of for what they took.
     */
    private function award(QuizSession $session, User $user): int
    {
        $remaining = $this->remainingAllowance($session, $user);

        if ($remaining < self::POINTS_PER_CORRECT) {
            return 0;
        }

        // How many correct answers the remaining allowance can still pay for.
        $payable = intdiv($remaining, self::POINTS_PER_CORRECT);

        // Allowance is spent in question order. Anything past it is still
        // graded and still counts toward the score — it just doesn't earn.
        $winners = $session->questions()
            ->answered()
            ->correct()
            ->ordered()
            ->limit($payable)
            ->get(['id']);

        $points = $winners->count() * self::POINTS_PER_CORRECT;

        if ($points < 1) {
            return 0;
        }

        QuizQuestion::whereIn('id', $winners->pluck('id'))
            ->update(['points_awarded' => self::POINTS_PER_CORRECT]);

        $session->forceFill(['points_awarded' => $points])->save();

        // Ledger owns the row-plus-column pair; quests mint through the same
        // method. We're inside settle()'s transaction with $user locked, which
        // is that method's calling contract.
        app(Ledger::class)->credit(
            $user,
            $points,
            'quiz',
            PointTransaction::CURRENCY_POINTS,
            $session,
        );

        return $points;
    }

    /**
     * Points left to earn today on this set.
     *
     * Only completed sessions count against it — quiz_sessions.points_awarded
     * is only written at settlement, so an abandoned run has never cost
     * anything, which is the right answer for someone who got interrupted.
     */
    private function remainingAllowance(QuizSession $session, User $user): int
    {
        [$from, $to] = $this->localDayWindow($user);

        $earned = (int) QuizSession::query()
            ->where('user_id', $user->getKey())
            ->where('study_set_id', $session->study_set_id)
            ->completed()
            ->whereBetween('completed_at', [$from, $to])
            ->whereKeyNot($session->getKey())
            ->sum('points_awarded');

        $cap = (int) ($session->studySet?->cards_count ?? 0) * self::POINTS_PER_CORRECT;

        return max(0, $cap - $earned);
    }

    /**
     * The UTC instants bounding this user's local calendar day. activity_date
     * is a DATE in the user's own timezone, and completed_at is stored in the
     * app's — comparing them without this is how streaks break east of UTC.
     */
    private function localDayWindow(User $user): array
    {
        $tz = $user->timezone ?: 'UTC';
        $local = Carbon::parse(DailyActivity::dateFor($user), $tz);

        return [
            $local->copy()->startOfDay()->utc(),
            $local->copy()->endOfDay()->utc(),
        ];
    }

    /** Roll the day up, then re-derive the streak from the days that exist. */
    private function recordActivity(User $user, int $answered, int $points): void
    {
        $today = DailyActivity::accrue($user, [
            'cards_studied' => $answered,
            'quizzes_completed' => 1,
            'points_earned' => $points,
        ]);

        if (! $today->counted_for_streak && $today->quizzes_completed >= self::STREAK_QUIZ_THRESHOLD) {
            $today->forceFill(['counted_for_streak' => true])->save();
        }

        $this->refreshStreak($user);
    }

    /**
     * A streak is a property of which days have qualifying rows, not a counter
     * to be incremented. Deriving it means a backfilled or corrected day is
     * reflected immediately, and there's no state to drift.
     *
     * Counting from yesterday as well as today is deliberate: mid-day, before
     * you've quizzed, your streak is still whatever it was last night.
     */
    private function refreshStreak(User $user): void
    {
        $tz = $user->timezone ?: 'UTC';
        $today = Carbon::parse(DailyActivity::dateFor($user), $tz);

        $days = $user->dailyActivities()
            ->countedForStreak()
            ->where('activity_date', '>=', $today->copy()->subDays(self::STREAK_LOOKBACK_DAYS)->toDateString())
            ->orderByDesc('activity_date')
            ->pluck('activity_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $have = array_flip($days);

        $cursor = isset($have[$today->toDateString()])
            ? $today->copy()
            : $today->copy()->subDay();

        $streak = 0;

        while (isset($have[$cursor->toDateString()])) {
            $streak++;
            $cursor->subDay();
        }

        $user->forceFill([
            'current_streak' => $streak,
            'longest_streak' => max($streak, (int) $user->longest_streak),
            'last_active_on' => $today->toDateString(),
        ])->save();
    }
}
