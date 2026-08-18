<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * users.total_points and users.credits are denormalised balances, one per
     * currency in point_transactions — which is what that `currency` column is
     * for. Same relationship as study_sets.cards_count to cards.
     */
    public const BALANCE_COLUMNS = [
        PointTransaction::CURRENCY_POINTS => 'total_points',
        PointTransaction::CURRENCY_CREDITS => 'credits',
    ];

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
}
