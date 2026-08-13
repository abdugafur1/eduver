<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An append-only ledger, and two things about it change how you must read it:
 *
 * 1. `currency` means this is NOT a single-balance table. Any sum or balance
 *    read that doesn't filter by currency is mixing units.
 * 2. `balance_after` is a running snapshot, so the current balance is the
 *    newest row's value — not SUM(amount). point_transactions_user_id_currency
 *    _created_at_index exists to make exactly that lookup one seek.
 */
class PointTransaction extends Model
{
    use HasFactory;

    /**
     * users.total_points and users.credits are the denormalised balances for
     * these two, which is strong evidence they're the whole set. Confirm:
     *
     *   SELECT currency, COUNT(*) FROM point_transactions GROUP BY currency;
     */
    public const CURRENCY_POINTS = 'points';

    public const CURRENCY_CREDITS = 'credits';

    protected $fillable = [
        'user_id',
        'currency',
        'amount',
        'reason',
        'source_type',
        'source_id',
        'balance_after',
    ];

    protected $attributes = [
        'currency' => self::CURRENCY_POINTS,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whatever caused the transaction — QuizSession, DailyActivity, etc.
     *
     * If source_type holds short aliases ('quiz_session') rather than FQCNs,
     * register a morph map in AppServiceProvider::boot() instead of renaming
     * the data:
     *
     *   Relation::enforceMorphMap([
     *       'quiz_session' => QuizSession::class,
     *       'daily_activity' => DailyActivity::class,
     *   ]);
     *
     * Check with: SELECT DISTINCT source_type FROM point_transactions;
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeCurrency(Builder $query, string $currency = self::CURRENCY_POINTS): Builder
    {
        return $query->where('currency', $currency);
    }

    public function scopeReason(Builder $query, string $reason): Builder
    {
        return $query->where('reason', $reason);
    }

    public function scopeEarned(Builder $query): Builder
    {
        return $query->where('amount', '>', 0);
    }

    public function scopeSpent(Builder $query): Builder
    {
        return $query->where('amount', '<', 0);
    }

    /** Newest first — the ordering the compound index is built for. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
