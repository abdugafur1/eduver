<?php

namespace Tests\Feature;

use App\Models\StudySet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesStudySets;
use Tests\TestCase;

/**
 * The sort strip on sets.saved.
 *
 * Two things are actually under test. One is that the library ordering
 * survived being given a name — `library` has to behave exactly as the old
 * unnamed default did. The other is that scopeSort() works at all on this
 * page: it runs on top of the set_user join here, and set_user carries a
 * created_at, so any unqualified column in that scope is an ambiguous-column
 * error rather than a sort. That's why every key gets exercised rather than
 * just the interesting ones.
 *
 * Could fold into StudySetTest; kept separate because it's one surface.
 */
class SavedSetsSortTest extends TestCase
{
    use MakesStudySets, RefreshDatabase;

    /** Titles in the order the page rendered them. */
    private function titles($response): array
    {
        return $response->viewData('sets')->pluck('title')->all();
    }

    private function save(User $user, StudySet $set, ?string $studied, ?string $created = null): void
    {
        $user->savedSets()->attach($set->getKey(), [
            'last_studied_at' => $studied,
            'created_at' => $created ?? now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ the default

    /**
     * COALESCE, not either column alone: a set saved this morning and never
     * opened outranks one you last studied a month ago, and a set you quizzed
     * an hour ago outranks both.
     */
    public function test_the_default_order_is_what_you_touched_last(): void
    {
        $user = User::factory()->create();

        $studiedToday = $this->makeSet(attributes: ['title' => 'Studied today']);
        $savedYesterday = $this->makeSet(attributes: ['title' => 'Saved yesterday']);
        $studiedLastMonth = $this->makeSet(attributes: ['title' => 'Studied last month']);

        $this->save($user, $studiedLastMonth, now()->subMonth()->toDateTimeString(), now()->subMonths(2)->toDateTimeString());
        $this->save($user, $savedYesterday, null, now()->subDay()->toDateTimeString());
        $this->save($user, $studiedToday, now()->toDateTimeString(), now()->subYear()->toDateTimeString());

        $response = $this->actingAs($user)->get(route('sets.saved'))->assertOk();

        $this->assertSame(
            ['Studied today', 'Saved yesterday', 'Studied last month'],
            $this->titles($response),
        );

        // The default is a named key now, and the view needs it to mark the
        // strip — the whole reason this page can share markup with mine.
        $this->assertSame(StudySet::SORT_LIBRARY, $response->viewData('sort'));
    }

    public function test_the_named_library_key_is_the_same_order_as_the_default(): void
    {
        $user = User::factory()->create();

        $a = $this->makeSet(attributes: ['title' => 'Older']);
        $b = $this->makeSet(attributes: ['title' => 'Newer']);

        $this->save($user, $a, now()->subWeek()->toDateTimeString());
        $this->save($user, $b, now()->toDateTimeString());

        $default = $this->titles($this->actingAs($user)->get(route('sets.saved')));
        $named = $this->titles($this->actingAs($user)->get(route('sets.saved', ['sort' => 'library'])));

        $this->assertSame(['Newer', 'Older'], $default);
        $this->assertSame($default, $named);
    }

    // --------------------------------------------------------- the shared scope

    /**
     * The ambiguity test. set_user has a created_at of its own, so an
     * unqualified `order by created_at` in scopeSort() throws here even though
     * it's fine on browse.
     */
    public function test_every_offered_sort_runs_against_the_pivot_join(): void
    {
        $user = User::factory()->create();

        $this->save($user, $this->makeSet(cards: 2, attributes: ['title' => 'Beta']), null);
        $this->save($user, $this->makeSet(cards: 9, attributes: ['title' => 'Alpha']), now()->toDateTimeString());

        foreach (array_keys(StudySet::LIBRARY_SORTS) as $key) {
            $this->actingAs($user)
                ->get(route('sets.saved', ['sort' => $key]))
                ->assertOk();
        }
    }

    public function test_alpha_sorts_by_title(): void
    {
        $user = User::factory()->create();

        $this->save($user, $this->makeSet(attributes: ['title' => 'Zoology']), now()->toDateTimeString());
        $this->save($user, $this->makeSet(attributes: ['title' => 'Anatomy']), null);

        $this->assertSame(
            ['Anatomy', 'Zoology'],
            $this->titles($this->actingAs($user)->get(route('sets.saved', ['sort' => 'alpha']))),
        );
    }

    public function test_largest_sorts_by_card_count(): void
    {
        $user = User::factory()->create();

        $this->save($user, $this->makeSet(cards: 2, attributes: ['title' => 'Small']), now()->toDateTimeString());
        $this->save($user, $this->makeSet(cards: 11, attributes: ['title' => 'Big']), null);

        $this->assertSame(
            ['Big', 'Small'],
            $this->titles($this->actingAs($user)->get(route('sets.saved', ['sort' => 'largest']))),
        );
    }

    // ------------------------------------------------------------- what's refused

    /**
     * `active` counts everyone's completed sessions, which is a different
     * claim from "recently active in your library" and would sit on the strip
     * meaning the opposite thing. `recent` is a valid SORTS key too — both are
     * rejected here, which is the point of a separate map.
     */
    public function test_a_sort_this_page_does_not_offer_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->save($user, $this->makeSet(), null);

        foreach (['active', 'recent', 'nonsense'] as $key) {
            $this->actingAs($user)
                ->get(route('sets.saved', ['sort' => $key]))
                ->assertSessionHasErrors('sort');
        }
    }

    public function test_searching_keeps_the_chosen_sort(): void
    {
        $user = User::factory()->create();

        $this->save($user, $this->makeSet(attributes: ['title' => 'Spanish verbs']), null);
        $this->save($user, $this->makeSet(attributes: ['title' => 'French verbs']), now()->toDateTimeString());
        $this->save($user, $this->makeSet(attributes: ['title' => 'Chemistry']), now()->toDateTimeString());

        $response = $this->actingAs($user)
            ->get(route('sets.saved', ['q' => 'verbs', 'sort' => 'alpha']))
            ->assertOk();

        $this->assertSame(['French verbs', 'Spanish verbs'], $this->titles($response));
        $this->assertSame('alpha', $response->viewData('sort'));
    }

    /**
     * The strip has to render the current key as current, or the page looks
     * unsorted while being sorted.
     */
    public function test_the_strip_marks_the_current_sort(): void
    {
        $user = User::factory()->create();
        $this->save($user, $this->makeSet(), null);

        $this->actingAs($user)
            ->get(route('sets.saved', ['sort' => 'alpha']))
            ->assertOk()
            ->assertSee('Recently active')
            ->assertSee('sort=alpha', false);
    }
}
