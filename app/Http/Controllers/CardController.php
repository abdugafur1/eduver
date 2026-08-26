<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\StudySet;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CardController extends Controller
{
    /** Blank rows offered on a fresh add-cards screen. */
    private const BLANK_ROWS = 5;

    /** Ceiling on one submission. Not a limit on set size — just on one POST. */
    private const MAX_ROWS = 50;

    /**
     * How many rows the reorder screen renders. Same number as flashcards'
     * DECK_LIMIT and for the same reason: one page, no pagination, because
     * positions are global to the set and reordering inside a filtered or
     * paginated view would place a card relative to neighbours that aren't
     * its real ones. Sets past this can't be reordered by hand here — see
     * order().
     */
    private const REORDER_LIMIT = 400;

    /**
     * The rail's "add cards" icon has no set in scope, so it lands here and
     * asks which one. Sets the viewer can't write to are never listed, which
     * makes this the only place in the app that answers "what have I made".
     */
    public function chooseSet(Request $request): View
    {
        $sets = StudySet::query()
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('updated_at')
            ->get(['id', 'slug', 'title', 'cards_count', 'visibility', 'updated_at']);

        return view('cards.choose-set', ['sets' => $sets]);
    }

    public function create(Request $request, StudySet $studySet): View
    {
        Gate::authorize('manageCards', $studySet);

        return view('cards.create', [
            'set' => $studySet,
            'blankRows' => self::BLANK_ROWS,
        ]);
    }

    /**
     * Bulk append. Rows left entirely blank are dropped before validation —
     * the form always offers more rows than most people fill, and demanding
     * they be emptied first would be hostile.
     *
     * Array keys are preserved rather than reindexed, so an error reported
     * against cards.3.term lands on the row the person actually typed in.
     */
    public function store(Request $request, StudySet $studySet): RedirectResponse
    {
        Gate::authorize('manageCards', $studySet);

        $rows = collect($request->input('cards', []))
            ->map(fn ($row) => [
                'term' => trim((string) ($row['term'] ?? '')),
                'definition' => trim((string) ($row['definition'] ?? '')),
                'hint' => trim((string) ($row['hint'] ?? '')),
            ])
            ->reject(fn ($row) => $row['term'] === '' && $row['definition'] === '' && $row['hint'] === '');

        $request->merge(['cards' => $rows->all()]);

        $validated = $request->validate([
            'cards' => ['required', 'array', 'max:'.self::MAX_ROWS],
            'cards.*.term' => ['required', 'string', 'max:500'],
            'cards.*.definition' => ['required', 'string', 'max:2000'],
            'cards.*.hint' => ['nullable', 'string', 'max:500'],
        ], [
            'cards.required' => 'Fill in at least one card.',
            'cards.*.term.required' => 'Add a term, or clear this row.',
            'cards.*.definition.required' => 'Add a definition, or clear this row.',
        ]);

        $this->rejectDuplicates($studySet, $validated['cards']);

        DB::transaction(function () use ($studySet, $validated) {
            // Append after whatever is already there. max() over an empty set
            // returns null, so a first card lands at position 0.
            $position = (int) ($studySet->cards()->max('position') ?? -1);

            // Card's created hook recounts the set, so a 50-row submission
            // recomputes cards_count fifty times and throws away the first
            // forty-nine. Suppressed here and reconciled once below. Safe because
            // booted() registers created and deleted only, and this loop creates.
            Card::withoutEvents(function () use ($studySet, $validated, &$position) {
                foreach ($validated['cards'] as $row) {
                    $studySet->cards()->create([
                        'term' => $row['term'],
                        'definition' => $row['definition'],
                        'hint' => $row['hint'] === '' ? null : $row['hint'],
                        'position' => ++$position,
                    ]);
                }
            });

            $studySet->recountCards();
        });

        $added = count($validated['cards']);

        return redirect()
            ->route('sets.terms', $studySet)
            ->with('status', $added.' '.str('card')->plural($added).' added.');
    }

    /**
     * The reorder screen: every card in one form, one submit.
     *
     * Not up/down arrows per row. They fit the no-JS house style, but moving a
     * card from the bottom of a 112-card set to the top is 111 round trips.
     * The number inputs are the mechanism for everyone; drag is a progressive
     * enhancement over the same server-rendered rows, and rewrites those same
     * inputs before submit.
     *
     * Nothing is written here. The inputs are numbered 1..n by loop index
     * rather than from the stored position, so a legacy set where every row is
     * still 0 renders as a sensible starting order without this GET having to
     * normalise anything. The first save is what makes it dense.
     */
    public function order(Request $request, StudySet $studySet): View
    {
        Gate::authorize('manageCards', $studySet);

        $cards = $studySet->cards()
            ->ordered()
            ->limit(self::REORDER_LIMIT)
            ->get(['id', 'term', 'definition']);

        return view('cards.order', [
            'set' => $studySet,
            'cards' => $cards,
            'overLimit' => $studySet->cards_count > self::REORDER_LIMIT,
            'limit' => self::REORDER_LIMIT,
        ]);
    }

    /**
     * Apply a submitted order.
     *
     * The submitted numbers are a ranking, not positions. They're sorted, ties
     * broken by current order, and then rewritten as a dense 1..n — so
     * duplicates, gaps, and "make this one 0 to send it to the front" all do
     * something sensible instead of erroring, and moving a card 90 places is
     * one number rather than 90 drags.
     *
     * Every card is loaded, not just the page's worth: on a set past
     * REORDER_LIMIT the rows the screen never showed still need positions, or
     * they'd keep old numbers that interleave with the new dense ones. They
     * sort after everything submitted, in their existing order.
     */
    public function reorder(Request $request, StudySet $studySet): RedirectResponse
    {
        Gate::authorize('manageCards', $studySet);

        $validated = $request->validate([
            'positions' => ['required', 'array'],
            // Bounded by the column: position is smallint unsigned. min:0 so
            // "0" remains a usable way to say "first" from the keyboard.
            'positions.*' => ['required', 'integer', 'min:0', 'max:65535'],
        ], [
            'positions.required' => 'Nothing to reorder.',
            'positions.*.integer' => 'Positions have to be whole numbers.',
        ]);

        $submitted = $validated['positions'];

        $cards = $studySet->cards()->ordered()->get(['id']);

        if ($cards->isEmpty()) {
            return redirect()->route('sets.terms', $studySet);
        }

        $ranked = $cards->values()
            ->map(fn (Card $card, int $index) => [
                'id' => $card->getKey(),
                // Cards the form didn't carry sort after everything it did,
                // keeping their relative order via the index tie-break.
                'rank' => array_key_exists($card->getKey(), $submitted)
                    ? (int) $submitted[$card->getKey()]
                    : PHP_INT_MAX,
                'index' => $index,
            ])
            ->sortBy([['rank', 'asc'], ['index', 'asc']])
            ->values();

        DB::transaction(function () use ($ranked, $studySet) {
            // One statement rather than a loop of up to 400 UPDATEs. Safe in a
            // single pass because cards_study_set_id_position_index is not
            // unique — were it unique, writing 1..n over an existing 1..n
            // would collide partway through and need a negative offset pass
            // first.
            //
            // Raw, so Eloquent's timestamps don't apply; updated_at is set
            // explicitly. No model events fire either, which is fine: Card's
            // booted() hooks created and deleted only, and cards_count can't
            // change here.
            $cases = [];
            $bindings = [];

            foreach ($ranked as $rank => $row) {
                $cases[] = 'when ? then ?';
                $bindings[] = $row['id'];
                $bindings[] = $rank + 1;
            }

            $ids = $ranked->pluck('id')->all();

            DB::update(
                'update cards set position = case id '.implode(' ', $cases).' else position end, '
                .'updated_at = ? where study_set_id = ? and id in ('
                .implode(', ', array_fill(0, count($ids), '?')).')',
                [...$bindings, now(), $studySet->getKey(), ...$ids],
            );
        });

        return redirect()
            ->route('sets.terms', $studySet)
            ->with('status', 'Card order saved.');
    }

    public function edit(Request $request, Card $card): View
    {
        $set = $this->authorizeCard($card);

        return view('cards.edit', [
            'card' => $card,
            'set' => $card->studySet,
        ]);
    }

    public function update(Request $request, Card $card): RedirectResponse
    {
        $set = $this->authorizeCard($card);

        $validated = $request->validate([
            'term' => ['required', 'string', 'max:500'],
            'definition' => ['required', 'string', 'max:2000'],
            'hint' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['term'] = trim($validated['term']);
        $validated['definition'] = trim($validated['definition']);
        $validated['hint'] = trim((string) ($validated['hint'] ?? '')) ?: null;

        // Only when the pair actually changed. The import left 123 groups of
        // duplicate terms in place, so running this on every save would block
        // someone from fixing a typo on a legacy row they didn't create.
        $changed = $validated['term'] !== $card->term
            || $validated['definition'] !== $card->definition;

        if ($changed) {
            $this->rejectDuplicates($card->studySet, [$validated], ignore: $card);
        }

        $card->update($validated);

        return redirect()
            ->route('sets.terms', $card->studySet)
            ->with('status', 'Card saved.');
    }

    public function destroy(Request $request, Card $card): RedirectResponse
    {
        $set = $this->authorizeCard($card);

        $set = $card->studySet;

        // quiz_questions.card_id is ON DELETE CASCADE, so this takes the card's
        // answered questions with it and the aggregates on those sessions stop
        // reconciling. Same trap the dedupe hit; here it's a deliberate trade,
        // because leaving cards undeletable to protect quiz history is worse.
        $card->delete();

        return redirect()
            ->route('sets.terms', $set)
            ->with('status', 'Card deleted.');
    }

    /**
     * The old site rejected a card only when term AND definition both matched
     * an existing one in the same set, and that behaviour is reproduced here
     * rather than tightened. Matching on term alone would refuse legitimate
     * second senses of a word, which the GRE sets contain.
     *
     * Checks the submission against itself as well as against the database —
     * ten rows posted at once can duplicate each other without either one
     * existing yet.
     *
     * @param  array<int, array{term: string, definition: string}>  $rows
     */
    private function rejectDuplicates(StudySet $studySet, array $rows, ?Card $ignore = null): void
    {
        $key = fn (array $row) => mb_strtolower($row['term']).'\x1f'.mb_strtolower($row['definition']);

        $existing = $studySet->cards()
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->get(['id', 'term', 'definition'])
            ->map(fn (Card $card) => $key(['term' => $card->term, 'definition' => $card->definition]))
            ->flip();

        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $signature = $key($row);
            $field = array_is_list($rows) && count($rows) === 1 && $ignore
                ? 'term'
                : "cards.{$index}.term";

            if ($existing->has($signature)) {
                $errors[$field] = 'This set already has “'.$row['term'].'” with that definition.';
            } elseif (isset($seen[$signature])) {
                $errors[$field] = '“'.$row['term'].'” is entered twice with the same definition.';
            }

            $seen[$signature] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A soft-deleted set leaves its cards behind and still routes by id, but
     * studySet is a plain belongsTo — the global scope makes it null. Passing
     * null to Gate resolves no policy and denies, so the owner of the card gets
     * a 403 saying it isn't theirs. 404 is the honest answer: the set is gone.
     */
    private function authorizeCard(Card $card): StudySet
    {
        abort_unless($card->studySet, 404);

        Gate::authorize('manageCards', $card->studySet);

        return $card->studySet;
    }
}
