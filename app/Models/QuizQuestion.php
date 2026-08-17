<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `is_correct` is nullable, and that third state is load-bearing: NULL means
 * the question was issued but never answered, which is different from answered
 * wrong. Anything that treats falsy as "got it wrong" will overcount mistakes
 * on abandoned sessions. Use the scopes rather than raw truthiness.
 */
class QuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_session_id',
        'card_id',
        'position',
        'presented_options',
        'expected_answer',
        'submitted_answer',
        'is_correct',
        'points_awarded',
        'issued_at',
        'expires_at',
        'answered_at',
    ];

    protected $attributes = [
        'points_awarded' => 0,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            // longtext + utf8mb4_bin is the JSON column signature on MariaDB.
            'presented_options' => 'array',
            'is_correct' => 'boolean',
            'points_awarded' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function quizSession(): BelongsTo
    {
        return $this->belongsTo(QuizSession::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** Rides quiz_questions_quiz_session_id_position_unique. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position');
    }

    /** Rides quiz_questions_quiz_session_id_answered_at_index. */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->whereNotNull('answered_at');
    }

    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->whereNull('answered_at');
    }

    public function scopeCorrect(Builder $query): Builder
    {
        return $query->where('is_correct', true);
    }

    /** Answered and wrong. SQL `= false` already excludes the NULL rows. */
    public function scopeMissed(Builder $query): Builder
    {
        return $query->where('is_correct', false);
    }

    /** Timed out before an answer landed. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNull('answered_at')->where('expires_at', '<=', now());
    }

    /**
     * True/false rows store a different shape in presented_options:
     * ['claim' => string, 'options' => ['True', 'False']]. Multiple choice
     * stores a plain list of option strings.
     *
     * The migration sanctions this — presented_options is described as the
     * distractors *and prompt* actually rendered, so a session can be replayed.
     * A true/false question's prompt is the claim, and without storing it the
     * result page could not show what was actually asked.
     */
    public function isTrueFalse(): bool
    {
        return is_array($this->presented_options)
            && array_key_exists('claim', $this->presented_options);
    }

    /**
     * Note: this used to be `filled($presented_options)`, which was correct
     * when multiple choice was the only mode that populated the column.
     * True/false populates it too, so the check now has to exclude that shape.
     */
    public function isMultipleChoice(): bool
    {
        return filled($this->presented_options) && ! $this->isTrueFalse();
    }

    /** The answerable options, whichever shape the row is in. */
    public function options(): array
    {
        if ($this->isTrueFalse()) {
            return $this->presented_options['options'] ?? [];
        }

        return $this->presented_options ?? [];
    }

    /** What a true/false question actually asserted. Null for other modes. */
    public function claim(): ?string
    {
        return $this->isTrueFalse() ? ($this->presented_options['claim'] ?? null) : null;
    }

    public function hasExpired(): bool
    {
        return $this->answered_at === null && $this->expires_at?->isPast();
    }

    /** Seconds between issue and answer, or null if never answered. */
    public function answerLatency(): ?int
    {
        if (! $this->answered_at || ! $this->issued_at) {
            return null;
        }

        return (int) $this->issued_at->diffInSeconds($this->answered_at);
    }
}
