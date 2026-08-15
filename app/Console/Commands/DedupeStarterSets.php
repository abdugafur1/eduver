<?php

namespace App\Console\Commands;

use App\Models\StudySet;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Collapses the per-user starter-pack copies down to one canonical row per pack.
 *
 * The legacy site gave every new user their own physical copy of the five
 * starter sets, which is why 187 accounts produced ~1,414 study_sets rows for
 * ~280 genuinely authored ones. This keeps the best copy of each pack, moves it
 * to the official account, and deletes the rest.
 *
 * Deleting a copy must not delete the fact that someone studied it. Both tables
 * that reference study_sets carry per-user history, so they are repointed at the
 * survivor before the copies go:
 *
 *   quiz_sessions — stores aggregates only (mode, counts, points), no card-level
 *     references, so a plain UPDATE is safe and preserves the score history.
 *
 *   set_user — the studied/saved pivot, unique on (user_id, study_set_id). A
 *     blind UPDATE would collide for any user holding a row on both a dropped
 *     copy and the survivor, so rows are merged per user instead: latest
 *     last_studied_at wins, earliest created_at is kept.
 *
 * Dry-run by default. Nothing is written without --force.
 */
class DedupeStarterSets extends Command
{
    protected $signature = 'sets:dedupe-starters
        {--owner=eduver : Username of the account the surviving packs move to}
        {--source=starter : The study_sets.source value that marks a starter copy}
        {--expect=5 : Abort if the number of distinct packs found is not this}
        {--force : Actually write. Without it the command only reports.}';

    protected $description = 'Collapse duplicated starter packs onto one canonical set each, owned by the official account';

    /**
     * Deleted outright along with the dropped sets. Rows here belong to the copy,
     * not to the user, so there is nothing to carry forward.
     *
     * @var list<string>
     */
    protected array $childTables = ['cards'];

    /**
     * Rewritten to point at the surviving set instead of being deleted. Rows here
     * are user history and would be silently lost to the FK cascade otherwise.
     *
     * @var list<string>
     */
    protected array $repointTables = ['quiz_sessions', 'set_user'];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $owner = User::where('username', $this->option('owner'))->first();

        if (! $owner) {
            $this->error("No user with username '{$this->option('owner')}'. Register it first, or pass --owner=.");

            return self::FAILURE;
        }

        if ($unhandled = $this->unhandledReferencingTables()) {
            $this->error('These tables reference study_set_id and this command does not handle them:');
            $this->line('  '.implode(', ', $unhandled));
            $this->line('Add each to $childTables (delete) or $repointTables (carry forward) before running.');

            return self::FAILURE;
        }

        $starters = StudySet::where('source', $this->option('source'))
            ->orderBy('id')
            ->get(['id', 'user_id', 'title', 'cards_count', 'created_at']);

        if ($starters->isEmpty()) {
            $this->error("No sets with source='{$this->option('source')}'. Check the value you used at import time:");
            $this->line("  php artisan tinker --execute=\"print_r(App\\Models\\StudySet::select('source')->distinct()->pluck('source')->all());\"");

            return self::FAILURE;
        }

        // Titles are the grouping key because each copy was cloned from the same
        // template. Normalised so casing or stray whitespace in one copy doesn't
        // split a pack into two groups.
        $groups = $starters->groupBy(fn (StudySet $s) => Str::lower(trim((string) $s->title)));

        $this->newLine();
        $this->line(sprintf('%d starter rows in %d distinct packs.', $starters->count(), $groups->count()));

        $expect = (int) $this->option('expect');

        if ($expect > 0 && $groups->count() !== $expect) {
            $this->newLine();
            $this->error("Expected {$expect} distinct packs, found {$groups->count()}. Not touching anything.");
            $this->line('Either the titles drifted between copies, or --source is catching real user sets.');
            $this->newLine();
            $this->table(['pack title', 'copies'], $groups->map(
                fn ($g, $title) => [Str::limit((string) $title, 60), $g->count()]
            )->values()->all());

            return self::FAILURE;
        }

        /** @var array<int, list<int>> $plan canonical set id => ids being dropped */
        $plan = [];
        $rows = [];

        foreach ($groups as $copies) {
            // Richest copy wins — a user may have edited or deleted cards out of
            // theirs, and the fullest version is the one worth publishing. Oldest
            // id breaks ties, which is the closest thing to the original.
            $canonical = $copies->sortBy([['cards_count', 'desc'], ['id', 'asc']])->first();

            $dropIds = $copies->reject(fn ($s) => $s->id === $canonical->id)->pluck('id')->all();
            $plan[$canonical->id] = $dropIds;

            $rows[] = [
                Str::limit((string) $canonical->title, 36),
                $canonical->id,
                $canonical->cards_count,
                count($dropIds),
                $copies->max('cards_count') === $copies->min('cards_count') ? '' : 'copies differ',
            ];
        }

        $keepIds = array_keys($plan);
        $dropIds = array_merge(...array_values($plan));

        $this->newLine();
        $this->table(['keeping', 'id', 'cards', 'dropping', 'note'], $rows);

        $this->newLine();
        $this->table(['carried forward', 'rows'], [
            ['quiz_sessions repointed', DB::table('quiz_sessions')->whereIn('study_set_id', $dropIds)->count()],
            ['set_user rows merged', DB::table('set_user')->whereIn('study_set_id', $dropIds)->count()],
            ['…of which collide with survivor', $this->collisionCount($plan)],
        ]);

        $this->newLine();
        $this->line(sprintf(
            'Would move %d sets to %s (id %d) and delete %d rows plus their cards.',
            count($keepIds), $owner->username, $owner->id, count($dropIds)
        ));

        if (! $force) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $keepIds, $dropIds, $owner) {
            foreach ($plan as $canonicalId => $losers) {
                if ($losers === []) {
                    continue;
                }

                $this->repointQuizSessions($canonicalId, $losers);
                $this->mergePivot($canonicalId, $losers);
            }

            StudySet::whereIn('id', $keepIds)->update([
                'user_id' => $owner->id,
                'visibility' => 'public',
            ]);

            // Chunked so the IN clause stays sane on ~1,100 ids.
            foreach (array_chunk($dropIds, 500) as $chunk) {
                foreach ($this->childTables as $table) {
                    DB::table($table)->whereIn('study_set_id', $chunk)->delete();
                }

                StudySet::whereIn('id', $chunk)->delete();
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Done. %d packs published under %s, %d duplicate rows removed.',
            count($keepIds), $owner->username, count($dropIds)
        ));
        $this->comment('Next: php artisan sets:backfill-visibility');

        return self::SUCCESS;
    }

    protected function repointQuizSessions(int $canonicalId, array $dropIds): void
    {
        foreach (array_chunk($dropIds, 500) as $chunk) {
            DB::table('quiz_sessions')
                ->whereIn('study_set_id', $chunk)
                ->update(['study_set_id' => $canonicalId, 'updated_at' => now()]);
        }
    }

    /**
     * Merge every pivot row across the group onto the survivor, one per user.
     *
     * Read-then-replace rather than UPDATE, because the unique on
     * (user_id, study_set_id) means a user with rows on two copies has to become
     * one row, not two. MAX/MIN on the timestamps keeps the truest version of
     * "first saved, last studied" across the copies being merged.
     */
    protected function mergePivot(int $canonicalId, array $dropIds): void
    {
        $ids = array_merge([$canonicalId], $dropIds);

        $merged = DB::table('set_user')
            ->whereIn('study_set_id', $ids)
            ->groupBy('user_id')
            ->select([
                'user_id',
                DB::raw('MAX(last_studied_at) as last_studied_at'),
                DB::raw('MIN(created_at) as created_at'),
            ])
            ->get();

        DB::table('set_user')->whereIn('study_set_id', $ids)->delete();

        if ($merged->isEmpty()) {
            return;
        }

        $now = now();

        foreach ($merged->chunk(500) as $chunk) {
            DB::table('set_user')->insert($chunk->map(fn ($row) => [
                'user_id' => $row->user_id,
                'study_set_id' => $canonicalId,
                'last_studied_at' => $row->last_studied_at,
                'created_at' => $row->created_at,
                'updated_at' => $now,
            ])->all());
        }
    }

    /**
     * How many pivot rows would have hit the unique constraint under a naive
     * UPDATE. Reported in the dry run so the merge isn't happening invisibly.
     */
    protected function collisionCount(array $plan): int
    {
        $total = 0;

        foreach ($plan as $canonicalId => $dropIds) {
            if ($dropIds === []) {
                continue;
            }

            $onSurvivor = DB::table('set_user')
                ->where('study_set_id', $canonicalId)
                ->pluck('user_id');

            if ($onSurvivor->isEmpty()) {
                continue;
            }

            $total += DB::table('set_user')
                ->whereIn('study_set_id', $dropIds)
                ->whereIn('user_id', $onSurvivor)
                ->count();
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    protected function unhandledReferencingTables(): array
    {
        $known = array_merge(['study_sets'], $this->childTables, $this->repointTables);
        $found = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, $known, true)) {
                continue;
            }

            if (in_array('study_set_id', Schema::getColumnListing($name), true)) {
                $found[] = $name;
            }
        }

        return $found;
    }
}
