<?php

namespace App\Http\Controllers;

use App\Models\StudySet;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StudySetController extends Controller
{
    private const PER_PAGE = 24;

    private const CARDS_PER_PAGE = 60;

    /**
     * Flashcards ships the whole set to the browser in one payload, so this is
     * the ceiling on that. Beyond it the deck is truncated rather than the page
     * being paginated — paging a flashcard deck defeats the point of shuffle.
     */
    private const DECK_LIMIT = 400;

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(array_keys(StudySet::SORTS))],
            'min' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'starter' => ['nullable', 'boolean'],
        ]);

        $sort = $filters['sort'] ?? 'recent';
        $includeStarter = (bool) ($filters['starter'] ?? false);

        $sets = StudySet::query()
            ->with('user:id,username,name')
            // No withCount('cards') — cards_count is a real column and the alias
            // would shadow it. quiz_sessions_count is a genuine subquery, so it
            // is only paid for when that sort is actually selected. Abandoned
            // sessions are excluded: a set nobody finishes isn't a popular set.
            ->when($sort === 'active', fn ($query) => $query->withCount([
                'quizSessions as quiz_sessions_count' => fn ($q) => $q->whereNotNull('completed_at'),
            ]))
            ->visibleTo($request->user())
            ->unless($includeStarter, fn ($query) => $query->excludingStarter())
            ->search($filters['q'] ?? null)
            ->when(
                ! empty($filters['min']),
                fn ($query) => $query->where('cards_count', '>=', (int) $filters['min'])
            )
            ->sort($sort)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('sets.index', [
            'sets' => $sets,
            'q' => $filters['q'] ?? '',
            'sort' => $sort,
            'min' => $filters['min'] ?? null,
            'includeStarter' => $includeStarter,
        ]);
    }


    /**
     * Everything the viewer owns. Not a filtered sets.index: querying by user_id
     * directly means visibleTo() never applies, so private and unlisted sets
     * appear here — this is the only page that shows you your own private work.
     * SoftDeletes keeps trashed sets out on its own.
     *
     * Starter packs are excluded, matching browse. They're per-account import
     * clones, so including them would open a new user's page on five sets they
     * didn't write. Note that CardController::chooseSet() does NOT exclude them,
     * deliberately — you can still add cards to a starter clone, it just isn't
     * listed as yours.
     *
     * No `min` filter: it exists to skim 1,400 browse results, not your dozen.
     */
    public function mine(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(array_keys(StudySet::SORTS))],
        ]);

        $sort = $filters['sort'] ?? 'recent';

        $sets = StudySet::query()
            ->where('user_id', $request->user()->getKey())
            ->when($sort === 'active', fn ($query) => $query->withCount([
                'quizSessions as quiz_sessions_count' => fn ($q) => $q->whereNotNull('completed_at'),
            ]))
            ->excludingStarter()
            ->search($filters['q'] ?? null)
            ->sort($sort)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('sets.mine', [
            'sets' => $sets,
            'q' => $filters['q'] ?? '',
            'sort' => $sort,
        ]);
    }


    /**
     * Your library: sets you've saved, studied, or both.
     *
     * Ordered by COALESCE(last_studied_at, created_at) rather than either
     * column alone, because a row means two different things depending on how
     * it got there — complete() stamps last_studied_at, save() only creates
     * the row. Sorting on last_studied_at alone would bury every saved-but-
     * unstarted set below everything you've quizzed; sorting on created_at
     * alone would ignore the studying entirely.
     *
     * No starter exclusion here, unlike mine(). A starter clone you've
     * actually worked through belongs in your library — the reason browse and
     * your-sets hide them is that they're not authorship, which this page
     * doesn't claim.
     *
     * Trashed sets need no guard: this rides User::savedSets(), which is a
     * StudySet query with the global scope applied. Reading set_user directly
     * would show tombstones.
     */
    public function saved(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $sets = $request->user()
            ->savedSets()
            ->with('user:id,username,name')
            ->search($filters['q'] ?? null)
            ->orderByRaw('COALESCE(set_user.last_studied_at, set_user.created_at) DESC')
            ->orderByDesc('study_sets.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('sets.saved', [
            'sets' => $sets,
            'q' => $filters['q'] ?? '',
        ]);
    }

    /**
     * Put a set in your library without studying it. Leaves last_studied_at
     * null, which is what distinguishes "saved" from "studied" on the page.
     *
     * syncWithoutDetaching rather than attach: set_user is unique on
     * (user_id, study_set_id), so a double-submit would otherwise be a
     * constraint violation rather than a no-op.
     */
    public function save(Request $request, StudySet $studySet): RedirectResponse
    {
        abort_unless($studySet->isReadableBy($request->user()), 404);

        $request->user()->savedSets()->syncWithoutDetaching([$studySet->getKey()]);

        return back()->with('status', 'Saved to your library.');
    }

    /**
     * Removes the row outright, losing last_studied_at with it. That's
     * deliberate — the alternative is a soft "unsaved" flag, and the pivot
     * has nowhere to put one. Studying the set again re-creates the row.
     */
    public function unsave(Request $request, StudySet $studySet): RedirectResponse
    {
        $request->user()->savedSets()->detach($studySet->getKey());

        return back()->with('status', 'Removed from your library.');
    }

    public function create(): View
    {
        Gate::authorize('create', StudySet::class);

        return view('sets.create');
    }

    /**
     * Creates the set only — cards are added on the next screen. Splitting it
     * means a long card-entry session can't lose the title to a validation
     * error, and it gives the set an id that card entry can post against.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', StudySet::class);

        $validated = $this->validateSet($request);

        $set = StudySet::create([
            ...$validated,
            'user_id' => $request->user()->getKey(),
            'slug' => StudySet::uniqueSlug(),
            // Explicit rather than leaning on the column default: a set made
            // here is authored by definition, and `starter` is only ever
            // written by the legacy import.
            'source' => StudySet::SOURCE_AUTHORED,
        ]);

        return redirect()
            ->route('sets.terms', $set)
            ->with('status', 'Set created. Add your first cards below.');
    }

    public function edit(Request $request, StudySet $studySet): View
    {
        Gate::authorize('update', $studySet);

        return view('sets.edit', ['set' => $studySet]);
    }

    public function update(Request $request, StudySet $studySet): RedirectResponse
    {
        Gate::authorize('update', $studySet);

        $studySet->update($this->validateSet($request));

        return redirect()
            ->route('sets.terms', $studySet)
            ->with('status', 'Changes saved.');
    }


    /**
     * Soft delete: the trait and the column were already in place, so this writes
     * deleted_at and nothing cascades. Cards and quiz history survive, and the
     * global scope hides the set from browse and from route binding.
     *
     * There is no restore UI, so to the user this is permanent. Recovery is
     * StudySet::withTrashed()->where('slug', $slug)->restore() in tinker — worth
     * knowing before someone asks for a set back.
     */
    public function destroy(Request $request, StudySet $studySet): RedirectResponse
    {
        Gate::authorize('delete', $studySet);

        $title = $studySet->title;
        $studySet->delete();

        return redirect()
            ->route('sets.index')
            ->with('status', 'Deleted “'.$title.'”.');
    }

    /**
     * Shared by store() and update(). max:128 matches the column exactly —
     * validating looser than the schema turns a form error into a DB
     * exception, the same trap the username field hit during registration.
     *
     * visibility is validated against the model constants rather than a Rule
     * built from the column, because the column is a plain string on purpose:
     * the allowed set lives in the application layer so it can change without
     * a migration.
     */
    private function validateSet(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', Rule::in([
                StudySet::VISIBILITY_PUBLIC,
                StudySet::VISIBILITY_UNLISTED,
                StudySet::VISIBILITY_PRIVATE,
            ])],
        ], [
            'title.required' => 'Give the set a title.',
            'visibility.required' => 'Choose who can see this set.',
        ]);
    }

    /**
     * Flashcards: the canonical view of a set, and the only study mode a guest
     * can use. quiz_sessions.user_id is constrained() and not nullable, so the
     * quiz modes are structurally unavailable logged out — landing here rather
     * than on a picker means an arriving visitor always gets something usable.
     *
     * Nothing is written: no session, no counters, no last_studied_at.
     */
    public function show(Request $request, StudySet $studySet): View|RedirectResponse
    {
        abort_unless($studySet->isReadableBy($request->user()), 404);

        if ($redirect = $this->canonicalRedirect($request, $studySet, 'sets.show')) {
            return $redirect;
        }

        $studySet->load('user:id,username,name');

        // Only the three columns the deck renders. Sending the whole row would
        // put timestamps and the aggregate difficulty counters into the page
        // source for every card in the set.
        $cards = $studySet->cards()
            ->ordered()
            ->limit(self::DECK_LIMIT)
            ->get(['id', 'term', 'definition', 'hint']);

        return view('sets.show', [
            'set' => $studySet,
            'cards' => $cards,
            'truncated' => $studySet->cards_count > self::DECK_LIMIT,
            // One indexed lookup on the unique (user_id, study_set_id), and
            // only for signed-in visitors — the control it drives isn't
            // rendered for guests.
            'isSaved' => $request->user() !== null
                && $studySet->savers()->whereKey($request->user()->getKey())->exists(),
        ]);
    }

    /**
     * The full card table, paginated and searchable. Was sets.show until
     * flashcards took that name.
     */
    public function terms(Request $request, StudySet $studySet): View|RedirectResponse
    {
        abort_unless($studySet->isReadableBy($request->user()), 404);

        if ($redirect = $this->canonicalRedirect($request, $studySet, 'sets.terms')) {
            return $redirect;
        }

        $studySet->load('user:id,username,name')->loadCount([
            'quizSessions as quiz_sessions_count' => fn ($q) => $q->whereNotNull('completed_at'),
        ]);

        $cards = $studySet->cards()
            ->ordered()
            ->search($request->query('q'))
            ->paginate(self::CARDS_PER_PAGE)
            ->withQueryString();

        return view('sets.terms', [
            'set' => $studySet,
            'cards' => $cards,
            'q' => (string) $request->query('q', ''),
            // Drives the edit controls in the template. Computed here so the
            // view never has to reason about ownership itself.
            'canManage' => $request->user() !== null
                && Gate::allows('update', $studySet),
        ]);
    }

    /**
     * Resolved via legacy_slug: 301 the old eduver.net link to the canonical
     * URL rather than serving one set at two addresses. Takes the route name so
     * a legacy link to the terms table doesn't get bounced to flashcards.
     */
    private function canonicalRedirect(Request $request, StudySet $studySet, string $route): ?RedirectResponse
    {
        $requested = $request->route()->originalParameters()['studySet'] ?? null;

        if ($requested === null || $requested === $studySet->slug) {
            return null;
        }

        return redirect()->route($route, ['studySet' => $studySet->slug], 301);
    }
}
