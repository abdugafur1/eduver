<?php

namespace App\Services;

use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The only place currency is minted.
 *
 * Extracted from QuizRewards::award() when quests became a second payout
 * path. balanceAudit() checks three independent records of the same number —
 * users.total_points, the newest balance_after, and SUM(amount) — and they
 * only agree if every writer does the same two things in the same order
 * inside one transaction. Two hand-rolled copies of that is how they drift.
 *
 * CALLING CONTRACT: must run inside a transaction with $user already locked
 * via lockForUpdate(). balance_after is a running snapshot read off the user
 * row, so two unlocked payouts settling at once read the same starting
 * balance and write the same balance_after. This class can't take the lock
 * itself — the caller usually needs the same lock held across other writes.
 */
class Ledger
{
    /**
     * Write one ledger row and move the matching denormalised column.
     *
     * $amount is signed: a negative is a spend, and balance_after follows it
     * down. Nothing spends yet, but the column is int(11) rather than
     * unsigned for exactly that reason.
     */
    public function credit(
        User $user,
        int $amount,
        string $reason,
        string $currency = PointTransaction::CURRENCY_POINTS,
        ?Model $source = null,
    ): PointTransaction {
        // Throws on an unknown currency rather than silently paying into
        // nothing — the map is the list of currencies that actually exist.
        $column = User::BALANCE_COLUMNS[$currency];

        // source is polymorphic. If AppServiceProvider registers a morph map,
        // source_type stores the alias rather than the FQCN — either way
        // morphTo() resolves it, so nothing here needs to know which.
        $transaction = $user->pointTransactions()->create([
            'currency' => $currency,
            'amount' => $amount,
            'reason' => $reason,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'balance_after' => (int) $user->{$column} + $amount,
        ]);

        // Same transaction as the row it summarises. increment() also updates
        // the in-memory attribute, so a second credit() call on the same
        // instance reads the new balance rather than the stale one.
        $user->increment($column, $amount);

        return $transaction;
    }
}
