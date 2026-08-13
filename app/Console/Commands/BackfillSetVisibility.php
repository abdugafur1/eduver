<?php

namespace App\Console\Commands;

use App\Models\StudySet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * All 1,414 study_sets landed on the `private` DEFAULT because the import's
 * INSERT never named the visibility column. This re-derives it.
 *
 * Whatever you run here becomes the production cutover migration, so it is
 * dry-run by default and prints a full breakdown before changing anything.
 *
 *   # Scenario A — legacy had a privacy flag, joined on legacy_slug
 *   php artisan sets:backfill-visibility \
 *       --connection=legacy --table=sets --key=slug --column=is_public
 *
 *   # Scenario B — legacy had no privacy concept; everything was public
 *   php artisan sets:backfill-visibility --all-public
 *
 * Add --apply once the dry run reads correctly.
 */
class BackfillSetVisibility extends Command
{
    protected $signature = 'sets:backfill-visibility
        {--connection= : Legacy DB connection name from config/database.php}
        {--table= : Legacy table holding the sets}
        {--key=slug : Legacy column to match against study_sets.legacy_slug}
        {--column= : Legacy column holding the privacy flag}
        {--all-public : Mark every set public; use only if legacy had no privacy flag}
        {--apply : Actually write. Without this, nothing is changed}';

    protected $description = 'Re-derive study_sets.visibility from the legacy source';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN — no rows will be written. Re-run with --apply to commit.');
            $this->newLine();
        }

        return $this->option('all-public')
            ? $this->allPublic($apply)
            : $this->fromLegacy($apply);
    }

    private function allPublic(bool $apply): int
    {
        $total = StudySet::count();

        $this->line("Would set <info>{$total}</info> sets to ".StudySet::VISIBILITY_PUBLIC.'.');
        $this->newLine();
        $this->warn('This publishes every set in the database. Only correct if the legacy');
        $this->warn('site genuinely had no privacy flag. If it did, and you run this, you');
        $this->warn('publish content users chose to keep private — and this same command');
        $this->warn('is what you will point at real data during cutover.');

        if (! $apply) {
            return self::SUCCESS;
        }

        if (! $this->confirm('Confirmed the legacy schema has no privacy column?', false)) {
            $this->info('Aborted. Nothing changed.');

            return self::SUCCESS;
        }

        StudySet::query()->update(['visibility' => StudySet::VISIBILITY_PUBLIC]);
        $this->info("Updated {$total} sets.");

        return self::SUCCESS;
    }

    private function fromLegacy(bool $apply): int
    {
        foreach (['connection', 'table', 'column'] as $required) {
            if (blank($this->option($required))) {
                $this->error("--{$required} is required unless you pass --all-public.");

                return self::FAILURE;
            }
        }

        $key = $this->option('key');
        $column = $this->option('column');

        $legacy = DB::connection($this->option('connection'))
            ->table($this->option('table'))
            ->pluck($column, $key);

        $this->line("Read <info>{$legacy->count()}</info> rows from the legacy table.");

        $counts = ['public' => 0, 'private' => 0, 'unmatched' => 0, 'no_legacy_slug' => 0];
        $updates = [];

        StudySet::query()
            ->select(['id', 'slug', 'legacy_slug'])
            ->chunkById(500, function ($sets) use ($legacy, &$counts, &$updates) {
                foreach ($sets as $set) {
                    if (blank($set->legacy_slug)) {
                        $counts['no_legacy_slug']++;

                        continue;
                    }

                    if (! $legacy->has($set->legacy_slug)) {
                        $counts['unmatched']++;

                        continue;
                    }

                    // Treats 1/'1'/'yes'/'public' as public, everything else as
                    // private. Verify against the real values before applying.
                    $raw = $legacy->get($set->legacy_slug);
                    $isPublic = in_array(
                        is_string($raw) ? strtolower(trim($raw)) : $raw,
                        [1, true, '1', 'y', 'yes', 'true', 'public', 'everyone'],
                        false
                    );

                    $visibility = $isPublic
                        ? StudySet::VISIBILITY_PUBLIC
                        : StudySet::VISIBILITY_PRIVATE;

                    $counts[$visibility]++;
                    $updates[$visibility][] = $set->id;
                }
            });

        $this->newLine();
        $this->table(['Outcome', 'Sets'], [
            ['→ public', $counts['public']],
            ['→ private', $counts['private']],
            ['no match in legacy table', $counts['unmatched']],
            ['legacy_slug is null', $counts['no_legacy_slug']],
        ]);

        if ($counts['unmatched'] + $counts['no_legacy_slug'] > 0) {
            $this->newLine();
            $this->warn('Unmatched sets keep their current value (private). Investigate');
            $this->warn('those before cutover — silently defaulting is what caused this.');
        }

        if (! $apply) {
            return self::SUCCESS;
        }

        foreach ($updates as $visibility => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                StudySet::whereIn('id', $chunk)->update(['visibility' => $visibility]);
            }
        }

        $this->newLine();
        $this->info('Backfill applied.');

        return self::SUCCESS;
    }
}
