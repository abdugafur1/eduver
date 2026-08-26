{{--
    The set grid: tiles, the paper-stack depth effect, and pagination.

    Included by sets/index and sets/mine. The CSS travels with the markup
    because @@push fires from inside an include — the styles land on whichever
    page uses the partial, and neither page has to remember to carry them.
    This is the same reasoning that moved .sort into the layout, applied one
    level down: shared by two pages, but not by every page.

    Expects:
      $sets       a paginator of StudySet
      $showOwner  optional, default true. False on sets/mine, where every tile
                  has the same owner and naming them is noise — and where
                  leaving it on would mean eager-loading user just to print
                  the viewer's own name 24 times.

    Renders nothing for an empty paginator: each page writes its own empty
    state, because "nothing matches that search" and "you haven't made a set
    yet" are different messages.
--}}
@push('styles')
<style>
    /* ---------- the grid ----------
       Each tile sits on a stack of paper sheets whose thickness is derived from
       the real card count, so a 200-card set reads as physically heavier than a
       6-card one before you've read a word of it. --d comes from stackDepth(). */

    .grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 50px 45px;              /* .scrollable */
        padding-right: 12px;
    }

    @media (min-width: 800px)  { .grid { grid-template-columns: 1fr 1fr; } }
    @media (min-width: 1400px) { .grid { grid-template-columns: 1fr 1fr 1fr; } }

    .stack { position: relative; }

    /* No shadow on the sheets. Each one carrying its own smudged into the
       tile's hard offset shadow along the right edge; a step-down in fill reads
       as a stacked edge more cleanly and leaves one shadow on the page. */
    .stack::before,
    .stack::after {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 0;
        border-radius: 2.5px;
    }

    .stack::before {
        background: #e7e7e9;
        transform: translate(calc(var(--d) * 2.5px), calc(var(--d) * 2.5px));
    }

    .stack::after {
        background: #e0e0e3;
        transform: translate(calc(var(--d) * 5px), calc(var(--d) * 5px));
    }

    .stack[data-depth="0"]::before,
    .stack[data-depth="0"]::after,
    .stack[data-depth="1"]::after { display: none; }

    .tile {
        /* z-index is load-bearing: ::after is the last child of .stack, so
           without it the opaque back sheet paints over the tile and hides
           every word of content. */
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        gap: 10px;
        height: 100%;
        min-height: 160px;          /* .scrollable grid-auto-rows */
        padding: 18px 18px 16px;
        background: var(--tile);    /* #eeeeee, a hair darker than the page */
        border: 0;
        border-radius: 2.5px;       /* .menu-div, verbatim */
        text-decoration: none;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        transition: color 120ms ease;
    }

    /* The live hover is a colour change on the link, not a lift — .menu-div a
       goes #0064ff -> #c864c8. The offset shadow stays put, which is what makes
       the tile feel printed rather than floating. */
    .tile:hover .tile__title,
    .tile:focus-visible .tile__title { color: var(--brand-b); }

    /* Blue, like the tiles on /user/index — the title is the link, and this is
       the only signal that says so once the pine underline colour is gone. */
    .tile__title {
        font-family: var(--display);
        font-weight: 500;
        font-size: 19px;
        line-height: 1.25;
        letter-spacing: -0.012em;
        /* auto top margin pairs with the footer's, so on a set with no
           description the title sits centred in the tile rather than stranded
           at the top of a 160px box. .set-name centres the same way. */
        margin: auto 0 0;
        color: var(--brand-a);       /* .set-name */
        transition: color 120ms ease;
    }

    .tile__desc {
        margin: 0;
        font-size: 15px;
        line-height: 1.5;
        color: var(--ink-soft);
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .tile__foot {
        margin-top: auto;
        padding-top: 12px;
        border-top: 1px solid var(--rule);
        display: flex;
        align-items: baseline;
        gap: 10px;
    }

    /* Was pine. Stays neutral now that the title is blue — two blues in one
       tile and neither one is the thing you click. */
    .tile__cards {
        font-family: var(--mono);
        font-weight: 500;
        font-variant-numeric: tabular-nums;
        font-size: 13px;
        color: var(--ink-soft);
    }

    .tile__owner {
        margin-left: auto;
        font-family: var(--mono);
        font-size: 11px;
        color: var(--ink-soft);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 45%;
    }

    .visibility-flag {
        font-family: var(--mono);
        font-size: 10px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--gold);
    }

    .starter-badge {
        font-family: var(--mono);
        font-size: 10px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--ink-soft);
    }
</style>
@endpush

<div class="grid">
    @foreach ($sets as $set)
        @php $depth = $set->stackDepth(); @endphp
        <div class="stack" data-depth="{{ $depth }}" style="--d: {{ $depth }}">
            <a class="tile" href="{{ route('sets.show', $set) }}">
                <h2 class="tile__title">{{ $set->title }}</h2>

                @if (filled($set->description))
                    <p class="tile__desc">{{ $set->description }}</p>
                @endif

                <div class="tile__foot">
                    <span class="tile__cards">
                        {{ number_format($set->cards_count) }} {{ Str::plural('card', $set->cards_count) }}
                    </span>

                    @if (($set->quiz_sessions_count ?? 0) > 0)
                        <span class="count">{{ number_format($set->quiz_sessions_count) }} studied</span>
                    @endif

                    @if ($set->isStarterPack())
                        <span class="starter-badge">Starter</span>
                    @endif

                    @unless ($set->isPublic())
                        <span class="visibility-flag">{{ $set->visibility }}</span>
                    @endunless

                    {{-- Defaulted here rather than in an inline at-php at the
                         top: the inline form with an expression is gone in
                         Laravel 13, and it fails as a swallowed block rather
                         than as a clear error. --}}
                    @if ($showOwner ?? true)
                        <span class="tile__owner">{{ $set->author?->name ?? 'Unknown' }}</span>
                    @endif
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="pagination">
    {{ $sets->links('vendor.pagination.eduver') }}
</div>
