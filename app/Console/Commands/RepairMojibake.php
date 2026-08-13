<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs double-encoded UTF-8 ("mojibake") left by importing a latin1 dump
 * that actually held UTF-8 bytes. ひらがな became ã²ã‚‰ãŒãª: every byte
 * above 0x7F got re-encoded a second time.
 *
 * The repair reverses that — reinterpret the latin1 bytes as UTF-8 — and it is
 * NOT idempotent. Run it twice on a row and you destroy good text. Hence the
 * three-part predicate below, which fires only on rows that are provably
 * double-encoded, and the dry run default.
 *
 *   php artisan data:repair-mojibake
 *   php artisan data:repair-mojibake --apply
 */
class RepairMojibake extends Command
{
    protected $signature = 'data:repair-mojibake
        {--apply : Actually write. Without this, nothing is changed}';

    protected $description = 'Repair double-encoded UTF-8 text left by the legacy import';

    /** table => columns holding human-authored text. */
    private const TARGETS = [
        'study_sets' => ['title', 'description'],
        'cards' => ['term', 'definition', 'hint'],
        'quiz_questions' => ['expected_answer', 'submitted_answer'],
        'users' => ['name'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN — nothing will be written. Re-run with --apply to commit.');
        } else {
            $this->warn('This rewrites text in place and CANNOT be run twice safely.');

            if (! $this->confirm('Have you taken a mysqldump backup?', false)) {
                $this->info('Aborted. Back up first.');

                return self::SUCCESS;
            }
        }

        $this->newLine();
        $rows = [];
        $grandTotal = 0;

        foreach (self::TARGETS as $table => $columns) {
            foreach ($columns as $column) {
                $count = DB::table($table)->whereRaw($this->predicate($column))->count();
                $grandTotal += $count;
                $rows[] = ["{$table}.{$column}", $count];

                if ($count > 0 && ! $apply) {
                    $this->showSample($table, $column);
                }
            }
        }

        $this->table(['Column', 'Damaged rows'], $rows);

        if ($grandTotal === 0) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->line("Would repair <info>{$grandTotal}</info> values. Re-run with --apply.");

            return self::SUCCESS;
        }

        // Two phases on purpose. MariaDB tolerates a failed latin1 conversion
        // in a SELECT (warning) but not in an UPDATE (error 1977), and a
        // predicate in the UPDATE's WHERE gets evaluated against every row —
        // including already-repaired kanji, which has no latin1 form at all.
        // So the predicate runs as a SELECT to collect ids, and the write
        // targets primary keys only, touching solely rows already proven
        // convertible.
        DB::transaction(function () {
            foreach (self::TARGETS as $table => $columns) {
                foreach ($columns as $column) {
                    $ids = DB::table($table)
                        ->whereRaw($this->predicate($column))
                        ->pluck('id');

                    $affected = 0;

                    foreach ($ids->chunk(500) as $chunk) {
                        $affected += DB::table($table)
                            ->whereIn('id', $chunk->all())
                            ->update([$column => DB::raw($this->repairExpression($column))]);
                    }

                    $this->line("  {$table}.{$column}: {$affected}");
                }
            }
        });

        $this->newLine();
        $this->info('Repair applied. Re-run without --apply to confirm zero rows remain.');

        return self::SUCCESS;
    }

    /** Reinterpret the column's latin1 bytes as UTF-8. */
    private function repairExpression(string $column): string
    {
        return "CONVERT(CAST(CONVERT(`{$column}` USING latin1) AS BINARY) USING utf8mb4)";
    }

    /**
     * A value is double-encoded only if the repair is exactly reversible.
     *
     * The earlier version of this method tested `repair IS NOT NULL`, on the
     * assumption that CONVERT() yields NULL for invalid UTF-8. MariaDB does no
     * such thing — it substitutes '?' and returns a non-NULL string. That made
     * the guard inert and matched genuine Latin-1 text: protégé "repaired" to
     * prot?g?, which is destruction, not repair.
     *
     * So instead of asking whether the repair looks plausible, re-corrupt it
     * and check it reproduces the input byte for byte. Real mojibake survives
     * the round trip; anything else does not:
     *
     *   protÃ©gÃ©  -> protégé -> protÃ©gÃ©   matches, genuine mojibake
     *   protégé    -> prot?g? -> prot?g?     differs, left alone
     *   hello      -> hello   -> hello       matches but unchanged, excluded
     *
     * utf8mb4_bin throughout: CONVERT() emits utf8mb4_general_ci against
     * utf8mb4_unicode_ci columns (illegal mix), and every _ci collation is
     * accent-insensitive, which would make damaged text compare equal to its
     * own repair.
     */
    private function predicate(string $column): string
    {
        $repair = $this->repairExpression($column);
        $recorrupted = "CONVERT(CONVERT(CAST({$repair} AS BINARY) USING latin1) USING utf8mb4)";

        return "`{$column}` IS NOT NULL"
            ." AND {$recorrupted} COLLATE utf8mb4_bin = `{$column}`"
            ." AND {$repair} COLLATE utf8mb4_bin <> `{$column}`";
    }

    private function showSample(string $table, string $column): void
    {
        $sample = DB::table($table)
            ->whereRaw($this->predicate($column))
            ->selectRaw("`{$column}` AS damaged, {$this->repairExpression($column)} AS repaired")
            ->limit(3)
            ->get();

        $this->line("<comment>{$table}.{$column}</comment>");

        foreach ($sample as $row) {
            $this->line('  '.mb_strimwidth((string) $row->damaged, 0, 44, '…')
                .'  →  '.mb_strimwidth((string) $row->repaired, 0, 44, '…'));
        }

        $this->newLine();
    }
}
