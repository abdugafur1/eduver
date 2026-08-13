<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the 2022-23-era Eduver data into the new schema.
 *
 *   php artisan eduver:import --dry-run
 *   php artisan eduver:import
 *
 * ANONYMISATION
 * -------------
 * No real name, email address or password hash crosses into the new
 * database. Each legacy account becomes a placeholder user whose only
 * inherited property is its identity *relative to other accounts* — so a set
 * still has a distinct author and the leaderboard still has distinct rows,
 * but nobody in the new database is identifiable.
 *
 * The archive keeps the real values. This script deliberately never reads
 * usersEmail, usersPassword, usersFirstName or usersLastName.
 *
 * LEGACY SHAPE
 * ------------
 *   cpUsers.cp_users        one row per account
 *   cpUsers.<username>      that user's sets; ownership is the table NAME
 *   cpCustom.<abCode>       that set's cards
 *   cpSetData.<abCode>      who the set was shared with
 *
 * Ownership being encoded in a table name is why this is a script and not a
 * few INSERT ... SELECT statements.
 */
class ImportLegacyEduver extends Command
{
    protected $signature = 'eduver:import
                            {--dry-run : Report what would be imported, write nothing}
                            {--prefix=n5e6095 : Legacy database prefix}';

    protected $description = 'Import and anonymise the legacy Eduver data';

    /** Tables in the cpUsers database that are not per-user set tables. */
    private const NOT_USER_TABLES = ['cp_users', 'cp_limits'];

    private string $dbUsers;
    private string $dbCustom;
    private string $dbSetData;

    private bool $dry;

    /** lowercased legacy username => new users.id */
    private array $userMap = [];

    /** legacy ab-code => new study_sets.id */
    private array $setMap = [];

    private array $stats = [
        'users' => 0,
        'merged_duplicate_users' => 0,
        'sets' => 0,
        'cards' => 0,
        'shares' => 0,
        'skipped_missing_cards' => 0,
        'skipped_duplicate_set' => 0,
        'skipped_unknown_user' => 0,
    ];

    public function handle(): int
    {
        $prefix = $this->option('prefix');
        $this->dbUsers   = "{$prefix}_cpUsers";
        $this->dbCustom  = "{$prefix}_cpCustom";
        $this->dbSetData = "{$prefix}_cpSetData";
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        if (! $this->legacyDatabasesPresent()) {
            $this->error("Legacy databases not found. Expected {$this->dbUsers}, {$this->dbCustom}, {$this->dbSetData} on this connection.");
            return self::FAILURE;
        }

        if (! $this->dry && ! $this->confirmDestructive()) {
            return self::FAILURE;
        }

        try {
            DB::transaction(function () {
                $this->importUsers();
                $this->importSets();
                $this->importShares();
                $this->recountSets();

                if ($this->dry) {
                    // Everything above ran against the real tables so the
                    // counts are honest; roll it back so the dry run leaves
                    // no trace.
                    throw new DryRunComplete();
                }
            });
        } catch (DryRunComplete) {
            $this->info('Rolled back — no rows written.');
        }

        $this->report();

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------
    // Users
    // -----------------------------------------------------------------

    /**
     * One placeholder user per legacy account.
     *
     * usersUid (the old username) is kept because set tables are named after
     * it and the mapping would otherwise be unrecoverable — but it is the
     * only inherited field, and it is a self-chosen handle rather than a
     * personal identifier. Everything else is synthesised.
     */
    private function importUsers(): void
    {
        $rows = DB::table("{$this->dbUsers}.cp_users")
            ->select('usersId', 'usersUid')   // deliberately nothing else
            ->orderBy('usersId')
            ->get();

        $bar = $this->output->createProgressBar($rows->count());
        $bar->setMessage('users');

        foreach ($rows as $row) {
            $username = trim($row->usersUid);

            if ($username === '') {
                continue;
            }

            // The old schema had no unique index on usersUid — uniqueness was
            // checked in PHP by uidExists(), which is racy: two simultaneous
            // signups both pass the check and both insert. 27 usernames ended
            // up duplicated across 79 rows.
            //
            // Those rows were never really separate accounts. Set tables are
            // named after the username, so every duplicate read and wrote the
            // same cpUsers.<username> table — the data was already merged on
            // the old site. Keeping the lowest usersId records what actually
            // happened rather than inventing a split that never existed.
            //
            // Compared case-insensitively because the new unique index uses a
            // ci collation, so Bader and bader would collide here even though
            // PHP's strcmp treated them as distinct.
            $key = mb_strtolower($username);

            if (isset($this->userMap[$key])) {
                $this->stats['merged_duplicate_users']++;
                $bar->advance();
                continue;
            }

            $id = DB::table('users')->insertGetId([
                'username'   => $username,
                'name'       => $username,
                // .invalid is reserved by RFC 2606 and can never resolve, so
                // a stray mailer cannot reach a real person.
                'email'      => 'legacy-' . $row->usersId . '@eduver.invalid',
                // Unusable by construction: no plaintext hashes to this.
                'password'   => '!legacy-import-no-login!',
                'legacy_id'  => $row->usersId,
                'must_reset_password' => true,
                'timezone'   => 'UTC',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->userMap[$key] = $id;
            $this->stats['users']++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    // -----------------------------------------------------------------
    // Sets and cards
    // -----------------------------------------------------------------

    private function importSets(): void
    {
        $tables = $this->userSetTables();

        $bar = $this->output->createProgressBar(count($tables));

        foreach ($tables as $username) {
            // The table name IS the ownership record.
            $ownerId = $this->userMap[mb_strtolower($username)] ?? null;

            if ($ownerId === null) {
                // A set table with no matching account — orphaned by a
                // deletion that did not cascade. Sets are still readable but
                // have nobody to attribute them to.
                $this->stats['skipped_unknown_user']++;
                $bar->advance();
                continue;
            }

            $columns = $this->columnsOf($this->dbUsers, $username);

            $rows = DB::table("{$this->dbUsers}." . $this->safeIdentifier($username))->get();

            foreach ($rows as $row) {
                $link = isset($row->setLink) ? trim((string) $row->setLink) : '';

                if ($link === '' || ! $this->looksLikeSetCode($link)) {
                    continue;
                }

                // A shared set appears in both users' tables pointing at the
                // same card table. Import it once, owned by whoever holds it
                // with givenBy NULL; the other rows become set_user entries.
                $givenBy = ($columns->contains('givenBy') && isset($row->givenBy))
                    ? trim((string) $row->givenBy)
                    : '';

                if (isset($this->setMap[$link])) {
                    if ($givenBy !== '') {
                        $this->linkUserToSet($ownerId, $this->setMap[$link]);
                    }
                    $this->stats['skipped_duplicate_set']++;
                    continue;
                }

                if ($givenBy !== '') {
                    // Received copy encountered before the original. Record
                    // the relationship, import the set under the receiver
                    // only if the original never shows up (handled by the
                    // duplicate branch above on a later pass).
                    $ownerForRow = $this->userMap[mb_strtolower($givenBy)] ?? $ownerId;
                } else {
                    $ownerForRow = $ownerId;
                }

                if (! $this->cardTableExists($link)) {
                    // Registry row pointing at a card table that no longer
                    // exists — a set deleted without cleaning up its index.
                    $this->stats['skipped_missing_cards']++;
                    continue;
                }

                $setId = DB::table('study_sets')->insertGetId([
                    'slug'        => $this->uniqueSlug(),
                    'legacy_slug' => $link,
                    'user_id'     => $ownerForRow,
                    'title'       => Str::limit(trim((string) ($row->setName ?? 'Untitled set')), 120, ''),
                    'description' => $columns->contains('notes') && ! empty($row->notes)
                        ? trim((string) $row->notes)
                        : null,
                    // Everything imports private. Republishing is a decision
                    // for whoever owns the account, and nobody owns these.
                    'visibility'  => 'private',
                    'cards_count' => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                $this->setMap[$link] = $setId;
                $this->stats['sets']++;

                $this->importCards($link, $setId);

                if ($ownerForRow !== $ownerId) {
                    $this->linkUserToSet($ownerId, $setId);
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function importCards(string $link, int $setId): void
    {
        $rows = DB::table("{$this->dbCustom}." . $this->safeIdentifier($link))
            ->orderBy('termId')
            ->get();

        $position = 0;
        $batch = [];

        foreach ($rows as $row) {
            $term = trim((string) ($row->termName ?? ''));
            $def  = trim((string) ($row->termDef ?? ''));

            if ($term === '' && $def === '') {
                continue;
            }

            $batch[] = [
                'study_set_id' => $setId,
                'position'     => $position++,
                'term'         => $term,
                'definition'   => $def,
                'hint'         => null,
                'times_tested' => (int) ($row->numTested ?? 0),
                'times_wrong'  => (int) ($row->numWrong ?? 0),
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        if ($batch !== []) {
            foreach (array_chunk($batch, 500) as $chunk) {
                DB::table('cards')->insert($chunk);
            }
            $this->stats['cards'] += count($batch);
        }
    }

    // -----------------------------------------------------------------
    // Sharing
    // -----------------------------------------------------------------

    /**
     * cpSetData.<abCode> holds one row per person a set was shared with.
     * Only sets that actually imported get their shares carried over.
     */
    private function importShares(): void
    {
        $existing = collect($this->tablesIn($this->dbSetData))->flip();

        $bar = $this->output->createProgressBar(count($this->setMap));

        foreach ($this->setMap as $link => $setId) {
            if (! $existing->has($link)) {
                $bar->advance();
                continue;
            }

            $rows = DB::table("{$this->dbSetData}." . $this->safeIdentifier($link))->get();

            foreach ($rows as $row) {
                $with = trim((string) ($row->sharedWith ?? ''));
                $userId = $this->userMap[mb_strtolower($with)] ?? null;

                if ($userId !== null) {
                    $this->linkUserToSet($userId, $setId);
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function linkUserToSet(int $userId, int $setId): void
    {
        $owner = DB::table('study_sets')->where('id', $setId)->value('user_id');

        if ((int) $owner === $userId) {
            return;   // owning it is not the same as saving it
        }

        DB::table('set_user')->insertOrIgnore([
            'user_id'      => $userId,
            'study_set_id' => $setId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->stats['shares']++;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function recountSets(): void
    {
        DB::statement('
            UPDATE study_sets s
            SET cards_count = (SELECT COUNT(*) FROM cards c WHERE c.study_set_id = s.id)
        ');
    }

    /** Per-user set tables: everything in cpUsers except the system tables. */
    private function userSetTables(): array
    {
        return array_values(array_filter(
            $this->tablesIn($this->dbUsers),
            fn (string $t) => ! in_array($t, self::NOT_USER_TABLES, true)
        ));
    }

    private function tablesIn(string $schema): array
    {
        return DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $schema)
            ->pluck('TABLE_NAME')
            ->all();
    }

    private function columnsOf(string $schema, string $table)
    {
        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $schema)
            ->where('TABLE_NAME', $table)
            ->pluck('COLUMN_NAME');
    }

    private function cardTableExists(string $link): bool
    {
        static $cache = null;

        if ($cache === null) {
            $cache = collect($this->tablesIn($this->dbCustom))->flip();
        }

        return $cache->has($link);
    }

    /**
     * Table names come from data, so they are interpolated into SQL. Both
     * sides are constrained: names originate from information_schema, and
     * anything reaching a query string must match this pattern first.
     */
    private function looksLikeSetCode(string $s): bool
    {
        return (bool) preg_match('/^ab\d{9,13}$/', $s);
    }

    /**
     * Table names come from data, so they end up inside table references.
     * Both sides are constrained: names originate from information_schema,
     * and anything reaching a query must match this pattern first.
     *
     * Returns the bare name — Laravel's query builder adds the backticks. Do
     * not add them here too, or you get ```Name``` and a 1146.
     */
    private function safeIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $identifier)) {
            throw new \RuntimeException("Refusing to interpolate identifier: {$identifier}");
        }

        return $identifier;
    }

    private function uniqueSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(12));
        } while (DB::table('study_sets')->where('slug', $slug)->exists());

        return $slug;
    }

    private function legacyDatabasesPresent(): bool
    {
        $found = DB::table('information_schema.SCHEMATA')
            ->whereIn('SCHEMA_NAME', [$this->dbUsers, $this->dbCustom, $this->dbSetData])
            ->count();

        return $found === 3;
    }

    private function confirmDestructive(): bool
    {
        $existing = DB::table('users')->count() + DB::table('study_sets')->count();

        if ($existing === 0) {
            return true;
        }

        return $this->confirm(
            "The target database already holds {$existing} users/sets. Import anyway?",
            false
        );
    }

    private function report(): void
    {
        $this->newLine();
        $this->table(
            ['metric', 'count'],
            collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values()->all()
        );
    }
}

/** Thrown to roll back a dry run. Caught in handle() via the transaction. */
class DryRunComplete extends \RuntimeException {}
