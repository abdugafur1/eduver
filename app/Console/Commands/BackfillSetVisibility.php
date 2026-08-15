<?php

namespace App\Console\Commands;

use App\Models\StudySet;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets visibility on every study set deliberately, rather than leaving whatever
 * the import happened to write.
 *
 * The rule is deny-by-default: everything goes private, then a named allow-list
 * comes back out. That way an author who never agreed to have their work
 * republished stays private even if this command is re-run after new rows land,
 * and a bug in the allow-list under-publishes rather than over-publishes.
 *
 * Dry-run by default. Nothing is written without --force.
 */
class BackfillSetVisibility extends Command
{
    protected $signature = 'sets:backfill-visibility
        {--owner=eduver : Official account; its sets are always published}
        {--publish=* : Additional usernames whose sets should go public (repeatable)}
        {--min-cards=1 : Sets with fewer cards than this stay private}
        {--force : Actually write. Without it the command only reports.}';

    protected $description = 'Set every study set private, then publish the official account and any vouched-for authors';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $minCards = (int) $this->option('min-cards');

        $usernames = array_values(array_unique(array_merge(
            [$this->option('owner')],
            $this->option('publish')
        )));

        $publishers = User::whereIn('username', $usernames)->get(['id', 'username']);

        if ($missing = array_diff($usernames, $publishers->pluck('username')->all())) {
            $this->error('Unknown username(s): '.implode(', ', $missing));

            return self::FAILURE;
        }

        $publisherIds = $publishers->pluck('id')->all();

        $total = StudySet::count();

        $publicQuery = fn () => StudySet::whereIn('user_id', $publisherIds)
            ->where('cards_count', '>=', $minCards);

        $willPublish = $publicQuery()->count();

        $heldBack = StudySet::whereIn('user_id', $publisherIds)
            ->where('cards_count', '<', $minCards)
            ->count();

        $this->newLine();
        $this->table(['', 'sets'], [
            ['total', $total],
            ['going public', $willPublish],
            ['staying private', $total - $willPublish],
            ["empty (under {$minCards} cards, held back)", $heldBack],
        ]);

        $this->newLine();
        $this->table(['publishing as', 'sets'], $publishers->map(fn (User $u) => [
            $u->username,
            StudySet::where('user_id', $u->id)->where('cards_count', '>=', $minCards)->count(),
        ])->all());

        if (! $force) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($publicQuery) {
            // Order matters: the blanket private write first, so a set that was
            // public under an old rule and no longer qualifies actually flips back.
            StudySet::query()->update(['visibility' => 'private']);

            $publicQuery()->update(['visibility' => 'public']);
        });

        $this->newLine();
        $this->info("Done. {$willPublish} public, ".($total - $willPublish).' private.');
        $this->comment('Check /sets as a guest — that page should now show exactly the public set.');

        return self::SUCCESS;
    }
}
