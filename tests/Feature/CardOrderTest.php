<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\StudySet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesStudySets;
use Tests\TestCase;

/**
 * Card reordering.
 *
 * The thing under test is that submitted numbers are a *ranking*, not
 * positions: reorder() sorts by them, breaks ties on current order, and
 * rewrites a dense 1..n. Nothing checks the submission is a permutation,
 * because requiring one is what would make typing numbers by hand miserable —
 * so duplicates, gaps and out-of-range-but-valid values all have to land
 * somewhere sensible, and that's most of what's asserted here.
 */
class CardOrderTest extends TestCase
{
    use RefreshDatabase;
    use MakesStudySets;

    /** Terms in scopeOrdered() order — what every assertion here reads. */
    private function order(StudySet $set): array
    {
        return $set->cards()->ordered()->pluck('term')->all();
    }

    /**
     * A positions[] payload, given the rank to submit for each term. Cards not
     * named are simply left out of the form, which is its own test case.
     *
     * @param  array<string, int>  $ranksByTerm
     * @return array<int, int>
     */
    private function payload(StudySet $set, array $ranksByTerm): array
    {
        return $set->cards()
            ->whereIn('term', array_keys($ranksByTerm))
            ->pluck('id', 'term')
            ->mapWithKeys(fn (int $id, string $term) => [$id => $ranksByTerm[$term]])
            ->all();
    }

    // ------------------------------------------------------------------- access

    public function test_the_owner_can_open_the_reorder_screen(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $this->actingAs($owner)
            ->get(route('sets.cards.order', $set))
            ->assertOk()
            ->assertViewIs('cards.order');
    }

    /**
     * order() and reorder() authorise against the set directly rather than
     * through authorizeCard(), so the manageCards denial needs pinning on both
     * of them separately — the card-level tests don't reach this path.
     */
    public function test_a_stranger_cannot_open_the_reorder_screen(): void
    {
        $set = $this->makeSet(cards: 3);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.cards.order', $set))
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_save_an_order(): void
    {
        $set = $this->makeSet(cards: 3);

        $this->actingAs(User::factory()->create())
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 3' => 1]),
            ])
            ->assertForbidden();

        $this->assertSame(['term 1', 'term 2', 'term 3'], $this->order($set));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $set = $this->makeSet(cards: 3);

        $this->get(route('sets.cards.order', $set))->assertRedirect(route('login'));
        $this->patch(route('sets.cards.reorder', $set), ['positions' => [1 => 1]])
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- reordering

    /** Gaps are fine, and what's stored afterwards is dense regardless. */
    public function test_saving_rewrites_positions_as_a_dense_run(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 1' => 30, 'term 2' => 20, 'term 3' => 10]),
            ])
            ->assertRedirect(route('sets.terms', $set))
            ->assertSessionHas('status');

        $this->assertSame(['term 3', 'term 2', 'term 1'], $this->order($set));
        $this->assertSame([1, 2, 3], $set->cards()->ordered()->pluck('position')->all());
    }

    /**
     * min:0 on the rule exists for this: from the keyboard, 0 is the obvious
     * way to say "put this first" without renumbering everything else.
     */
    public function test_zero_sends_a_card_to_the_front(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 1' => 1, 'term 2' => 2, 'term 3' => 0]),
            ]);

        $this->assertSame(['term 3', 'term 1', 'term 2'], $this->order($set));
    }

    /** Ties fall back to current order, so a form of identical numbers is a no-op. */
    public function test_duplicate_numbers_keep_the_current_order(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 1' => 1, 'term 2' => 1, 'term 3' => 1]),
            ]);

        $this->assertSame(['term 1', 'term 2', 'term 3'], $this->order($set));
        $this->assertSame([1, 2, 3], $set->cards()->ordered()->pluck('position')->all());
    }

    /**
     * The case a set past REORDER_LIMIT hits: rows the screen never rendered
     * aren't in the form, and must land after everything that was, keeping
     * their order relative to each other rather than picking up stale numbers.
     */
    public function test_cards_missing_from_the_form_sort_after_the_rest(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 4, owner: $owner);

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 4' => 1]),
            ]);

        $this->assertSame(['term 4', 'term 1', 'term 2', 'term 3'], $this->order($set));
        $this->assertSame([1, 2, 3, 4], $set->cards()->ordered()->pluck('position')->all());
    }

    /**
     * Most rows in the database are in this state: the import never wrote
     * positions, so scopeOrdered() has been tie-breaking on id. The screen
     * numbers its inputs by loop index rather than by stored position, and the
     * first save is what makes those numbers real.
     */
    public function test_a_legacy_set_with_no_positions_gets_them_on_first_save(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeUnorderedSet(cards: 3, owner: $owner);

        $this->assertSame([0, 0, 0], $set->cards()->ordered()->pluck('position')->all());
        $this->assertSame(['term 1', 'term 2', 'term 3'], $this->order($set));

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 1' => 3, 'term 2' => 2, 'term 3' => 1]),
            ]);

        $this->assertSame(['term 3', 'term 2', 'term 1'], $this->order($set));
        $this->assertSame([1, 2, 3], $set->cards()->ordered()->pluck('position')->all());
    }

    // ------------------------------------------------------------- side effects

    /**
     * The write is raw SQL, so no model events fire. That's correct — Card's
     * booted() hooks created and deleted only — but it means nothing recounts,
     * so this pins that nothing needed to.
     */
    public function test_reordering_leaves_the_card_count_alone(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 5, owner: $owner);

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 5' => 1]),
            ]);

        $this->assertSame(5, $set->refresh()->cards_count);
        $this->assertSame(5, $set->cards()->count());
    }

    /** Raw SQL bypasses Eloquent's timestamps, so updated_at is set by hand. */
    public function test_reordering_touches_updated_at(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $set->cards()->update(['updated_at' => now()->subMonth()]);

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 3' => 1]),
            ]);

        $this->assertTrue($set->cards()->ordered()->first()->updated_at->isToday());
    }

    /** position is smallint unsigned. Anything outside it is a form error. */
    public function test_a_position_outside_the_column_is_rejected(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), [
                'positions' => $this->payload($set, ['term 1' => 70000]),
            ])
            ->assertSessionHasErrors();

        $this->assertSame(['term 1', 'term 2', 'term 3'], $this->order($set));
    }

    public function test_a_set_with_one_card_still_saves(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 1, owner: $owner);
        $card = $set->cards()->sole();

        $this->actingAs($owner)
            ->patch(route('sets.cards.reorder', $set), ['positions' => [$card->getKey() => 1]])
            ->assertRedirect(route('sets.terms', $set));

        $this->assertSame(1, $card->refresh()->position);
    }
}
