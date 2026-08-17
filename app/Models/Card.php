<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    use HasFactory;

    protected $fillable = [
        'study_set_id',
        'position',
        'term',
        'definition',
        'hint',
    ];

    protected $attributes = [
        'position' => 0,
        'times_tested' => 0,
        'times_wrong' => 0,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'times_tested' => 'integer',
            'times_wrong' => 'integer',
        ];
    }

    public function studySet(): BelongsTo
    {
        return $this->belongsTo(StudySet::class);
    }

    public function quizQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }

    /**
     * Matches cards_study_set_id_position_index exactly, so the set detail page
     * pages without a filesort. position is NOT NULL DEFAULT 0, so id is only
     * breaking ties among the legacy rows that never got ordered.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('term', 'like', $like)
                ->orWhere('definition', 'like', $like)
                ->orWhere('hint', 'like', $like);
        });
    }

    /**
     * Share of attempts answered wrong, or null when too few attempts for the
     * number to mean anything.
     */
    public function missRate(int $minAttempts = 5): ?float
    {
        if ($this->times_tested < $minAttempts) {
            return null;
        }

        return $this->times_wrong / $this->times_tested;
    }

    /** Cards the legacy userbase reliably got wrong. */
    public function scopeTroublesome(Builder $query, float $threshold = 0.4, int $minAttempts = 5): Builder
    {
        return $query
            ->where('times_tested', '>=', $minAttempts)
            ->whereRaw('times_wrong / times_tested >= ?', [$threshold]);
    }

    protected static function booted(): void
    {
        // created and deleted only, never saved: QuizController writes
        // times_tested and times_wrong on every answer, and recounting on
        // `saved` would fire a COUNT query per question answered.
        static::created(fn (Card $card) => $card->studySet?->recountCards());
        static::deleted(fn (Card $card) => $card->studySet?->recountCards());
    }
}
