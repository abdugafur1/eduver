# eduver

A study platform for creating flashcard sets and learning them through flashcards, multiple-choice, and true/false quizzes, with points, daily quests, streaks, and leaderboards.

**Live:** https://app.eduver.net


## History

I built the first version of eduver at 17 in plain PHP and JavaScript, and it ran with real users for years. In August 2026 I rebuilt it from scratch in Laravel to fix design problems I found when I audited the original: the browser decided whether an answer was correct and then asked the server for points, each user's sets lived in a database table named after them, and a race condition let usernames be duplicated. This repository is the rebuild.

## Stack

- **Backend:** PHP 8.4, Laravel 13, Breeze (authentication)
- **Frontend:** Blade, Tailwind CSS, Alpine.js, Vite
- **Database:** MariaDB / MySQL with InnoDB
- **Testing:** PHPUnit 12, 160+ feature tests, run against both SQLite and MariaDB

## Features

- Create, edit, reorder, and delete study sets and cards
- Study with flashcards, multiple choice, and true/false
- Points, daily quests, streaks, and daily and all-time leaderboards
- Save other users' sets to your library
- Sign in with username or email
- Close your account without breaking sets other people rely on

## Technical decisions

### Quizzes can't be cheated from the browser

In the old site, the browser graded answers and then sent a request to claim points, so a simple script could farm the leaderboard. Now every question is created and stored on the server before it's shown, and the correct answer never reaches the browser. A submission sends the index of the chosen option, and the server grades it against the stored answer. Questions expire after an hour.

### Every write that pays out is idempotent

Answering a question and finishing a quiz both use a conditional `UPDATE ... WHERE answered_at IS NULL` (or `completed_at IS NULL`) rather than read-then-write. If two identical requests arrive at once, only one can claim the row, so a question is never scored twice and a session never pays out twice.

### Points go through an append-only ledger

`App\Services\Ledger` is the only place points are created. Each award writes a `point_transactions` row with a `balance_after` snapshot and updates the user's balance in the same transaction, with the user row locked (`SELECT ... FOR UPDATE`) so concurrent payouts can't read the same starting balance. `User::balanceAudit()` checks that three independent records agree: the balance column, the latest `balance_after`, and the sum of all transactions.

### Earnings are capped per set per day

Each set can pay out up to its card count × 10 points per day, so repeating a small set stops paying once it's earned out. The cap is on points earned, not attempts, so practicing never costs anything. Days are calculated in each user's own timezone.

### Derived values are recomputed, not incremented

Streaks are derived from which days have qualifying activity rather than kept as a counter, so a corrected day is reflected immediately and there's no state to drift. The denormalized `cards_count` column has a reconciliation command, `sets:recount-cards`, that reports drift and only writes with `--force`.

### Accounts are closed by tombstoning, not cascading deletes

Closing an account removes personal data and soft-deletes the user row, but keeps it as a tombstone (`deleted_user_{id}`) so sets other people saved still work and the ledger stays intact. Active sessions for that user are deleted at the same time.

### The database is configured to actually enforce integrity

The production server defaults to MyISAM, which supports neither transactions nor foreign keys, so the app pins InnoDB. Constraints like unique usernames and one library entry per user per set are enforced with unique indexes, and the leaderboard and browse queries have composite indexes built for them.

## Migrating the legacy data

`php artisan eduver:import` moves the old site's data into the new schema.

- **Ownership was encoded in table names.** Each user's sets lived in a table named after their username, so the import discovers tables through `information_schema` instead of running a few `INSERT ... SELECT` statements.
- **It found a race condition in the old signup flow.** Username uniqueness was checked in PHP rather than enforced by the database, so two simultaneous signups could both succeed. 27 usernames had been duplicated across 79 rows. The new schema enforces uniqueness with a unique index.
- **Personal data never crosses over.** The script never reads emails, password hashes, or real names. Each legacy account becomes a placeholder user with an unroutable `.invalid` email address.
- **Dry runs are real.** `--dry-run` runs the full import inside a transaction and then rolls it back, so the reported counts are accurate and nothing is written.
- **Table names from data are validated** against a strict pattern before they reach any query.

## Running locally

```bash
composer install
cp .env.example .env
php artisan key:generate
# set DB_USERNAME and DB_PASSWORD in .env
php artisan migrate
php artisan db:seed      # creates a test user: test@example.com / password
npm install && npm run build
php artisan serve
```

Run the tests:

```bash
php artisan test                                  # SQLite
php artisan test --configuration=phpunit.mariadb.xml   # MariaDB
```
