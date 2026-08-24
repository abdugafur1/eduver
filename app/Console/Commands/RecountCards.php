<?php

namespace App\Console\Commands;

use App\Models\StudySet;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Checks study_sets.cards_count against the cards actually there.
 *
 * The column is denormalised, and the only thing keeping it true is Card's
 * created/deleted hooks — which don't fire for anything written through the
 * query builder. eduver:import is the one place that does that, and it
 * reconciles itself before it commits, so a healthy database reports zero
 * here. A non-zero answer means some other bulk write has appeared.
 *
 * That makes this mostly a *reporting* tool. Running it after an import, or
 * after any one-off data script, is the cheapest way to find out whether the
 * counter is lying — and it is worth knowing, because scopeSort('largest')
 * orders on that column and stackDepth() renders the browse grid from it, so
 * drift shows up as wrong-looking pages rather than as an error.
 *
 * Dry-run by default, matching sets:dedupe-starters. Nothing is written
 * without --force.
 */
class RecountCards extends Command
{
    protected $signature = 'sets:recount-cards
        {--with-trashed : Include soft-deleted sets}
        {--force : Actually write. Without it the command only reports.}';

    protected $description = 'Reconcile study_sets.cards_count against the cards table';

    /** Rows listed before the report stops naming names. */
    private const SAMPLE = 20;

    public function handle(): int
    {
        $withTrashed = (bool) $this->option('with-trashed');

        $drifted = $this->drifted($withTrashed);

        $this->newLine();

        if ($drifted->isEmpty()) {
            $this->info('Every counter agrees with the cards table.');

            if (! $withTrashed) {
                $this->line('Soft-deleted sets were not checked. Pass --with-trashed to include them.');
            }

            return self::SUCCESS;
        }

        $total = $this->count($withTrashed);

        $this->table(
            ['id', 'title', 'cards_count', 'actual', 'diff'],
            $drifted->map(fn (StudySet $set) => [
                $set->id,
                Str::limit((string) $set->title, 40),
                $set->cards_count,
                $set->actual_cards,
                sprintf('%+d', $set->actual_cards - $set->cards_count),
            ])->all(),
        );

        if ($total > $drifted->count()) {
            $this->line(sprintf('…and %d more.', $total - $drifted->count()));
        }

        $this->newLine();
        $this->warn(sprintf(
            '%d %s a counter that disagrees with its cards.',
            $total,
            $total === 1 ? 'set has' : 'sets have',
        ));

        if (! $withTrashed) {
            $this->line('Soft-deleted sets were not checked. Pass --with-trashed to include them —');
            $this->line('expect ~1,100 hits there from sets:dedupe-starters, which is not drift.');
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --force to repair.');

            return self::SUCCESS;
        }

        $repaired = StudySet::recountAll(withTrashed: $withTrashed);

        $this->newLine();
        $this->info(sprintf('Repaired %d %s.', $repaired, str('counter')->plural($repaired)));

        // Cheap, and the only thing that proves the statement did what it
        // claims rather than matching zero rows for a different reason.
        $remaining = $this->count($withTrashed);

        if ($remaining > 0) {
            $this->error("{$remaining} still disagree after the repair.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function count(bool $withTrashed): int
    {
        return $this->query($withTrashed)->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, StudySet>
     */
    private function drifted(bool $withTrashed)
    {
        return $this->query($withTrashed)
            // Aliased, because withCount('cards') would name its subquery
            // cards_count and shadow the real column this command exists to
            // check. See the @property note on StudySet.
            ->withCount(['cards as actual_cards'])
            ->orderBy('id')
            ->limit(self::SAMPLE)
            ->get(['id', 'title', 'cards_count']);
    }

    private function query(bool $withTrashed)
    {
        $query = StudySet::query()->miscounted();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query;
    }
}
