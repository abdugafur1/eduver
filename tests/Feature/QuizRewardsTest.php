<?php

namespace Tests\Feature;

use App\Models\DailyActivity;
use App\Models\PointTransaction;
use App\Models\QuizSession;
use App\Models\User;
use App\Services\QuizRewards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesStudySets;
use Tests\Concerns\PlaysQuizzes;
use Tests\TestCase;

/**
 * Settlement: what a finished session pays, what it writes to the ledger, and
 * what stops it paying twice.
 *
 * The daily allowance has three outcomes — paid in full, paid in part, paid
 * nothing — and all three are here, because a silent zero is indistinguishable
 * from a bug when you're looking at it in the browser.
 */
class QuizRewardsTest extends TestCase
{
    use MakesStudySets, PlaysQuizzes, RefreshDatabase;

    public function test_a_finished_session_pays_ten_per_correct_answer(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $session = $this->playPerfectly($user, $set);

        $this->assertNotNull($session->completed_at);
        $this->assertSame(10, $session->correct_count);
        $this->assertSame(100, $session->points_awarded);
        $this->assertSame(100, $user->refresh()->total_points);
    }

    public function test_settlement_writes_one_ledger_row_that_agrees_with_the_cache(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $session = $this->playPerfectly($user, $set);

        $row = PointTransaction::sole();

        $this->assertSame($user->getKey(), $row->user_id);
        $this->assertSame(PointTransaction::CURRENCY_POINTS, $row->currency);
        $this->assertSame('quiz', $row->reason);
        $this->assertSame(100, $row->amount);
        $this->assertSame($session->getKey(), (int) $row->source_id);

        // balance_after is the running snapshot the ledger is audited against.
        $this->assertSame(100, $row->balance_after);

        // Three independent records of the same number, per balanceAudit():
        // the column, the newest snapshot, and SUM(amount).
        $audit = $user->refresh()->balanceAudit();
        $this->assertTrue($audit['agrees'], 'ledger and cached balance disagree: '.json_encode($audit));
    }

    /**
     * play() reaches complete() both when the last answer lands and when a
     * stale session is reopened, so settling must be claimable exactly once.
     */
    public function test_settling_twice_pays_once(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $session = $this->playPerfectly($user, $set);

        app(QuizRewards::class)->settle($session);
        app(QuizRewards::class)->settle($session->refresh());
        $this->actingAs($user)->get(route('quiz.play', $session));

        $this->assertSame(1, PointTransaction::count());
        $this->assertSame(100, $session->refresh()->points_awarded);
        $this->assertSame(100, $user->refresh()->total_points);
    }

    public function test_only_correct_answers_earn(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();

        foreach ($session->questions()->ordered()->get() as $i => $question) {
            $correct = array_search($question->expected_answer, $question->options(), true);

            // Right on the first three, wrong on the rest.
            $choice = $i < 3 ? $correct : ($correct === 0 ? 1 : 0);

            $this->actingAs($user)->post(route('quiz.answer', $session), [
                'question_id' => $question->getKey(),
                'choice' => $choice,
            ]);
        }

        $this->actingAs($user)->get(route('quiz.play', $session));

        $this->assertSame(3, $session->refresh()->correct_count);
        $this->assertSame(30, $session->points_awarded);
        $this->assertSame(30, $user->refresh()->total_points);
    }

    // ------------------------------------------------------- the daily allowance

    /**
     * A set is worth cards_count * 10 a day. Eight cards means eight questions
     * (the session is capped by the set), 80 points, and an 80-point allowance
     * — so a second perfect run earns nothing.
     */
    public function test_a_small_set_stops_paying_once_its_allowance_is_earned(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(8);

        $first = $this->playPerfectly($user, $set);
        $this->assertSame(80, $first->points_awarded);

        $second = $this->playPerfectly($user, $set);

        $this->assertSame(8, $second->correct_count, 'the second run should still be scored');
        $this->assertSame(0, $second->points_awarded, 'but it should not pay');
        $this->assertSame(80, $user->refresh()->total_points);

        // No zero-value ledger row: nothing moved, so nothing is recorded.
        $this->assertSame(1, PointTransaction::count());
    }

    /**
     * Twelve cards is a 120-point allowance but only ten questions a session,
     * so a perfect first run leaves 20 — two more correct answers, then it
     * stops. This is the case that reads like a bug in the browser, which is
     * why the scorecard explains it.
     */
    public function test_an_allowance_can_be_spent_partway_through_a_run(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(12);

        $first = $this->playPerfectly($user, $set);
        $this->assertSame(100, $first->points_awarded);

        $second = $this->playPerfectly($user, $set);

        $this->assertSame(10, $second->correct_count);
        $this->assertSame(20, $second->points_awarded);
        $this->assertSame(120, $user->refresh()->total_points);

        // Only the answers that were paid for carry a per-question value.
        $this->assertSame(2, $second->questions()->where('points_awarded', '>', 0)->count());
    }

    public function test_wrong_answers_do_not_consume_the_allowance(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(8);

        // A run scoring zero: under an attempt-based cap this would burn the
        // day's quota. Practice you got wrong should cost nothing.
        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $session = QuizSession::sole();

        foreach ($session->questions()->ordered()->get() as $question) {
            $correct = array_search($question->expected_answer, $question->options(), true);

            $this->actingAs($user)->post(route('quiz.answer', $session), [
                'question_id' => $question->getKey(),
                'choice' => $correct === 0 ? 1 : 0,
            ]);
        }

        $this->actingAs($user)->get(route('quiz.play', $session));
        $this->assertSame(0, $session->refresh()->points_awarded);

        $this->assertSame(80, $this->playPerfectly($user, $set)->points_awarded);
    }

    public function test_each_set_has_its_own_allowance(): void
    {
        $user = User::factory()->create();
        $a = $this->makeSet(8);
        $b = $this->makeSet(8);

        $this->assertSame(80, $this->playPerfectly($user, $a)->points_awarded);
        $this->assertSame(80, $this->playPerfectly($user, $b)->points_awarded);
        $this->assertSame(160, $user->refresh()->total_points);
    }

    public function test_an_abandoned_run_costs_nothing(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(8);

        $this->actingAs($user)->get(route('sets.quiz.multiple_choice', $set));
        $abandoned = QuizSession::sole();
        $question = $abandoned->questions()->ordered()->first();

        $this->actingAs($user)->post(route('quiz.answer', $abandoned), [
            'question_id' => $question->getKey(),
            'choice' => array_search($question->expected_answer, $question->options(), true),
        ]);

        // Walked away. Nothing settled, so nothing was spent — being
        // interrupted must not cost someone their allowance.
        $this->assertNull($abandoned->refresh()->completed_at);

        $this->assertSame(80, $this->playPerfectly($user, $set)->points_awarded);
    }

    public function test_yesterdays_earnings_do_not_count_against_today(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(8);

        $yesterday = $this->playPerfectly($user, $set);
        $yesterday->forceFill(['completed_at' => now()->subDay()])->save();

        $this->assertSame(80, $this->playPerfectly($user, $set)->points_awarded);
    }

    // ------------------------------------------------------------------ activity

    public function test_finishing_rolls_up_the_day_and_starts_a_streak(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $this->playPerfectly($user, $set);

        $day = DailyActivity::sole();

        $this->assertSame($user->getKey(), $day->user_id);
        $this->assertSame(1, $day->quizzes_completed);
        $this->assertSame(10, $day->cards_studied);
        $this->assertSame(100, $day->points_earned);
        $this->assertTrue($day->counted_for_streak);

        $this->assertSame(1, $user->refresh()->current_streak);
        $this->assertSame(1, $user->longest_streak);
    }

    public function test_two_sessions_in_a_day_share_one_activity_row(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $this->playPerfectly($user, $set);
        $this->playPerfectly($user, $set);

        // One row per user per local day — the unique key is also the upsert key.
        $day = DailyActivity::sole();
        $this->assertSame(2, $day->quizzes_completed);
        $this->assertSame(20, $day->cards_studied);

        // Still one day, so still a streak of one.
        $this->assertSame(1, $user->refresh()->current_streak);
    }

    /**
     * A streak is a property of which days have rows, not a counter. Writing
     * yesterday's row directly and finishing today must produce two.
     */
    public function test_a_streak_is_derived_from_consecutive_days(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        DailyActivity::create([
            'user_id' => $user->getKey(),
            'activity_date' => now()->setTimezone($user->timezone ?: 'UTC')->subDay()->toDateString(),
            'quizzes_completed' => 1,
            'counted_for_streak' => true,
        ]);

        $this->playPerfectly($user, $set);

        $this->assertSame(2, $user->refresh()->current_streak);
        $this->assertSame(2, $user->longest_streak);
    }

    public function test_a_gap_breaks_the_streak_without_shrinking_the_record(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $today = now()->setTimezone($user->timezone ?: 'UTC');

        foreach ([9, 8, 7] as $daysAgo) {
            DailyActivity::create([
                'user_id' => $user->getKey(),
                'activity_date' => $today->copy()->subDays($daysAgo)->toDateString(),
                'quizzes_completed' => 1,
                'counted_for_streak' => true,
            ]);
        }

        $user->forceFill(['longest_streak' => 3])->save();

        $this->playPerfectly($user, $set);

        $user->refresh();

        $this->assertSame(1, $user->current_streak, 'the old run is not adjacent to today');
        $this->assertSame(3, $user->longest_streak, 'but the record stands');
    }
}
