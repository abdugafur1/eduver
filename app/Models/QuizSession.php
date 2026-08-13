<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class QuizSession extends Model
{
    use HasFactory;

    /**
     * `mode` is varchar(24) defaulting to multiple_choice, so there are almost
     * certainly others in the data. Confirm and add them:
     *
     *   SELECT mode, COUNT(*) FROM quiz_sessions GROUP BY mode;
     */
    public const MODE_MULTIPLE_CHOICE = 'multiple_choice';

    protected $fillable = [
        'user_id',
        'study_set_id',
        'mode',
        'questions_total',
        'questions_answered',
        'correct_count',
        'points_awarded',
        'started_at',
        'completed_at',
    ];

    protected $attributes = [
        'mode' => self::MODE_MULTIPLE_CHOICE,
        'questions_total' => 0,
        'questions_answered' => 0,
        'correct_count' => 0,
        'points_awarded' => 0,
    ];

    protected function casts(): array
    {
        return [
            'questions_total' => 'integer',
            'questions_answered' => 'integer',
            'correct_count' => 'integer',
            'points_awarded' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studySet(): BelongsTo
    {
        return $this->belongsTo(StudySet::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }

    /** The ledger rows this session generated, via point_transactions.source_*. */
    public function pointTransactions(): MorphMany
    {
        return $this->morphMany(PointTransaction::class, 'source');
    }

    /** Rides quiz_sessions_user_id_completed_at_index when scoped to a user. */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeOfMode(Builder $query, string $mode): Builder
    {
        return $query->where('mode', $mode);
    }

    /**
     * Share of *answered* questions got right. Dividing by questions_total
     * instead would quietly punish abandoned sessions for questions the user
     * never saw — questions_answered is the honest denominator.
     */
    public function accuracy(): ?float
    {
        if ($this->questions_answered < 1) {
            return null;
        }

        return $this->correct_count / $this->questions_answered;
    }

    /** How far through the quiz they got, separate from how well they did. */
    public function completionRate(): ?float
    {
        if ($this->questions_total < 1) {
            return null;
        }

        return $this->questions_answered / $this->questions_total;
    }

    public function durationInSeconds(): ?int
    {
        if (! $this->completed_at || ! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }
}
