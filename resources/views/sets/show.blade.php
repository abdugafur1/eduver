@extends('layouts.app')

@section('title', $set->title)

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.index') }}" style="text-decoration: none;">&larr; All sets</a>
    </p>
@endsection

@push('styles')
<style>
    .set-head {
        padding-bottom: 22px;
        border-bottom: 2px solid var(--ink);
        margin-bottom: 26px;
    }

    .set-head h1 {
        font-family: var(--display);
        font-weight: 800;
        font-size: clamp(28px, 4.5vw, 40px);
        letter-spacing: -0.035em;
        line-height: 1.08;
        margin: 6px 0 10px;
        max-width: 22ch;
    }

    .set-head p.lede {
        margin: 0 0 16px;
        font-size: 18px;
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
        border-radius: 3px;
        background: var(--surface);
        font-family: var(--body);
        font-size: 15px;
        color: var(--ink);
    }

    .toolbar input[type="search"]:focus { outline: 0; border-color: var(--pine); }

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
        border-radius: 3px;
        background: var(--surface);
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

    #selftest:checked ~ .toolbar .selftest { color: var(--pine); }

    #selftest:checked ~ .toolbar .selftest::before {
        background: var(--pine);
        border-color: var(--pine);
    }

    #selftest:focus-visible ~ .toolbar .selftest::before {
        outline: 2px solid var(--pine);
        outline-offset: 2px;
    }

    /* ---------- card rows ---------- */

    .cards {
        list-style: none;
        margin: 0;
        padding: 0;
        border: 1px solid var(--rule);
        border-radius: 4px;
        overflow: hidden;
        background: var(--surface);
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
    .cards li:nth-child(even) { background: #fbfcfd; }

    .cards li::before {
        counter-increment: card;
        content: counter(card);
        font-family: var(--mono);
        font-size: 12px;
        font-variant-numeric: tabular-nums;
        color: #aeb6c0;
        padding-top: 3px;
    }

    .card-term { font-weight: 600; }

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

    /* Self-test: masks the answers until you hover or tab to one. This is the
       closest thing to actually flipping the card, and it needs no JS. */
    #selftest:checked ~ .cards .card-def {
        color: transparent;
        background: repeating-linear-gradient(
            -45deg, #eef1f4, #eef1f4 6px, #e6eaef 6px, #e6eaef 12px
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

    @media (max-width: 720px) {
        .cards li { grid-template-columns: 28px minmax(0, 1fr); }
        .cards li .card-def { grid-column: 2; }
        .cards li .card-miss { grid-column: 2; text-align: left; padding-top: 0; }
    }
</style>
@endpush

@section('content')
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
        <form method="get" action="{{ route('sets.show', $set) }}" role="search">
            <input
                type="search"
                name="q"
                value="{{ $q }}"
                placeholder="Find a card"
                aria-label="Search cards in this set"
            >
            @if ($q !== '')
                <a class="btn btn--quiet" href="{{ route('sets.show', $set) }}">Clear</a>
            @endif
        </form>

        @unless ($cards->isEmpty())
            <label class="selftest" for="selftest">Hide answers</label>
        @endunless
    </div>

    @if ($cards->isEmpty())
        <div class="empty">
            <p>
                @if ($q !== '')
                    No cards in this set match “{{ $q }}”.
                @else
                    This set has no cards. Worth checking against the legacy export.
                @endif
            </p>
        </div>
    @else
        <ul class="cards">
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
                </li>
            @endforeach
        </ul>

        <div class="pagination">
            {{ $cards->links('vendor.pagination.eduver') }}
        </div>
    @endif
@endsection
