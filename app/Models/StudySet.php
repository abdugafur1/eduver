<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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

    /**
     * The pivot ordering on sets.saved: COALESCE(last_studied_at, created_at),
     * i.e. what you touched last. Not in SORTS because it can only be
     * expressed on a query joined to set_user — selecting it on browse would
     * be a missing-column error.
     */
    public const SORT_LIBRARY = 'library';

    /**
     * The strip on sets.saved. A subset of SORTS with the pivot order in
     * front, which is what lets that page offer a sort at all: its default
     * ordering now has a name, so it can be listed like everything else
     * rather than being the one option not on the strip.
     *
     * `active` is deliberately absent. It counts everyone's completed
     * sessions, so next to a library sorted by your own activity it would be
     * two different meanings of "most studied" side by side. `recent` is out
     * for a smaller reason: when a set was authored says nothing about your
     * relationship to it, which is all this page is about.
     */
    public const LIBRARY_SORTS = [
        self::SORT_LIBRARY => 'Recently active',
        'alpha'            => 'A–Z',
        'largest'          => 'Most cards',
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

    /**
     * The author including tombstones. user() keeps the global scope, so a
     * closed account resolves to null there — which is what scopeSearch()
     * wants, since orWhereHas('user') would otherwise make "deleted" match
     * every orphaned set. Views bind here instead: closeAccount() writes
     * 'Deleted user' into name precisely so it can be shown.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
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
     *
     * Every column is qualified, because sets.saved applies this scope on top
     * of the set_user join and that pivot carries a created_at of its own —
     * unqualified, `recent` is an ambiguous column error rather than a sort.
     * quiz_sessions_count stays bare: it's a select alias, not a column.
     */
    public function scopeSort(Builder $query, ?string $sort): Builder
    {
        $table = $query->getModel()->getTable();

        return match ($sort) {
            'largest' => $query->orderByDesc("{$table}.cards_count")->orderBy("{$table}.title"),
            'active'  => $query->orderByDesc('quiz_sessions_count')->orderByDesc("{$table}.id"),
            'alpha'   => $query->orderBy("{$table}.title")->orderBy("{$table}.id"),
            default   => $query->orderByDesc("{$table}.created_at")->orderByDesc("{$table}.id"),
        };
    }

    /**
     * What cards_count is supposed to equal. Correlated on the outer table by
     * name rather than an alias, because `UPDATE study_sets s SET ...` is a
     * MySQL spelling and this statement also has to run under the test driver.
     */
    private const ACTUAL_CARDS = '(select count(*) from cards where cards.study_set_id = study_sets.id)';

    /**
     * Reconcile this set's counter against reality. Card's created/deleted
     * hooks call this, which covers every write that goes through Eloquent.
     *
     * Bulk writes don't fire model events, so anything using the query builder
     * — DB::table('cards')->insert(), a mass delete — leaves this column
     * stale and has to reconcile itself. See recountAll().
     */
    public function recountCards(): int
    {
        $count = $this->cards()->count();

        $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->update(['cards_count' => $count]);

        return $this->cards_count = $count;
    }

    /**
     * Sets whose counter disagrees with the cards actually there.
     *
     * Nothing in the app should ever match this. It matching is the symptom of
     * a bulk write that skipped Eloquent, which is why sets:recount-cards
     * reports before it repairs — the number of drifted rows is the
     * interesting part, not the fix.
     */
    public function scopeMiscounted(Builder $query): Builder
    {
        return $query->whereRaw('cards_count <> '.self::ACTUAL_CARDS);
    }

    /**
     * Repair every drifted counter in one statement. Returns rows changed.
     *
     * Trashed sets are excluded by default and it matters which way that
     * defaults: sets:dedupe-starters hard-deletes the cards of the ~1,100
     * copies it drops but only soft-deletes the sets themselves, so every one
     * of those rows is permanently "miscounted" and would bury a real drift
     * under a thousand rows of expected noise.
     *
     * toBase() so this doesn't touch updated_at. Reconciling a counter is not
     * an edit to the set, and CardController::chooseSet() lists by updated_at.
     */
    public static function recountAll(bool $withTrashed = false): int
    {
        $query = static::query()->miscounted();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->toBase()->update([
            'cards_count' => DB::raw(self::ACTUAL_CARDS),
        ]);
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
