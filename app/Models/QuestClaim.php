<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quest paid out, once, for one user on one local day.
 *
 * Rows are written by App\Services\Quests::claim() and never updated — the
 * unique on (user_id, quest_key, activity_date) is what makes claiming
 * accept-once, so anything that edits a row after the fact defeats it.
 */
class QuestClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quest_key',
        'activity_date',
        'reward',
        'currency',
    ];

    protected $attributes = [
        'currency' => PointTransaction::CURRENCY_CREDITS,
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'reward' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * whereDate() rather than a plain where, for the reason DailyActivity's
     * accrue() spells out: the 'date' cast serialises through the model's
     * datetime format on write, so an equality match on 'Y-m-d' can miss on a
     * driver with no native DATE type. Casting the column in SQL matches
     * everywhere.
     */
    public function scopeOnDate(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('activity_date', $date);
    }
}
