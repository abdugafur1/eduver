@extends('layouts.app')

@section('title', 'Browse sets')

@section('masthead')
    <p class="masthead__meta">
        {{ number_format($sets->total()) }} {{ Str::plural('set', $sets->total()) }} in view
    </p>
@endsection

@push('styles')
<style>
    .browse-head { margin-bottom: 28px; }

    /*
      Flat magenta, not a gradient clip. Two reasons: the live site's section
      headings are flat magenta and the gradient stays on chrome; and a clipped
      gradient paints across the element box, so on a block-level h1 the words
      only ever saw the first half of the ramp — all blue, no pink.
      3.2:1 on paper, so weight goes to 400 to give the strokes body, and this
      colour never appears below 24px.
    */
    .browse-head h1 {
        font-family: var(--display);
        font-weight: 400;
        font-size: clamp(30px, 5vw, 46px);
        letter-spacing: -0.022em;
        line-height: 1.08;
        margin: 6px 0 0;
        width: fit-content;
        color: var(--brand-b);
    }

    /* ---------- filters ---------- */

    .filters {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        padding: 14px 0 20px;
        border-bottom: 1px solid var(--rule);
        margin-bottom: 28px;
    }

    .field {
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--tile);
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        padding: 0 12px;
        height: 40px;
    }

    .field:focus-within { border-color: var(--brand-a); }

    .field input {
        border: 0;
        outline: 0;
        background: none;
        font-family: var(--body);
        font-weight: 300;
        font-size: 16px;
        color: var(--ink);
    }

    .field input[type="search"] { width: 260px; max-width: 44vw; }
    .field input[type="number"] { width: 62px; font-family: var(--mono); font-size: 14px; }

    .field label {
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ink-soft);
        white-space: nowrap;
    }

    .starter-toggle {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--ink-soft);
        cursor: pointer;
        user-select: none;
    }

    .starter-toggle input { accent-color: var(--brand-a); width: 14px; height: 14px; }

    .starter-badge {
        font-family: var(--mono);
        font-size: 10px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--ink-soft);
    }

    .sorts {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        margin-left: auto;
    }


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
</style>
@endpush

@section('content')
    <div class="browse-head">
        <p class="eyebrow">Library</p>
        <h1>Browse study sets</h1>
    </div>

    <form class="filters" method="get" action="{{ route('sets.index') }}" role="search">
        <div class="field">
            <input
                type="search"
                name="q"
                value="{{ $q }}"
                placeholder="Search titles, descriptions, authors"
                aria-label="Search study sets"
            >
        </div>

        <div class="field">
            <label for="min">Min cards</label>
            <input type="number" id="min" name="min" min="0" max="1000" value="{{ $min }}">
        </div>

        <input type="hidden" name="sort" value="{{ $sort }}">
        <button class="btn" type="submit">Search</button>

        <label class="starter-toggle">
            <input type="checkbox" name="starter" value="1" @checked($includeStarter)>
            Show starter packs
        </label>

        @if ($q !== '' || $min)
            <a class="btn btn--quiet" href="{{ route('sets.index', array_filter(['sort' => $sort, 'starter' => $includeStarter ? 1 : null])) }}">Clear filters</a>
        @endif

        <div class="sorts">
            @foreach (\App\Models\StudySet::SORTS as $key => $label)
                <a
                    class="sort"
                    href="{{ route('sets.index', array_filter(['q' => $q, 'min' => $min, 'sort' => $key, 'starter' => $includeStarter ? 1 : null])) }}"
                    @if ($sort === $key) aria-current="true" @endif
                >{{ $label }}</a>
            @endforeach
        </div>
    </form>

    @if ($sets->isEmpty())
        <div class="empty">
            <h2>Nothing matches that yet</h2>
            <p>
                @if ($q !== '')
                    Try a shorter search term, or lower the minimum card count.
                @else
                    No sets are visible here. Check what values <code>visibility</code> actually holds.
                @endif
            </p>
        </div>
    @else
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

                            <span class="tile__owner">{{ $set->user?->name ?? 'Unknown' }}</span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>

        <div class="pagination">
            {{ $sets->links('vendor.pagination.eduver') }}
        </div>
    @endif
@endsection
