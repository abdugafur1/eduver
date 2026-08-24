<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\QuizSession;
use App\Models\StudySet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesStudySets;
use Tests\TestCase;

/**
 * Browsing and studying: everything that isn't a quiz.
 *
 * The reason this file exists is the route rename. sets.show used to mean the
 * card table and now means flashcards, with the table moved to sets.terms.
 * That touched the browse tiles and the legacy 301, and nothing asserted either
 * still worked — a broken tile would have gone unnoticed until someone clicked
 * one, and a broken 301 until an old eduver.net link was followed.
 *
 * Your-sets and deletion live here too rather than in a file of their own: they
 * want the same makeSet() helper, and splitting on "reads vs writes" would put
 * mine() — which is a listing, and reads like index — on the far side of the
 * split from the listing tests it has to stay consistent with. Card management
 * does live elsewhere; makeSet() moved to Tests\Concerns\MakesStudySets when
 * CardOrderTest needed it too.
 */
class StudySetTest extends TestCase
{
    use RefreshDatabase;
    use MakesStudySets;

    // ------------------------------------------------------------------ browsing

    public function test_the_browser_lists_public_sets_to_anyone(): void
    {
        $set = $this->makeSet(attributes: ['title' => 'Japanese Kanji 12']);

        $this->get(route('sets.index'))
            ->assertOk()
            ->assertSee('Japanese Kanji 12');
    }

    public function test_someone_elses_private_set_is_not_listed(): void
    {
        $this->makeSet(visibility: StudySet::VISIBILITY_PRIVATE, attributes: ['title' => 'Secret set']);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.index'))
            ->assertDontSee('Secret set');
    }

    public function test_your_own_private_set_is_listed_to_you(): void
    {
        $owner = User::factory()->create();
        $this->makeSet(owner: $owner, visibility: StudySet::VISIBILITY_PRIVATE, attributes: ['title' => 'My draft']);

        $this->actingAs($owner)
            ->get(route('sets.index'))
            ->assertSee('My draft');
    }

    /**
     * Unlisted is deliberately not the same as public: reachable by direct
     * link, absent from the browser. scopeVisibleTo omits it; isReadableBy
     * allows it.
     */
    public function test_an_unlisted_set_is_reachable_but_not_listed(): void
    {
        $set = $this->makeSet(visibility: StudySet::VISIBILITY_UNLISTED, attributes: ['title' => 'Shared by link']);

        $this->get(route('sets.index'))->assertDontSee('Shared by link');
        $this->get(route('sets.show', $set))->assertOk()->assertSee('Shared by link');
    }

    /**
     * The import left ~1,130 clones of five starter sets. Without the default
     * exclusion they outnumber real content four to one.
     */
    public function test_starter_packs_are_hidden_unless_asked_for(): void
    {
        $this->makeSet(attributes: ['title' => 'Starter vocabulary', 'source' => StudySet::SOURCE_STARTER]);

        $this->get(route('sets.index'))->assertDontSee('Starter vocabulary');
        $this->get(route('sets.index', ['starter' => 1]))->assertSee('Starter vocabulary');
    }

    public function test_search_matches_the_author_as_well_as_the_set(): void
    {
        $author = User::factory()->create(['name' => 'Abdugafur']);
        $this->makeSet(owner: $author, attributes: ['title' => 'Biology Terms']);
        $this->makeSet(attributes: ['title' => 'World History']);

        $this->get(route('sets.index', ['q' => 'Abdugafur']))
            ->assertSee('Biology Terms')
            ->assertDontSee('World History');
    }

    public function test_the_minimum_card_filter_reads_the_counter_column(): void
    {
        $this->makeSet(cards: 2, attributes: ['title' => 'Tiny set']);
        $this->makeSet(cards: 9, attributes: ['title' => 'Bigger set']);

        $this->get(route('sets.index', ['min' => 5]))
            ->assertSee('Bigger set')
            ->assertDontSee('Tiny set');
    }

    /** The tiles point at flashcards. This is what the rename could have broken. */
    public function test_a_browse_tile_links_to_flashcards(): void
    {
        $set = $this->makeSet();

        $this->get(route('sets.index'))
            ->assertSee(route('sets.show', $set), escape: false);
    }

    // ---------------------------------------------------------------- flashcards

    public function test_flashcards_is_the_canonical_set_page(): void
    {
        $set = $this->makeSet();

        $this->get(route('sets.show', $set))
            ->assertOk()
            ->assertViewIs('sets.show');
    }

    /**
     * quiz_sessions.user_id is constrained() and not nullable, so every quiz
     * mode needs an account. Flashcards is the one study mode a guest can use,
     * which is the whole reason it's the canonical page rather than a picker.
     */
    public function test_a_guest_can_study_flashcards(): void
    {
        $set = $this->makeSet();

        $this->get(route('sets.show', $set))->assertOk();
        $this->assertGuest();
    }

    public function test_flashcards_writes_nothing(): void
    {
        $user = User::factory()->create();
        $set = $this->makeSet();

        $this->actingAs($user)->get(route('sets.show', $set))->assertOk();

        $this->assertSame(0, QuizSession::count());
        $this->assertSame(0, Card::where('times_tested', '>', 0)->count());

        // set_user.last_studied_at is what feeds "Jump Back In" — deliberately
        // not touched here, so this pins that decision rather than assuming it.
        // Queried directly: StudySet has no belongsToMany for the pivot yet.
        $this->assertDatabaseCount('set_user', 0);
    }

    public function test_the_deck_arrives_in_card_order(): void
    {
        $set = StudySet::create([
            'slug' => 'set-'.str()->random(10),
            'user_id' => User::factory()->create()->getKey(),
            'title' => 'Ordered',
            'visibility' => StudySet::VISIBILITY_PUBLIC,
        ]);

        // Inserted out of order: position, not id, decides the deck.
        foreach ([3, 1, 2] as $position) {
            Card::create([
                'study_set_id' => $set->getKey(),
                'position' => $position,
                'term' => "term {$position}",
                'definition' => "definition {$position}",
            ]);
        }
        $set->recountCards();

        $this->get(route('sets.show', $set))
            ->assertViewHas('cards', fn ($cards) => $cards->pluck('term')->all() === ['term 1', 'term 2', 'term 3']);
    }

    public function test_a_stranger_cannot_open_a_private_set(): void
    {
        $set = $this->makeSet(visibility: StudySet::VISIBILITY_PRIVATE);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.show', $set))
            ->assertNotFound();
    }

    public function test_the_owner_can_open_their_own_private_set(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(owner: $owner, visibility: StudySet::VISIBILITY_PRIVATE);

        $this->actingAs($owner)->get(route('sets.show', $set))->assertOk();
    }

    public function test_a_set_with_no_cards_still_opens(): void
    {
        $set = $this->makeSet(cards: 0);

        $this->get(route('sets.show', $set))
            ->assertOk()
            ->assertViewHas('cards', fn ($cards) => $cards->isEmpty());
    }

    // --------------------------------------------------------------- terms table

    public function test_the_terms_table_lists_cards(): void
    {
        $set = $this->makeSet(cards: 3);

        $this->get(route('sets.terms', $set))
            ->assertOk()
            ->assertViewIs('sets.terms')
            ->assertSee('definition 2');
    }

    public function test_the_terms_table_paginates(): void
    {
        $set = $this->makeSet(cards: 65);

        $this->get(route('sets.terms', $set))
            ->assertViewHas('cards', fn ($cards) => $cards->count() === 60 && $cards->total() === 65);
    }

    public function test_the_terms_table_searches_within_the_set(): void
    {
        $set = $this->makeSet(cards: 12);

        // Parameters go in one array: route()'s third argument is \$absolute.
        $this->get(route('sets.terms', ['studySet' => $set, 'q' => 'term 7']))
            ->assertViewHas('cards', fn ($cards) => $cards->total() === 1);
    }

    /**
     * The row number is a CSS counter, reset on every render — so page 2 of an
     * 85-card set started again at 1. Seeded from the paginator now, which is
     * the same number its "61-85 of 85" footer is built from.
     */
    public function test_the_terms_table_numbers_rows_across_pages(): void
    {
        $set = $this->makeSet(cards: 65);

        $this->get(route('sets.terms', $set))
            ->assertSee('counter-reset: card 0', escape: false);

        $this->get(route('sets.terms', ['studySet' => $set, 'page' => 2]))
            ->assertSee('counter-reset: card 60', escape: false);
    }

    public function test_the_terms_table_honours_visibility(): void
    {
        $set = $this->makeSet(visibility: StudySet::VISIBILITY_PRIVATE);

        $this->actingAs(User::factory()->create())
            ->get(route('sets.terms', $set))
            ->assertNotFound();
    }

    // ----------------------------------------------------------- legacy redirects

    /**
     * Old eduver.net links carry the "ab###########" identifier, which used to
     * double as a physical table name. resolveRouteBinding() accepts it; the
     * controller then 301s to the canonical slug rather than serving one set at
     * two addresses.
     */
    public function test_a_legacy_link_redirects_to_the_canonical_flashcards_url(): void
    {
        $set = $this->makeSet(attributes: ['legacy_slug' => 'ab13705011285']);

        $this->get('/sets/ab13705011285')
            ->assertStatus(301)
            ->assertRedirect(route('sets.show', $set));
    }

    /**
     * The redirect has to preserve which page you asked for. Bouncing a legacy
     * link to the card table onto flashcards would be a silent behaviour change
     * dressed up as a redirect — this is the case the route rename introduced.
     */
    public function test_a_legacy_link_to_the_terms_table_stays_on_the_terms_table(): void
    {
        $set = $this->makeSet(attributes: ['legacy_slug' => 'ab13705011285']);

        $this->get('/sets/ab13705011285/terms')
            ->assertStatus(301)
            ->assertRedirect(route('sets.terms', $set));
    }

    public function test_the_canonical_url_does_not_redirect_to_itself(): void
    {
        $set = $this->makeSet(attributes: ['legacy_slug' => 'ab13705011285']);

        $this->get(route('sets.show', $set))->assertOk();
        $this->get(route('sets.terms', $set))->assertOk();
    }

    /**
     * /sets/{slug}/terms is declared before /sets/{slug}, or the wildcard
     * swallows it and "terms" is looked up as a set slug.
     */
    public function test_the_terms_route_is_not_swallowed_by_the_set_wildcard(): void
    {
        $set = $this->makeSet();

        $this->get(route('sets.terms', $set))
            ->assertOk()
            ->assertViewIs('sets.terms');
    }

    public function test_a_soft_deleted_set_is_gone(): void
    {
        $set = $this->makeSet();
        $set->delete();

        $this->get(route('sets.show', $set))->assertNotFound();
        $this->get(route('sets.index'))->assertDontSee('Test set');
    }

    // ------------------------------------------------------------- your sets

    /**
     * Also pins the route ordering. /sets/mine is declared inside the auth
     * group, which sits above the /sets/{studySet} wildcard at the bottom of
     * the file. Declared after it, this would 404 as a lookup for a set
     * slugged "mine" instead of redirecting to login.
     */
    public function test_your_sets_needs_an_account(): void
    {
        $this->get(route('sets.mine'))->assertRedirect(route('login'));
    }

    /**
     * The whole reason mine() queries user_id directly instead of filtering
     * index(). scopeVisibleTo() omits unlisted from browse by design, so a
     * shared-by-link set appears on no listing anywhere if this regresses.
     */
    public function test_your_sets_shows_your_private_and_unlisted_work(): void
    {
        $owner = User::factory()->create();
        $this->makeSet(owner: $owner, visibility: StudySet::VISIBILITY_PRIVATE, attributes: ['title' => 'Private draft']);
        $this->makeSet(owner: $owner, visibility: StudySet::VISIBILITY_UNLISTED, attributes: ['title' => 'Shared by link']);

        $this->actingAs($owner)
            ->get(route('sets.mine'))
            ->assertOk()
            ->assertViewIs('sets.mine')
            ->assertSee('Private draft')
            ->assertSee('Shared by link');
    }

    public function test_your_sets_excludes_everyone_elses(): void
    {
        $owner = User::factory()->create();
        $this->makeSet(owner: $owner, attributes: ['title' => 'Kanji drills']);
        $this->makeSet(attributes: ['title' => 'Somebody elses set']);

        $this->actingAs($owner)
            ->get(route('sets.mine'))
            ->assertSee('Kanji drills')
            ->assertDontSee('Somebody elses set');
    }

    /**
     * Browse hides starter packs but offers ?starter=1 to bring them back.
     * Your sets has no such escape hatch, deliberately — the query param is
     * simply not in mine()'s validate() rules, so it's ignored rather than
     * rejected. The second request is what pins that difference.
     */
    public function test_your_sets_excludes_starter_packs_with_no_way_to_ask_for_them(): void
    {
        $owner = User::factory()->create();
        $this->makeSet(owner: $owner, attributes: ['title' => 'Starter vocabulary', 'source' => StudySet::SOURCE_STARTER]);
        $this->makeSet(owner: $owner, attributes: ['title' => 'Own work']);

        $this->actingAs($owner)
            ->get(route('sets.mine'))
            ->assertSee('Own work')
            ->assertDontSee('Starter vocabulary');

        $this->actingAs($owner)
            ->get(route('sets.mine', ['starter' => 1]))
            ->assertOk()
            ->assertDontSee('Starter vocabulary');
    }

    public function test_your_sets_omits_what_you_have_deleted(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(owner: $owner, attributes: ['title' => 'Retired set']);
        $set->delete();

        $this->actingAs($owner)
            ->get(route('sets.mine'))
            ->assertDontSee('Retired set');
    }

    // -------------------------------------------------------------- deleting

    public function test_the_owner_can_delete_their_set(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(owner: $owner, attributes: ['title' => 'Retired set']);

        $this->actingAs($owner)
            ->delete(route('sets.destroy', $set))
            ->assertRedirect(route('sets.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted($set);
    }

    public function test_a_stranger_cannot_delete_a_set(): void
    {
        $set = $this->makeSet(attributes: ['title' => 'Not yours']);

        $this->actingAs(User::factory()->create())
            ->delete(route('sets.destroy', $set))
            ->assertForbidden();

        $this->assertNotSoftDeleted($set);
    }

    public function test_a_guest_cannot_delete_a_set(): void
    {
        $set = $this->makeSet();

        $this->delete(route('sets.destroy', $set))->assertRedirect(route('login'));

        $this->assertNotSoftDeleted($set);
    }

    /**
     * Nothing cascades: the trait writes deleted_at and stops. That's what
     * makes withTrashed()->restore() worth anything, and it's only true as
     * long as no future onDelete('cascade') lands on these tables.
     */
    public function test_deleting_a_set_leaves_its_cards_behind(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 3, owner: $owner);

        $this->actingAs($owner)->delete(route('sets.destroy', $set));

        $this->assertSame(3, Card::where('study_set_id', $set->getKey())->count());
    }

    /**
     * The surviving cards still route by id, and studySet is a plain belongsTo,
     * so the relation comes back null through the global scope. A null handed
     * to Gate resolves no policy and denies — which told the owner of a card
     * that it wasn't theirs. The missing set has to be checked before the
     * policy, or this comes back 403 and reads as a permissions bug.
     */
    public function test_a_card_on_a_deleted_set_is_gone_rather_than_forbidden(): void
    {
        $owner = User::factory()->create();
        $set = $this->makeSet(cards: 1, owner: $owner);
        $card = Card::where('study_set_id', $set->getKey())->sole();

        $set->delete();

        $this->actingAs($owner)
            ->get(route('cards.edit', $card))
            ->assertNotFound();
    }
}
