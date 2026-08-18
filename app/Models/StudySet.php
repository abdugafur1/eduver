<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int    $cards_count  Denormalised counter column, NOT a withCount()
 *                                alias. Never call withCount('cards') on this
 *                                model — the alias would shadow the real column.
 */
class StudySet extends Model
{
    use HasFactory, SoftDeletes;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_UNLISTED = 'unlisted';

    public const VISIBILITY_PRIVATE = 'private';

    /**
     * How the set came to exist. The legacy import wrote 'starterPack' into
     * description for the five sets every signup received; this column is
     * where that belongs. RECEIVED is declared but unpopulated until the
     * legacy `givenBy` sharing data is imported.
     */
    public const SOURCE_AUTHORED = 'authored';

    public const SOURCE_STARTER = 'starter';

    public const SOURCE_RECEIVED = 'received';

    /** Sort key => label shown in the browser. */
    public const SORTS = [
        'recent'  => 'Newest',
        'largest' => 'Most cards',
        'active'  => 'Most studied',
        'alpha'   => 'A–Z',
    ];

    protected $fillable = [
        'slug',
        'user_id',
        'title',
        'description',
        'visibility',
        'source',
    ];

    protected $attributes = [
        'visibility' => self::VISIBILITY_PRIVATE,
        'source' => self::SOURCE_AUTHORED,
        'cards_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'cards_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Also resolves legacy_slug so inbound links from the old site still land.
     * The closure grouping matters: SoftDeletes has already applied a
     * `deleted_at is null` constraint, and a bare orWhere would escape it.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this
            ->where(fn (Builder $q) => $q->where('slug', $value)->orWhere('legacy_slug', $value))
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function quizSessions(): HasMany
    {
        return $this->hasMany(QuizSession::class);
    }

    /**
     * The other side of User::savedSets(). Used to answer "have I saved this?"
     * on the set page without a raw pivot query.
     */
    public function savers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'set_user')
            ->withPivot('last_studied_at')
            ->withTimestamps();
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    public function isStarterPack(): bool
    {
        return $this->source === self::SOURCE_STARTER;
    }

    /**
     * 12 lowercase alphanumerics in a 22-char column. Random rather than a
     * title slug: titles collide constantly ("GRE Vocab"), and a random key
     * means the URL doesn't change when the title does.
     */
    public static function uniqueSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(12));
        } while (static::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }



    /**
     * Hides the ~1,130 starter-pack clones. Without this the browser shows
     * copies of the same five sets outnumbering real content four to one.
     */
    public function scopeExcludingStarter(Builder $query): Builder
    {
        return $query->where('source', '!=', self::SOURCE_STARTER);
    }

    /**
     * Listable in the browser: public only, plus the viewer's own sets.
     * Unlisted sets are deliberately excluded — reachable by direct link,
     * not by browsing. See scopeReadableBy() for the show() counterpart.
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer = null): Builder
    {
        return $query->where(function (Builder $q) use ($viewer) {
            $q->where('visibility', self::VISIBILITY_PUBLIC);

            if ($viewer) {
                $q->orWhere('user_id', $viewer->getKey());
            }
        });
    }

    public function isReadableBy(?User $viewer = null): bool
    {
        if (in_array($this->visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_UNLISTED], true)) {
            return true;
        }

        return $viewer !== null && $viewer->getKey() === $this->user_id;
    }

    /**
     * Matches title, description, or owner name. LIKE is fine at ~1.4k rows;
     * switch to a FULLTEXT index on (title, description) if that grows an
     * order of magnitude.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('name', 'like', $like)
                    ->orWhere('username', 'like', $like));
        });
    }

    /**
     * `recent` rides study_sets_visibility_created_at_index. `active` orders by
     * the withCount() alias, so the caller must apply withCount('quizSessions').
     */
    public function scopeSort(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'largest' => $query->orderByDesc('cards_count')->orderBy('title'),
            'active'  => $query->orderByDesc('quiz_sessions_count')->orderByDesc('id'),
            'alpha'   => $query->orderBy('title')->orderBy('id'),
            default   => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * Reconcile the counter column against reality. Worth running once after
     * the import, and from Card's saved/deleted events once editing exists.
     */
    public function recountCards(): int
    {
        $count = $this->cards()->count();

        $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->update(['cards_count' => $count]);

        return $this->cards_count = $count;
    }

    /** How thick the card stack renders in the browser grid (0–3). */
    public function stackDepth(): int
    {
        return match (true) {
            $this->cards_count >= 100 => 3,
            $this->cards_count >= 40  => 2,
            $this->cards_count >= 12  => 1,
            default                   => 0,
        };
    }
}
