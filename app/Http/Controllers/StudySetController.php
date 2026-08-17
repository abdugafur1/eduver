<?php

namespace App\Http\Controllers;

use App\Models\StudySet;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
