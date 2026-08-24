<?php

namespace Tests\Concerns;

use App\Models\Card;
use App\Models\StudySet;
use App\Models\User;

/**
 * Shared fixtures for anything that needs a set with cards in it.
 *
 * Lifted out of StudySetTest when CardOrderTest needed the same thing. Two
 * copies would have to agree about positions being 1..n and terms being
 * "term {n}", which is precisely what the reorder assertions read — and a
 * copy that drifts breaks those tests in a way that looks like a bug in the
 * controller.
 */
trait MakesStudySets
{
    /**
     * Built explicitly rather than through a factory: cards_count is a
     * denormalised column, and recountCards() is the model's own reconciler.
     *
     * Cards land at positions 1..n in creation order, with terms "term 1"
     * upward, so a test can name a card and know where it started.
     */
    protected function makeSet(
        int $cards = 4,
        ?User $owner = null,
        string $visibility = StudySet::VISIBILITY_PUBLIC,
        array $attributes = [],
    ): StudySet {
        $set = StudySet::create([
            'slug' => 'set-'.str()->random(10),
            'user_id' => ($owner ?? User::factory()->create())->getKey(),
            'title' => 'Test set',
            'visibility' => $visibility,
        ]);

        // forceFill, because not everything a test needs to set is fillable.
        // legacy_slug in particular is guarded — only the importer writes it,
        // and create() drops it silently, which is the model behaving correctly.
        if ($attributes !== []) {
            $set->forceFill($attributes)->save();
        }

        for ($i = 1; $i <= $cards; $i++) {
            Card::create([
                'study_set_id' => $set->getKey(),
                'position' => $i,
                'term' => "term {$i}",
                'definition' => "definition {$i}",
            ]);
        }

        $set->recountCards();

        return $set->refresh();
    }

    /**
     * A set as the legacy import left it: every position still 0, so
     * scopeOrdered() is tie-breaking on id alone. This is the state most rows
     * in the database are actually in.
     */
    protected function makeUnorderedSet(int $cards = 3, ?User $owner = null): StudySet
    {
        $set = $this->makeSet(cards: $cards, owner: $owner);

        $set->cards()->update(['position' => 0]);

        return $set->refresh();
    }
}
