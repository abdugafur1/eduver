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

            foreach ($validated['cards'] as $row) {
                $studySet->cards()->create([
                    'term' => $row['term'],
                    'definition' => $row['definition'],
                    'hint' => $row['hint'] === '' ? null : $row['hint'],
                    'position' => ++$position,
                ]);
            }
        });

        $added = count($validated['cards']);

        return redirect()
            ->route('sets.terms', $studySet)
            ->with('status', $added.' '.str('card')->plural($added).' added.');
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
