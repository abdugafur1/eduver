@extends('layouts.app')

@section('title', $set->title.' — terms')

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.show', $set) }}" style="text-decoration: none;">&larr; Back to set</a>
    </p>
@endsection

@push('styles')
<style>
    /* Gold rather than green: the palette has no green, and gold already
       carries "pay attention to this" on visibility flags and miss rates. */
    .flash {
        display: flex;
        align-items: baseline;
        gap: 10px;
        padding: 12px 16px;
        margin-bottom: 22px;
        background: var(--tile);
        border-left: 3px solid var(--gold);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        font-size: 16px;
    }

    /* Stays a solid ink rule. The masthead sweep is 90px above it, and two
       gradient rules that close together stop reading as a signature. */
    .set-head {
        padding-bottom: 22px;
        border-bottom: 2px solid var(--ink);
        margin-bottom: 26px;
    }

    /* Blue, per the live .title rule — which overrides the line I drew
       earlier about keeping brand colour off user content. The old site puts
       the set title in #0064ff at 28px and I'd rather match it than keep my
       own rule; the magenta stays for section labels like the browse heading. */
    .set-head h1 {
        font-family: var(--display);
        color: var(--brand-a);
        font-weight: 300;
        font-size: clamp(28px, 4.5vw, 40px);
        letter-spacing: -0.022em;
        line-height: 1.12;
        margin: 6px 0 10px;
        max-width: 22ch;
    }

    .set-head p.lede {
        margin: 0 0 16px;
        font-size: 18px;
        line-height: 1.55;
        color: var(--ink-soft);
        max-width: 62ch;
    }

    .stats {
        display: flex;
        gap: 26px;
        flex-wrap: wrap;
        font-family: var(--mono);
        font-size: 12px;
        letter-spacing: 0.03em;
        color: var(--ink-soft);
    }

    .stats b {
        display: block;
        font-weight: 500;
        font-size: 20px;
        font-variant-numeric: tabular-nums;
        color: var(--ink);
        letter-spacing: -0.01em;
    }

    /* ---------- toolbar ---------- */

    .toolbar {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .toolbar form { display: flex; gap: 8px; }

    .toolbar input[type="search"] {
        height: 36px;
        width: 240px;
        max-width: 50vw;
        padding: 0 12px;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--tile);
        font-family: var(--body);
        font-weight: 300;
        font-size: 15px;
        color: var(--ink);
    }

    .toolbar input[type="search"]:focus { outline: 0; border-color: var(--brand-a); }

    /* Owner controls sit left of the self-test toggle, which keeps its
       margin-left:auto and stays pinned right. */
    .owner-tools { display: flex; gap: 8px; align-items: center; }

    .selftest {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: var(--mono);
        font-size: 12px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--ink-soft);
        cursor: pointer;
        user-select: none;
    }

    .selftest::before {
        content: '';
        width: 15px;
        height: 15px;
        border: 1px solid var(--ink-soft);
        border-radius: 2.5px;
        background: var(--tile);
        transition: background 120ms ease, border-color 120ms ease;
    }

    #selftest {
        position: absolute;
        width: 1px;
        height: 1px;
        opacity: 0;
        margin: 0;
        pointer-events: none;
    }

    #selftest:checked ~ .toolbar .selftest { color: var(--brand-a); }

    /* Solid, not --brand-fill: a 15px box is too small to show a ramp, so the
       gradient would just look like a slightly-off blue. */
    #selftest:checked ~ .toolbar .selftest::before {
        background: var(--brand-a);
        border-color: var(--brand-a);
    }

    #selftest:focus-visible ~ .toolbar .selftest::before {
        outline: 2px solid var(--brand-a);
        outline-offset: 2px;
    }

    /* ---------- card rows ---------- */

    .cards {
        list-style: none;
        margin: 0;
        padding: 0;
        border: 0;
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        overflow: hidden;
        background: var(--tile);
        /* Overridden inline per page — see the ul below. Kept here so the
           counter still exists if the attribute is ever dropped. */
        counter-reset: card;
    }

    .cards li {
        display: grid;
        grid-template-columns: 46px minmax(0, 1fr) minmax(0, 1.3fr) 52px;
        align-items: start;
        gap: 18px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--rule);
    }

    .cards li:last-child { border-bottom: 0; }
    .cards li:nth-child(even) { background: #e9e9eb; }

    .cards li::before {
        counter-increment: card;
        content: counter(card);
        font-family: var(--mono);
        font-size: 12px;
        font-variant-numeric: tabular-nums;
        color: #aeb6c0;
        padding-top: 3px;
    }

    /* 500 rather than 600: Heebo's 600 is heavy enough at 17px to fight the
       set title above it, which is now only 300. */
    .card-term { font-weight: 500; }

    .card-def { color: var(--ink-soft); }

    .card-def:empty::after {
        content: 'no definition stored';
        font-family: var(--mono);
        font-size: 12px;
        color: var(--gold);
    }

    .card-hint {
        display: block;
        margin-top: 5px;
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.03em;
        color: #97a0ab;
    }

    /* Miss rate from the legacy times_tested / times_wrong counters. Only shown
       once a card has enough attempts for the number to mean anything. */
    .card-miss {
        font-family: var(--mono);
        font-size: 11px;
        font-variant-numeric: tabular-nums;
        text-align: right;
        color: #aeb6c0;
        padding-top: 3px;
        white-space: nowrap;
    }

    .card-miss[data-hard="1"] { color: var(--gold); font-weight: 500; }


    /* A fifth column only for the owner. Everyone else keeps the four-column
       grid, so a visitor's view is unchanged. */
    .cards--manage li { grid-template-columns: 46px minmax(0, 1fr) minmax(0, 1.3fr) 52px 52px; }

    .card-edit {
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--ink-soft);
        text-decoration: none;
        text-align: right;
        padding-top: 3px;
    }

    .card-edit:hover, .card-edit:focus-visible { color: var(--brand-b); }


    /* Self-test: masks the answers until you hover or tab to one. This is the
       closest thing to actually flipping the card, and it needs no JS. */
    #selftest:checked ~ .cards .card-def {
        color: transparent;
        background: repeating-linear-gradient(
            -45deg, #e2e2e5, #e2e2e5 6px, #d8d8dc 6px, #d8d8dc 12px
        );
        border-radius: 3px;
        cursor: pointer;
        transition: color 90ms ease, background 90ms ease;
    }

    #selftest:checked ~ .cards .card-def:hover,
    #selftest:checked ~ .cards .card-def:focus-visible {
        color: var(--ink-soft);
        background: none;
    }

    .empty .btn { margin-top: 14px; }

    @media (max-width: 720px) {
        .cards li { grid-template-columns: 28px minmax(0, 1fr); }
        .cards li .card-def { grid-column: 2; }
        .cards li .card-miss { grid-column: 2; text-align: left; padding-top: 0; }
        .cards--manage li { grid-template-columns: 28px minmax(0, 1fr); }

        /* .card-def and .card-miss are pinned to column 2 here; .card-edit was
           left to auto-place, so on the owner's view it dropped into column 1
           under the row counter — the one 28px column on the page, with
           text-align: right pushing it against the number. */
        .cards--manage li .card-edit { grid-column: 2; text-align: left; padding-top: 0; }
    }
</style>
@endpush

@section('content')
    @if (session('status'))
        <p class="flash" role="status">{{ session('status') }}</p>
    @endif

    <div class="set-head">
        <p class="eyebrow">
            {{ $set->slug }} · {{ $set->user?->name ?? 'Unknown author' }}
            @unless ($set->isPublic()) · {{ $set->visibility }} @endunless
        </p>

        <h1>{{ $set->title }}</h1>

        @if (filled($set->description))
            <p class="lede">{{ $set->description }}</p>
        @endif

        <div class="stats">
            <span><b>{{ number_format($set->cards_count) }}</b> cards</span>
            <span><b>{{ number_format($set->quiz_sessions_count) }}</b> completed quizzes</span>
            @if ($set->created_at)
                <span><b>{{ $set->created_at->format('M Y') }}</b> created</span>
            @endif
        </div>
    </div>

    <input type="checkbox" id="selftest">

    <div class="toolbar">
        <form method="get" action="{{ route('sets.terms', $set) }}" role="search">
            <input
                type="search"
                name="q"
                value="{{ $q }}"
                placeholder="Find a card"
                aria-label="Search cards in this set"
            >
            @if ($q !== '')
                <a class="btn btn--quiet" href="{{ route('sets.terms', $set) }}">Clear</a>
            @endif
        </form>

        {{-- Route::has on the card routes so this page keeps rendering until
             card entry exists. Drop the guard once those routes are defined. --}}
        @if ($canManage)
            <div class="owner-tools">
                @if (Route::has('sets.cards.create'))
                    <a class="btn" href="{{ route('sets.cards.create', $set) }}">Add cards</a>
                @endif
                {{-- Only worth offering with something to reorder. One card
                     has exactly one order. --}}
                @if (Route::has('sets.cards.order') && $set->cards_count > 1)
                    <a class="btn btn--quiet" href="{{ route('sets.cards.order', $set) }}">Reorder</a>
                @endif
                <a class="btn btn--quiet" href="{{ route('sets.edit', $set) }}">Edit set</a>
            </div>
        @endif

        @unless ($cards->isEmpty())
            <label class="selftest" for="selftest">Hide answers</label>
        @endunless
    </div>

    @if ($cards->isEmpty())
        <div class="empty">
            @if ($q !== '')
                <p>No cards in this set match “{{ $q }}”.</p>
            @elseif ($canManage)
                <h2>No cards yet</h2>
                <p>Add a term and its definition to start studying this set.</p>
                @if (Route::has('sets.cards.create'))
                    <a class="btn" href="{{ route('sets.cards.create', $set) }}">Add the first cards</a>
                @endif
            @else
                <p>This set has no cards yet.</p>
            @endif
        </div>
    @else
        {{-- The counter is reset per render, so page 2 of an 85-card set
             started again at 1. firstItem() is the paginator's own 1-based
             index of the first row on this page, and counter-reset seeds the
             value *before* the first increment — hence the -1. --}}
        <ul
            class="cards @if ($canManage) cards--manage @endif"
            style="counter-reset: card {{ ($cards->firstItem() ?? 1) - 1 }}"
        >
            @foreach ($cards as $card)
                @php $miss = $card->missRate(); @endphp
                <li>
                    <div class="card-term">
                        {{ $card->term }}
                        @if (filled($card->hint))
                            <span class="card-hint">{{ $card->hint }}</span>
                        @endif
                    </div>

                    <div class="card-def" tabindex="0">{{ $card->definition }}</div>

                    <div
                        class="card-miss"
                        @if ($miss !== null) data-hard="{{ $miss >= 0.4 ? 1 : 0 }}" @endif
                        @if ($miss !== null) title="Missed {{ number_format($card->times_wrong) }} of {{ number_format($card->times_tested) }} attempts" @endif
                    >{{ $miss === null ? '' : round($miss * 100).'%' }}</div>
                    @if ($canManage && Route::has('cards.edit'))
                        <a class="card-edit" href="{{ route('cards.edit', $card) }}">Edit</a>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="pagination">
            {{ $cards->links('vendor.pagination.eduver') }}
        </div>
    @endif

    @include('partials.mode-strip', ['set' => $set, 'current' => 'terms'])
@endsection
