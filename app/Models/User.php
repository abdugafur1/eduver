<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Exceptions\ProtectedAccountException;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * users.total_points and users.credits are denormalised balances, one per
     * currency in point_transactions — which is what that `currency` column is
     * for. Same relationship as study_sets.cards_count to cards.
     */
    public const BALANCE_COLUMNS = [
        PointTransaction::CURRENCY_POINTS => 'total_points',
        PointTransaction::CURRENCY_CREDITS => 'credits',
    ];

    /**
     * A closed account keeps its row: every FK pointing at it is
     * cascadeOnDelete, and the ledger is append-only. What goes is the PII.
     *
     * Both username and email are unique and email is NOT NULL, so neither can
     * be blanked — they have to be overwritten with synthetic values. .invalid
     * is reserved by RFC 2606, so the address can never collide with a real
     * one or accidentally receive a password reset.
     *
     * Registration validation must reject this prefix, or a squatter can make
     * some future user's deletion fail on a unique constraint. tombstone-
     * Username() retries anyway, but the retry is a backstop, not the guard.
     */
    public const TOMBSTONE_PREFIX = 'deleted_user_';

    public const TOMBSTONE_EMAIL_DOMAIN = 'deleted.invalid';

    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'timezone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'timezone' => 'UTC',
        'total_points' => 0,
        'credits' => 0,
        'xp' => 0,
        'level' => 1,
        'current_streak' => 0,
        'longest_streak' => 0,
        'must_reset_password' => false,
        'is_protected' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'total_points' => 'integer',
            'credits' => 'integer',
            'xp' => 'integer',
            'level' => 'integer',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'last_active_on' => 'date',
            'must_reset_password' => 'boolean',
            'is_protected' => 'boolean',
        ];
    }

    /** username is unique varchar(30) — better public URLs than an id. */
    public function getRouteKeyName(): string
    {
        return 'username';
    }

    public function studySets(): HasMany
    {
        return $this->hasMany(StudySet::class);
    }

    public function quizSessions(): HasMany
    {
        return $this->hasMany(QuizSession::class);
    }

    /**
     * The set_user pivot: sets you've saved, studied, or both.
     *
     * created_at means "you put this in your library" and last_studied_at
     * means "you worked on it" — a row can have either without the other, so
     * neither column alone is the sort key. See StudySetController::saved().
     *
     * Trashed sets drop out for free: this is a StudySet query underneath, so
     * SoftDeletes' global scope applies. The orphaned pivot rows a soft delete
     * leaves behind (the FK cascade never fires) stay in the table but can't
     * reach a page through here. Anything reading set_user directly still
     * needs its own guard.
     */
    public function savedSets(): BelongsToMany
    {
        return $this->belongsToMany(StudySet::class, 'set_user')
            ->withPivot('last_studied_at')
            ->withTimestamps();
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function dailyActivities(): HasMany
    {
        return $this->hasMany(DailyActivity::class);
    }

    /** Everyone whose password was scrambled by the anonymised import. */
    public function scopeMustResetPassword(Builder $query): Builder
    {
        return $query->where('must_reset_password', true);
    }

    /** Rides users_total_points_index / users_level_xp_index. */
    public function scopeLeaderboard(Builder $query, string $by = 'total_points'): Builder
    {
        return match ($by) {
            'xp' => $query->orderByDesc('level')->orderByDesc('xp'),
            default => $query->orderByDesc('total_points'),
        };
    }

    /** The fast read: denormalised column, no ledger touched. */
    public function balance(string $currency = PointTransaction::CURRENCY_POINTS): int
    {
        return (int) $this->{self::BALANCE_COLUMNS[$currency]};
    }

    /** Newest ledger row's running snapshot — one indexed seek. */
    public function ledgerSnapshot(string $currency = PointTransaction::CURRENCY_POINTS): int
    {
        return (int) $this->pointTransactions()
            ->currency($currency)
            ->latestFirst()
            ->value('balance_after');
    }

    /** SUM(amount) — the slow source of truth. */
    public function ledgerTotal(string $currency = PointTransaction::CURRENCY_POINTS): int
    {
        return (int) $this->pointTransactions()->currency($currency)->sum('amount');
    }

    /**
     * Three independent records of the same number: the users column, the
     * newest balance_after, and the sum of amounts. They should agree. Where
     * they don't, the import dropped or duplicated ledger rows for that user.
     *
     * @return array{column:int, snapshot:int, sum:int, agrees:bool}
     */
    public function balanceAudit(string $currency = PointTransaction::CURRENCY_POINTS): array
    {
        $column = $this->balance($currency);
        $snapshot = $this->ledgerSnapshot($currency);
        $sum = $this->ledgerTotal($currency);

        return [
            'column' => $column,
            'snapshot' => $snapshot,
            'sum' => $sum,
            'agrees' => $column === $snapshot && $snapshot === $sum,
        ];
    }

    /** Today's calendar date in this user's own timezone. */
    public function today(): string
    {
        return DailyActivity::dateFor($this);
    }

    /**
     * Sets that go when this account closes: everything the user owns that
     * isn't public and that nobody else holds — "holds" meaning a set_user row
     * or a quiz_sessions row belonging to someone other than the owner.
     *
     * One rule, all three visibilities. Private goes. Public is kept whether
     * or not anyone holds it, because it is part of the public library and its
     * cards carry aggregate times_tested / times_wrong that every studier
     * contributed to. Unlisted is kept only if it actually reached someone: an
     * unlisted set nobody ever saved or studied is functionally private, and
     * there is no third party to protect by keeping it.
     *
     * The rule also absorbs the awkward case — a set that was public, was
     * saved by fifty people, then flipped to private. Clause two protects it,
     * so closing the account doesn't empty it out of fifty libraries.
     *
     * withTrashed() deliberately: a set the user already soft-deleted is still
     * their content, and leaving it behind would be the 1,125 dedupe shells
     * all over again.
     */
    public function disposableSets(): Builder
    {
        $id = $this->getKey();

        return StudySet::withTrashed()
            ->where('user_id', $id)
            // Grouped, or the orWhere escapes the user_id constraint and this
            // starts disposing of other people's sets.
            //
            // A set the user already trashed is disposable whatever its
            // visibility: it is unreachable through every scoped path, and
            // keeping it would make their own deletion permanent by way of
            // them leaving. The holder clauses below still apply — deliberately
            // conservative, since a trashed set's set_user rows are already
            // orphaned and arguably protect nothing.
            ->where(fn (Builder $q) => $q
                ->where('visibility', '!=', StudySet::VISIBILITY_PUBLIC)
                ->orWhereNotNull('deleted_at'))
            ->whereDoesntHave('savers', fn (Builder $q) => $q->whereKeyNot($id))
            ->whereDoesntHave('quizSessions', fn (Builder $q) => $q
                ->where('quiz_sessions.user_id', '!=', $id));
    }


    /**
     * Close this account: destroy what only this user has, overwrite the PII,
     * and leave the row in place so every FK pointing at it stays valid.
     *
     * MUST run inside a transaction — see ProfileController::destroy(). It is
     * not one internally because the caller also has to tear down the session
     * afterwards, and that must happen only if this committed.
     *
     * What is deliberately kept:
     *   - point_transactions, in both currencies. Append-only by design, and
     *     balanceAudit() still has to agree afterwards, which is why
     *     total_points and credits are NOT zeroed. Zeroing the columns while
     *     the ledger rows survive breaks all three legs against each other.
     *   - daily_activities. Same reasoning, and they carry no PII.
     *   - quiz_sessions on retained sets. StudySet::scopeSort('active') counts
     *     them, so deleting them would silently re-order every public set this
     *     user ever studied.
     */
    public function closeAccount(): void
    {
        // On the model, not only in the controller: a command or a future
        // admin screen calling this directly must hit the same wall.
        if ($this->is_protected) {
            throw ProtectedAccountException::for($this);
        }
        
        // Bulk force delete: no model events, which is fine because cards,
        // quiz_questions and set_user all cascade at the database level and
        // nothing needs a counter reconciled on a row that is going away.
        $this->disposableSets()->forceDelete();

        // Their library — a record of what they studied, so it is their data.
        // Nothing in the FK graph removes it once the user row survives.
        $this->savedSets()->detach();

        // sessions.user_id has no FK at all, so nothing cascades here.
        // Scrambling the password does not invalidate an existing cookie:
        // without this, a browser they're still signed in on stays
        // authenticated as the tombstone.
        DB::table('sessions')->where('user_id', $this->getKey())->delete();

        $username = $this->tombstoneUsername();

        $this->forceFill([
            'username' => $username,
            'name' => 'Deleted user',
            // Derived from the username, so it inherits its uniqueness.
            'email' => $username.'@'.self::TOMBSTONE_EMAIL_DOMAIN,
            'email_verified_at' => null,
            // The 'hashed' cast turns this into a bcrypt hash of a string
            // nobody holds. Not a marker: must_reset_password already means
            // "this password is garbage" for every imported account.
            'password' => Str::random(64),
            'remember_token' => null,
            // The last thread back to a real person on the old site.
            'legacy_id' => null,
            'timezone' => 'UTC',
        ])->save();

        // Soft: writes deleted_at and touches nothing else. The global scope
        // takes it out of the leaderboard, out of StudySet::scopeSearch()'s
        // author clause, and out of the auth provider's credential lookup.
        $this->delete();
    }

    /**
     * deleted_user_{id} unless somebody is sitting on it, in which case a
     * random suffix, the same shape as StudySet::uniqueSlug(). Fits varchar(30)
     * for any plausible id: 13 + 7 digits + 5 = 25.
     */
    private function tombstoneUsername(): string
    {
        $base = self::TOMBSTONE_PREFIX.$this->getKey();
        $username = $base;

        while (static::withTrashed()
            ->where('username', $username)
            ->whereKeyNot($this->getKey())
            ->exists()
        ) {
            $username = $base.'_'.Str::lower(Str::random(4));
        }

        return $username;
    }

    /** Whether this row is a closed account rather than a person. */
    public function isTombstone(): bool
    {
        return str_starts_with($this->username, self::TOMBSTONE_PREFIX);
    }
}
