<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\StudySet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStudySets;
use Tests\TestCase;

/**
 * study_sets.cards_count: what keeps it true, and what doesn't.
 *
 * The column is denormalised, so every test here is really about one seam —
 * Eloquent fires Card::booted() and the query builder doesn't. The tests that
 * assert the counter goes *stale* are as load-bearing as the ones that assert
 * it stays right: they're the executable version of the rule that a bulk
 * writer has to reconcile after itself, and if someone later adds a global
 * fix that makes them fail, the failure is the notification.
 *
 * makeSet() calls recountCards() explicitly, which would mask the hooks
 * working, so anything testing the hooks builds its set by hand.
 */
class CardCountTest extends TestCase
{
    use MakesStudySets, RefreshDatabase;

    private function emptySet(?User $owner = null): StudySet
    {
        return StudySet::create([
            'slug' => 'set-'.str()->random(10),
            'user_id' => ($owner ?? User::factory()->create())->getKey(),
            'title' => 'Test set',
            'visibility' => StudySet::VISIBILITY_PUBLIC,
        ]);
    }

    /** A card written straight to the table, skipping Eloquent entirely. */
    private function insertRaw(StudySet $set, int $count = 1): void
    {
        DB::table('cards')->insert(collect(range(1, $count))->map(fn (int $i) => [
            'study_set_id' => $set->getKey(),
            'position' => $i,
            'term' => "raw {$i}",
            'definition' => "raw definition {$i}",
            'times_tested' => 0,
            'times_wrong' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    // ------------------------------------------------------- the events that fire

    public function test_a_new_set_starts_at_zero(): void
    {
        $this->assertSame(0, $this->emptySet()->cards_count);
    }

    public function test_creating_a_card_through_eloquent_moves_the_counter(): void
    {
        $set = $this->emptySet();

        Card::create([
            'study_set_id' => $set->getKey(),
            'position' => 1,
            'term' => 'term',
            'definition' => 'definition',
        ]);

        $this->assertSame(1, $set->refresh()->cards_count);

        $set->cards()->create(['position' => 2, 'term' => 'two', 'definition' => 'two']);

        $this->assertSame(2, $set->refresh()->cards_count);
    }

    public function test_deleting_a_card_moves_it_back(): void
    {
        $set = $this->makeSet(3);

        $set->cards()->ordered()->first()->delete();

        $this->assertSame(2, $set->refresh()->cards_count);
    }

    public function test_adding_cards_through_the_form_keeps_the_counter_true(): void
    {
        $owner = User::factory()->create();
        $set = $this->emptySet($owner);

        $this->actingAs($owner)->post(route('sets.cards.store', $set), [
            'cards' => [
                ['term' => 'a', 'definition' => 'one'],
                ['term' => 'b', 'definition' => 'two'],
                ['term' => 'c', 'definition' => 'three'],
            ],
        ])->assertRedirect(route('sets.terms', $set));

        $this->assertSame(3, $set->refresh()->cards_count);
        $this->assertSame(3, $set->cards()->count());
    }

    public function test_deleting_a_card_through_the_form_keeps_the_counter_true(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $this->actingAs($owner)
            ->delete(route('cards.destroy', $set->cards()->ordered()->first()));

        $this->assertSame(2, $set->refresh()->cards_count);
    }

    /**
     * Editing a card must not recount — Card hooks created and deleted only,
     * deliberately, because QuizController writes times_tested on every answer
     * and recounting on `saved` would be a COUNT per question.
     */
    public function test_updating_a_card_does_not_disturb_the_counter(): void
    {
        $set = $this->makeSet(3);
        $card = $set->cards()->ordered()->first();

        $card->update(['term' => 'renamed']);
        $card->increment('times_tested');

        $this->assertSame(3, $set->refresh()->cards_count);
    }

    // ------------------------------------------------------ the events that don't

    /**
     * The trap. This is what eduver:import does 14,000 times, and the reason
     * it has to reconcile before it commits.
     */
    public function test_a_bulk_insert_leaves_the_counter_stale(): void
    {
        $set = $this->emptySet();

        $this->insertRaw($set, 5);

        $this->assertSame(5, $set->cards()->count());
        $this->assertSame(0, $set->refresh()->cards_count, 'insert() does not fire model events');
    }

    public function test_a_bulk_delete_leaves_the_counter_stale(): void
    {
        $set = $this->makeSet(4);

        $set->cards()->delete();

        $this->assertSame(0, $set->cards()->count());
        $this->assertSame(4, $set->refresh()->cards_count);
    }

    // -------------------------------------------------------------- reconciling

    public function test_the_scope_finds_only_the_drifted_set(): void
    {
        $healthy = $this->makeSet(3);
        $drifted = $this->emptySet();

        $this->insertRaw($drifted, 2);

        $this->assertSame(
            [$drifted->getKey()],
            StudySet::query()->miscounted()->pluck('id')->all(),
        );

        $this->assertSame(3, $healthy->refresh()->cards_count);
    }

    public function test_recount_all_repairs_drift_and_reports_how_much(): void
    {
        $a = $this->emptySet();
        $b = $this->makeSet(4);
        $healthy = $this->makeSet(2);

        $this->insertRaw($a, 5);
        $b->cards()->delete();

        $this->assertSame(2, StudySet::recountAll());

        $this->assertSame(5, $a->refresh()->cards_count);
        $this->assertSame(0, $b->refresh()->cards_count);
        $this->assertSame(2, $healthy->refresh()->cards_count);
        $this->assertSame(0, StudySet::query()->miscounted()->count());
    }

    public function test_recount_all_is_a_no_op_on_a_healthy_database(): void
    {
        $this->makeSet(3);
        $this->makeSet(1);

        $this->assertSame(0, StudySet::recountAll());
    }

    /**
     * Reconciling a counter is not an edit to the set. chooseSet() lists by
     * updated_at, so a repair run must not shuffle everyone's set list to the
     * top — which is what an Eloquent update() would have done.
     */
    public function test_repairing_does_not_touch_updated_at(): void
    {
        $set = $this->emptySet();
        $this->insertRaw($set, 3);

        // Straight to the table: an Eloquent save would rewrite the very
        // column this test is about.
        $before = now()->subMonth();
        DB::table('study_sets')->where('id', $set->getKey())->update(['updated_at' => $before]);

        StudySet::recountAll();

        $this->assertSame(3, $set->refresh()->cards_count);
        $this->assertSame($before->toDateTimeString(), $set->updated_at->toDateTimeString());
    }

    /**
     * sets:dedupe-starters hard-deletes the cards of every copy it drops but
     * only soft-deletes the sets, so those rows are permanently miscounted by
     * design. Including them by default would bury real drift under ~1,100
     * rows of expected noise.
     */
    public function test_trashed_sets_are_left_alone_unless_asked_for(): void
    {
        $set = $this->makeSet(3);
        $set->cards()->delete();
        $set->delete();

        $this->assertSame(0, StudySet::recountAll());
        $this->assertSame(3, StudySet::withTrashed()->find($set->getKey())->cards_count);

        $this->assertSame(1, StudySet::recountAll(withTrashed: true));
        $this->assertSame(0, StudySet::withTrashed()->find($set->getKey())->cards_count);
    }

    // ------------------------------------------------------------------ command

    public function test_the_command_reports_without_writing(): void
    {
        $set = $this->emptySet();
        $this->insertRaw($set, 5);

        $this->artisan('sets:recount-cards')->assertSuccessful();

        $this->assertSame(0, $set->refresh()->cards_count, 'a dry run must not write');
    }

    public function test_the_command_repairs_with_force(): void
    {
        $set = $this->emptySet();
        $this->insertRaw($set, 5);

        $this->artisan('sets:recount-cards', ['--force' => true])->assertSuccessful();

        $this->assertSame(5, $set->refresh()->cards_count);
    }

    public function test_the_command_succeeds_when_there_is_nothing_to_do(): void
    {
        $this->makeSet(3);

        $this->artisan('sets:recount-cards')->assertSuccessful();
        $this->artisan('sets:recount-cards', ['--force' => true])->assertSuccessful();
    }

    public function test_the_command_only_reaches_trashed_sets_when_asked(): void
    {
        $set = $this->makeSet(3);
        $set->cards()->delete();
        $set->delete();

        $this->artisan('sets:recount-cards', ['--force' => true])->assertSuccessful();
        $this->assertSame(3, StudySet::withTrashed()->find($set->getKey())->cards_count);

        $this->artisan('sets:recount-cards', ['--force' => true, '--with-trashed' => true])
            ->assertSuccessful();
        $this->assertSame(0, StudySet::withTrashed()->find($set->getKey())->cards_count);
    }
}
