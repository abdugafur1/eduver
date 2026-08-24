<?php

namespace App\Services;

use App\Models\DailyActivity;
use App\Models\PointTransaction;
use App\Models\QuestClaim;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Daily quests: what they are, how far along you are, and paying one out.
 *
 * Definitions live here rather than in a table, the same call as
 * quiz_sessions.mode being a plain string — retuning a threshold or adding a
 * tier is an edit to this array, not a migration. What was actually paid is
 * recorded on quest_claims, so changing a reward here never rewrites history.
 *
 * `metric` is a column on daily_activities. That's the whole extension point:
 * a quest counting quizzes or cards instead of points is a new entry with a
 * different metric and no new code, because the day's row already carries all
 * three counters. Anything that *isn't* a daily_activities column — a streak
 * length, a set created — needs a case in progressFor() as well.
 *
 * Credits are the reward currency and nothing else mints them, which is why
 * partials/wealth hides that pill behind config('eduver.show_credits'). Flip
 * that to true with this feature or the credits people earn stay invisible.
 */
class Quests
{
    public const REWARD_CURRENCY = PointTransaction::CURRENCY_CREDITS;

    /**
     * Keyed by quest_key, which is stored on quest_claims and used verbatim as
     * point_transactions.reason — keep them inside varchar(32).
     *
     * The four tiers are the legacy set: cumulative points earned today,
     * paying 10/25/50/100 credits. They're cumulative rather than exclusive,
     * so passing 1000 completes all four and each is claimed separately.
     */
    public const QUESTS = [
        'quest_points_100' => [
            'metric' => 'points_earned',
            'threshold' => 100,
            'reward' => 10,
            'label' => 'Gain 100 points',
        ],
        'quest_points_250' => [
            'metric' => 'points_earned',
            'threshold' => 250,
            'reward' => 25,
            'label' => 'Gain 250 points',
        ],
        'quest_points_500' => [
            'metric' => 'points_earned',
            'threshold' => 500,
            'reward' => 50,
            'label' => 'Gain 500 points',
        ],
        'quest_points_1000' => [
            'metric' => 'points_earned',
            'threshold' => 1000,
            'reward' => 100,
            'label' => 'Gain 1000 points',
        ],
    ];

    /**
     * Today's quests for this user: progress, target, reward, and whether it's
     * been claimed.
     *
     * Two queries regardless of how many quests exist — the day's row and the
     * day's claims — because every metric is a column on that one row.
     *
     * @return array<int, array{key:string, label:string, progress:int, threshold:int, reward:int, claimed:bool, claimable:bool}>
     */
    public function progressFor(User $user): array
    {
        $date = DailyActivity::dateFor($user);

        $today = $user->dailyActivities()->whereDate('activity_date', $date)->first();

        $claimed = QuestClaim::query()
            ->where('user_id', $user->getKey())
            ->onDate($date)
            ->pluck('quest_key')
            ->flip();

        $quests = [];

        foreach (self::QUESTS as $key => $quest) {
            // No row yet means no activity today, not an error — everything
            // reads 0 until the first quiz settles.
            $progress = (int) ($today?->{$quest['metric']} ?? 0);
            $isClaimed = $claimed->has($key);

            $quests[] = [
                'key' => $key,
                'label' => $quest['label'],
                'progress' => min($progress, $quest['threshold']),
                'threshold' => $quest['threshold'],
                'reward' => $quest['reward'],
                'claimed' => $isClaimed,
                'claimable' => ! $isClaimed && $progress >= $quest['threshold'],
            ];
        }

        return $quests;
    }

    /**
     * Pay out one quest, at most once per user per local day.
     *
     * Progress is recomputed here from daily_activities rather than trusted
     * from the request — the old site's pointsProcess.php took the browser's
     * word for what had been earned, and this is the same class of endpoint.
     *
     * The claim row is inserted *before* the mint, so the unique index decides
     * who wins: a duplicate insert throws, and nothing is paid. Doing it the
     * other way round leaves a window where two requests both mint and only
     * the second fails.
     *
     * Returns false when there's nothing to pay — unknown key, not yet earned,
     * or already claimed. All three are the same non-event to the caller.
     */
    public function claim(User $user, string $key): bool
    {
        $quest = self::QUESTS[$key] ?? null;

        if ($quest === null) {
            return false;
        }

        $date = DailyActivity::dateFor($user);

        return DB::transaction(function () use ($user, $key, $quest, $date) {
            // Locked for the rest of the transaction, per Ledger's contract:
            // balance_after is a running snapshot, and claiming two tiers at
            // once would otherwise write the same one twice.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return false;
            }

            $progress = (int) ($locked->dailyActivities()
                ->whereDate('activity_date', $date)
                ->value($quest['metric']) ?? 0);

            if ($progress < $quest['threshold']) {
                return false;
            }

            try {
                $claim = QuestClaim::create([
                    'user_id' => $locked->getKey(),
                    'quest_key' => $key,
                    'activity_date' => $date,
                    'reward' => $quest['reward'],
                    'currency' => self::REWARD_CURRENCY,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Already claimed today. Not an error worth surfacing — the
                // page will render it as claimed on the redirect.
                return false;
            }

            app(Ledger::class)->credit(
                $locked,
                $quest['reward'],
                $key,
                self::REWARD_CURRENCY,
                $claim,
            );

            return true;
        });
    }
}
