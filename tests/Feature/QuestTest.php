<?php

namespace Tests\Feature;

use App\Models\DailyActivity;
use App\Models\PointTransaction;
use App\Models\QuestClaim;
use App\Models\User;
use App\Services\Quests;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesStudySets;
use Tests\Concerns\PlaysQuizzes;
use Tests\TestCase;

/**
 * Quests: what the bars read, what claiming pays, and what stops it paying
 * twice.
 *
 * This is a mint endpoint driven by a form field, so most of the file is the
 * refusals — unknown key, unearned tier, second click, someone else's
 * progress. The one path that pays is checked three ways, because
 * balanceAudit() only agrees if Ledger::credit() wrote the row, moved the
 * right column, and did both inside one transaction.
 *
 * Progress is written two ways on purpose. Most tests use earned(), which is
 * fast and lets a test name any number it likes; a couple settle a real quiz
 * instead, which is the only thing that proves QUESTS' `metric` string names
 * the column QuizRewards actually increments.
 */
class QuestTest extends TestCase
{
    use MakesStudySets, PlaysQuizzes, RefreshDatabase;

    /** The exact wording QuestController flashes on a refusal. */
    private const REFUSED = 'That quest isn’t ready to claim.';

    /**
     * A day's activity row, written directly.
     *
     * forceFill rather than create: this is a fixture reaching past whatever
     * the model considers fillable, and the columns are the ones
     * QuizRewardsTest asserts on, so they're known to exist.
     */
    private function earned(User $user, int $points, ?string $date = null): DailyActivity
    {
        $day = new DailyActivity;

        $day->forceFill([
            'user_id' => $user->getKey(),
            'activity_date' => $date ?? DailyActivity::dateFor($user),
            'quizzes_completed' => 1,
            'cards_studied' => 10,
            'points_earned' => $points,
            'counted_for_streak' => true,
        ])->save();

        return $day;
    }

    /** progressFor(), keyed by quest for readable assertions. */
    private function questsFor(User $user): array
    {
        $quests = [];

        foreach (app(Quests::class)->progressFor($user) as $quest) {
            $quests[$quest['key']] = $quest;
        }

        return $quests;
    }

    private function claim(User $user, string $key): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(route('quests.claim'), ['quest' => $key]);
    }

    // ------------------------------------------------------------------- reading

    public function test_a_user_with_no_activity_sees_every_tier_at_zero(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('quests.index'))->assertOk();

        $quests = $this->questsFor($user);

        // No daily_activities row at all is the normal morning state, not an
        // error — progressFor() must not need one to exist.
        $this->assertCount(count(Quests::QUESTS), $quests);

        foreach ($quests as $quest) {
            $this->assertSame(0, $quest['progress']);
            $this->assertFalse($quest['claimable']);
            $this->assertFalse($quest['claimed']);
        }
    }

    /**
     * The bar is capped so it can't overflow its track, but claimability is
     * decided on the raw number. Capping both would make the top tier
     * unclaimable the moment you passed it.
     */
    public function test_progress_is_capped_for_display_but_not_for_claiming(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 300);

        $quests = $this->questsFor($user);

        $this->assertSame(100, $quests['quest_points_100']['progress']);
        $this->assertSame(250, $quests['quest_points_250']['progress']);
        $this->assertSame(300, $quests['quest_points_500']['progress']);

        // Cumulative, not exclusive: 300 completes the first two at once.
        $this->assertTrue($quests['quest_points_100']['claimable']);
        $this->assertTrue($quests['quest_points_250']['claimable']);
        $this->assertFalse($quests['quest_points_500']['claimable']);
        $this->assertFalse($quests['quest_points_1000']['claimable']);
    }

    public function test_only_todays_points_count(): void
    {
        $user = User::factory()->create();

        $yesterday = Carbon::parse($user->today())->subDay()->toDateString();
        $this->earned($user, 1000, $yesterday);

        foreach ($this->questsFor($user) as $quest) {
            $this->assertSame(0, $quest['progress']);
            $this->assertFalse($quest['claimable']);
        }
    }

    public function test_the_page_offers_a_button_only_until_the_tier_is_claimed(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 100);

        // The hidden input is the only place the key appears in the markup,
        // and the closing quote keeps quest_points_1000 from matching.
        $this->actingAs($user)->get(route('quests.index'))
            ->assertOk()
            ->assertSee('value="quest_points_100"', false);

        $this->claim($user, 'quest_points_100');

        $this->actingAs($user)->get(route('quests.index'))
            ->assertOk()
            ->assertDontSee('value="quest_points_100"', false)
            ->assertSee('Claimed');
    }

    // ------------------------------------------------------------------ claiming

    public function test_claiming_an_earned_tier_pays_credits_and_records_the_claim(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 100);

        $this->claim($user, 'quest_points_100')
            ->assertRedirect(route('quests.index'))
            ->assertSessionHas('status', 'Quest claimed.');

        $claim = QuestClaim::sole();

        $this->assertSame($user->getKey(), $claim->user_id);
        $this->assertSame('quest_points_100', $claim->quest_key);
        $this->assertSame($user->today(), $claim->activity_date->toDateString());

        // Recorded as paid rather than looked up later: retuning QUESTS must
        // not rewrite what someone was actually given.
        $this->assertSame(10, $claim->reward);
        $this->assertSame(PointTransaction::CURRENCY_CREDITS, $claim->currency);

        $row = PointTransaction::sole();

        $this->assertSame(PointTransaction::CURRENCY_CREDITS, $row->currency);
        $this->assertSame('quest_points_100', $row->reason);
        $this->assertSame(10, $row->amount);
        $this->assertSame(10, $row->balance_after);

        // The claim row is the source, which is how a payout is traced back
        // to the day it settled against.
        $this->assertSame($claim->getKey(), (int) $row->source_id);
        $this->assertSame($claim->getMorphClass(), $row->source_type);
    }

    /**
     * The end-to-end one. QUESTS names its metric in a string, so a fixture
     * that writes points_earned proves nothing about whether settlement
     * writes the same column — only a real session does.
     */
    public function test_points_from_a_finished_quiz_are_what_the_bar_reads(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet(30);

        $this->assertSame(100, $this->playPerfectly($user, $set)->points_awarded);

        $quests = $this->questsFor($user);

        $this->assertSame(100, $quests['quest_points_100']['progress']);
        $this->assertTrue($quests['quest_points_100']['claimable']);
        $this->assertFalse($quests['quest_points_250']['claimable']);

        $this->claim($user, 'quest_points_100');

        $user->refresh();

        $this->assertSame(100, $user->total_points);
        $this->assertSame(10, $user->credits);
    }

    /**
     * Ledger reads its column off BALANCE_COLUMNS by currency. A credit
     * landing in total_points would look like a working feature right up
     * until someone read the leaderboard.
     */
    public function test_the_reward_lands_in_credits_and_leaves_points_alone(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 100);
        $user->forceFill(['total_points' => 100])->save();

        $this->claim($user, 'quest_points_100');

        $user->refresh();

        $this->assertSame(10, $user->credits);
        $this->assertSame(100, $user->total_points);
        $this->assertSame(0, PointTransaction::query()->currency(PointTransaction::CURRENCY_POINTS)->count());
    }

    /**
     * Every tier is its own claim, and each mint reads the balance the last
     * one left. If credit() took the balance off a stale instance instead of
     * incrementing in place, these snapshots would all read 10.
     */
    public function test_every_tier_is_claimed_separately_and_the_snapshots_run(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 1000);

        foreach (array_keys(Quests::QUESTS) as $key) {
            $this->claim($user, $key);
        }

        $this->assertSame(count(Quests::QUESTS), QuestClaim::count());
        $this->assertSame(185, $user->refresh()->credits);

        $this->assertSame(
            [10, 35, 85, 185],
            array_map('intval', PointTransaction::query()
                ->currency(PointTransaction::CURRENCY_CREDITS)
                ->orderBy('id')
                ->pluck('balance_after')
                ->all()),
        );

        $audit = $user->balanceAudit(PointTransaction::CURRENCY_CREDITS);
        $this->assertTrue($audit['agrees'], 'credits ledger and cached balance disagree: '.json_encode($audit));
    }

    /**
     * Two currencies in one table: the snapshot for either must ignore the
     * other's rows. This is the case that catches a latestFirst() that
     * forgot to filter.
     */
    public function test_a_credit_does_not_disturb_the_points_snapshot(): void
    {
        $user = User::factory()->create();
        $a = $this->makeSet(30);
        $b = $this->makeSet(30);

        $this->playPerfectly($user, $a);
        $this->claim($user, 'quest_points_100');
        $this->playPerfectly($user, $b);

        $user->refresh();

        $this->assertSame(200, $user->total_points);
        $this->assertSame(10, $user->credits);
        $this->assertSame(200, $user->ledgerSnapshot());
        $this->assertSame(10, $user->ledgerSnapshot(PointTransaction::CURRENCY_CREDITS));

        foreach ([PointTransaction::CURRENCY_POINTS, PointTransaction::CURRENCY_CREDITS] as $currency) {
            $audit = $user->balanceAudit($currency);
            $this->assertTrue($audit['agrees'], "{$currency} disagrees: ".json_encode($audit));
        }
    }

    // -------------------------------------------------------------- the refusals

    /**
     * claim() has no read-then-write check — it inserts and lets the unique
     * index decide — so a second sequential POST exercises the same code path
     * a simultaneous one would, right down to the catch.
     */
    public function test_claiming_twice_pays_once(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 100);

        $this->claim($user, 'quest_points_100')->assertSessionHas('status', 'Quest claimed.');
        $this->claim($user, 'quest_points_100')->assertSessionHas('status', self::REFUSED);

        $this->assertSame(1, QuestClaim::count());
        $this->assertSame(1, PointTransaction::count());
        $this->assertSame(10, $user->refresh()->credits);
    }

    /**
     * The guard itself, independent of the service. If the unique index ever
     * goes missing from a migration, claim()'s catch block becomes dead code
     * and the test above starts passing for the wrong reason.
     */
    public function test_the_claim_table_itself_refuses_a_duplicate(): void
    {
        $user = User::factory()->create();

        $attributes = [
            'user_id' => $user->getKey(),
            'quest_key' => 'quest_points_100',
            'activity_date' => $user->today(),
            'reward' => 10,
            'currency' => PointTransaction::CURRENCY_CREDITS,
        ];

        QuestClaim::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        QuestClaim::create($attributes);
    }

    public function test_a_tier_below_its_threshold_pays_nothing(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 99);

        $this->claim($user, 'quest_points_100')->assertSessionHas('status', self::REFUSED);

        $this->assertSame(0, QuestClaim::count());
        $this->assertSame(0, PointTransaction::count());
        $this->assertSame(0, $user->refresh()->credits);
    }

    /**
     * An unknown key is a non-event, not a validation failure — the form
     * can't produce one, so anything that does is someone posting by hand.
     */
    public function test_an_unknown_quest_key_pays_nothing(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 1000);

        $this->claim($user, 'quest_points_9999')
            ->assertRedirect(route('quests.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::REFUSED);

        $this->assertSame(0, QuestClaim::count());
        $this->assertSame(0, $user->refresh()->credits);
    }

    public function test_the_quest_key_is_required(): void
    {
        $user = User::factory()->create();
        $this->earned($user, 1000);

        $this->actingAs($user)
            ->post(route('quests.claim'), [])
            ->assertSessionHasErrors('quest');

        $this->assertSame(0, QuestClaim::count());
        $this->assertSame(0, $user->refresh()->credits);
    }

    /**
     * Progress is per user and read inside the transaction, so one person's
     * quiz can't fund another's claim.
     */
    public function test_one_users_progress_does_not_pay_another(): void
    {
        $earner = User::factory()->create();
        $other = User::factory()->create();

        $this->earned($earner, 1000);

        $this->claim($other, 'quest_points_100')->assertSessionHas('status', self::REFUSED);

        $this->assertSame(0, QuestClaim::count());
        $this->assertSame(0, $other->refresh()->credits);

        // And the earner still has an unclaimed tier waiting.
        $this->claim($earner, 'quest_points_100');

        $this->assertSame(10, $earner->refresh()->credits);
        $this->assertSame(0, $other->refresh()->credits);
        $this->assertFalse($this->questsFor($other)['quest_points_100']['claimed']);
    }

    /**
     * activity_date is in the unique key, so yesterday's claim is not this
     * one — quests reset, they don't retire.
     */
    public function test_a_claim_is_per_day_not_per_lifetime(): void
    {
        $user = User::factory()->create();

        QuestClaim::create([
            'user_id' => $user->getKey(),
            'quest_key' => 'quest_points_100',
            'activity_date' => Carbon::parse($user->today())->subDay()->toDateString(),
            'reward' => 10,
            'currency' => PointTransaction::CURRENCY_CREDITS,
        ]);

        $this->earned($user, 100);

        $this->assertFalse($this->questsFor($user)['quest_points_100']['claimed']);

        $this->claim($user, 'quest_points_100')->assertSessionHas('status', 'Quest claimed.');

        $this->assertSame(2, QuestClaim::count());
        $this->assertSame(10, $user->refresh()->credits);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('quests.index'))->assertRedirect(route('login'));

        $this->post(route('quests.claim'), ['quest' => 'quest_points_100'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, QuestClaim::count());
        $this->assertSame(0, PointTransaction::count());
    }

    // ------------------------------------------------------------- the local day

    /**
     * 15:30 UTC is already the next day in Tokyo. Everything here — which row
     * progressFor() reads, and the date stamped on the claim — has to follow
     * the user's clock, not the server's.
     */
    public function test_the_day_boundary_is_the_users_own_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 15:30:00', 'UTC'));

        $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

        $this->assertSame('2026-08-25', $user->today());

        // Banked before their local midnight: a big number on the day that
        // just ended, which must not light anything up.
        $this->earned($user, 1000, '2026-08-24');

        $this->assertFalse($this->questsFor($user)['quest_points_100']['claimable']);

        $this->claim($user, 'quest_points_100')->assertSessionHas('status', self::REFUSED);
        $this->assertSame(0, QuestClaim::count());

        $this->earned($user, 100, '2026-08-25');

        $this->claim($user, 'quest_points_100')->assertSessionHas('status', 'Quest claimed.');

        $this->assertSame('2026-08-25', QuestClaim::sole()->activity_date->toDateString());
        $this->assertSame(10, $user->refresh()->credits);
    }

    // ------------------------------------------------------------- the definitions

    /**
     * QUESTS is an array of strings and numbers with nothing checking it, and
     * a typo'd metric fails silently as a bar stuck on zero. This is the
     * cheapest place to catch adding a tier wrong.
     */
    public function test_every_quest_definition_is_wired_to_something_real(): void
    {
        foreach (Quests::QUESTS as $key => $quest) {
            $this->assertLessThanOrEqual(
                32,
                strlen($key),
                "{$key} does not fit point_transactions.reason or quest_claims.quest_key",
            );

            $this->assertTrue(
                Schema::hasColumn('daily_activities', $quest['metric']),
                "{$key} counts {$quest['metric']}, which is not a column on daily_activities",
            );

            // The blade divides by the threshold to size the fill.
            $this->assertGreaterThan(0, $quest['threshold'], "{$key} has no threshold to reach");
            $this->assertGreaterThan(0, $quest['reward'], "{$key} pays nothing");
        }
    }
}
