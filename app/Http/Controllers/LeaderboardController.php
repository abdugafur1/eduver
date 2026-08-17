<?php

namespace App\Http\Controllers;

use App\Models\DailyActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Two boards over the same points.
 *
 * Daily is the default. All-time is a cumulative total that only ever grows, so
 * whoever leads it in three months leads it in a year; daily resets and is
 * winnable by someone who joined this morning. Both are offered because they
 * answer different questions, but the one on the landing tab should be the one
 * a new account can act on.
 *
 * Neither board filters on must_reset_password, and that is deliberate. The
 * legacy accounts imported with total_points = 0 and cannot log in, so they
 * cannot earn — the score filters below exclude them as a side effect of being
 * about scores. If an account is ever restored it appears the moment it earns,
 * with no second rule to remember.
 */
class LeaderboardController extends Controller
{
    /** Query value => tab label. */
    public const BOARDS = [
        'daily' => 'Today',
        'all_time' => 'All time',
    ];

    private const SIZE = 25;

    public function index(Request $request): View
    {
        $board = (string) $request->query('board', 'daily');

        if (! array_key_exists($board, self::BOARDS)) {
            $board = 'daily';
        }

        $viewer = $request->user();

        // The date this board covers, in the viewer's own timezone.
        //
        // activity_date is a DATE written in each user's local zone, so there is
        // no single global "today" to query. Anchoring on the viewer means
        // everyone competes on their own calendar day — which is the same rule
        // QuizRewards uses when it decides which day a session landed on. The
        // alternative, pinning to the app timezone, would close the board at
        // 09:00 for the users the dateFor() comment says the legacy userbase is
        // mostly made of.
        $date = $viewer ? DailyActivity::dateFor($viewer) : now()->toDateString();

        return view('leaderboard.index', [
            'board' => $board,
            'date' => $date,
            'rows' => $board === 'daily'
                ? $this->dailyRows($date, $viewer)
                : $this->allTimeRows($viewer),
            'viewerRank' => $board === 'daily'
                ? $this->dailyRank($date, $viewer)
                : $this->allTimeRank($viewer),
        ]);
    }

    /**
     * Rides daily_activities_activity_date_points_earned_index.
     *
     * points_earned > 0 drops the rows a user gets for opening a set without
     * finishing a quiz — being on the board should mean you scored, not that
     * you showed up.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function dailyRows(string $date, ?User $viewer): Collection
    {
        return DailyActivity::query()
            ->onDate($date)
            ->where('points_earned', '>', 0)
            ->join('users', 'users.id', '=', 'daily_activities.user_id')
            ->orderByDesc('daily_activities.points_earned')
            // Ties are broken by id, so the order is stable between requests
            // rather than whatever the storage engine hands back.
            ->orderBy('daily_activities.user_id')
            ->limit(self::SIZE)
            ->get([
                'daily_activities.user_id',
                'daily_activities.points_earned',
                'daily_activities.quizzes_completed',
                'users.username',
                'users.name',
            ])
            ->values()
            ->map(fn ($row, $i) => [
                'rank' => $i + 1,
                'username' => $row->username,
                'name' => $row->name,
                'score' => (int) $row->points_earned,
                'meta' => $row->quizzes_completed.' '.\Illuminate\Support\Str::plural('quiz', $row->quizzes_completed),
                'is_viewer' => $viewer && (int) $row->user_id === $viewer->getKey(),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function allTimeRows(?User $viewer): Collection
    {
        return User::query()
            ->where('total_points', '>', 0)
            ->leaderboard()
            ->orderBy('id')
            ->limit(self::SIZE)
            ->get(['id', 'username', 'name', 'total_points', 'current_streak'])
            ->values()
            ->map(fn (User $user, $i) => [
                'rank' => $i + 1,
                'username' => $user->username,
                'name' => $user->name,
                'score' => $user->balance(),
                'meta' => $user->current_streak > 0
                    ? $user->current_streak.'-day streak'
                    : null,
                'is_viewer' => $viewer && $user->getKey() === $viewer->getKey(),
            ]);
    }

    /**
     * Where the viewer stands when they're below the cut.
     *
     * Counting the rows strictly above them gives standard competition ranking —
     * two people tied for 1st are both 1st and the next is 3rd. Returns null
     * when they haven't scored, because "unranked" is the honest answer rather
     * than last place.
     *
     * @return array{rank:int, score:int}|null
     */
    private function dailyRank(string $date, ?User $viewer): ?array
    {
        if (! $viewer) {
            return null;
        }

        $mine = DailyActivity::query()
            ->where('user_id', $viewer->getKey())
            ->onDate($date)
            ->value('points_earned');

        if (! $mine) {
            return null;
        }

        $above = DailyActivity::query()
            ->onDate($date)
            ->where('points_earned', '>', $mine)
            ->count();

        return ['rank' => $above + 1, 'score' => (int) $mine];
    }

    /**
     * @return array{rank:int, score:int}|null
     */
    private function allTimeRank(?User $viewer): ?array
    {
        if (! $viewer || $viewer->balance() < 1) {
            return null;
        }

        $above = User::query()
            ->where('total_points', '>', $viewer->balance())
            ->count();

        return ['rank' => $above + 1, 'score' => $viewer->balance()];
    }
}
