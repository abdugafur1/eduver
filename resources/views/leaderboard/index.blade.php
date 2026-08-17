@extends('layouts.app')

@section('title', 'Leaderboard')

@section('masthead')
    <p class="masthead__meta">
        {{ $board === 'daily' ? \Illuminate\Support\Carbon::parse($date)->format('j M') : 'all time' }}
    </p>
@endsection

@section('current-icon')
    @include('partials.rail-icon', ['name' => 'leaderboard'])
@endsection

@push('styles')
<style>
    .board-head { margin-bottom: 28px; }

    /* Same treatment as .browse-head h1 — flat magenta, display face, never
       below 24px. See the note there for why this isn't a clipped gradient. */
    .board-head h1 {
        font-family: var(--display);
        font-weight: 400;
        font-size: clamp(30px, 5vw, 46px);
        letter-spacing: -0.022em;
        line-height: 1.08;
        margin: 6px 0 0;
        width: fit-content;
        color: var(--brand-b);
    }

    /* Reuses .sort from the browse filters verbatim so the two pages share one
       tab idiom rather than growing a second one. */
    .board-tabs {
        display: flex;
        gap: 4px;
        padding: 14px 0 20px;
        border-bottom: 1px solid var(--rule);
        margin-bottom: 24px;
    }

    .board {
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-width: 640px;
    }

    .row {
        display: flex;
        align-items: baseline;
        gap: 14px;
        padding: 12px 16px;
        background: var(--tile);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
    }

    /* The viewer's own row, so you can find yourself without reading names. The
       live site has no precedent for this, so it borrows the rail's gradient
       rather than inventing a colour. */
    .row--viewer {
        background: linear-gradient(100deg, var(--brand-a), var(--brand-b));
        box-shadow: none;
    }

    .row--viewer .row__rank,
    .row--viewer .row__name,
    .row--viewer .row__meta,
    .row--viewer .row__score { color: var(--paper); }

    .row__rank {
        font-family: var(--mono);
        font-variant-numeric: tabular-nums;
        font-size: 13px;
        color: var(--ink-soft);
        min-width: 30px;
    }

    /* Top three carry the gold the visibility flag uses; nothing else is
       decorated, so the eye lands on the podium and then stops. */
    .row--podium .row__rank { color: var(--gold); font-weight: 500; }

    .row__name {
        font-family: var(--display);
        font-weight: 500;
        font-size: 17px;
        color: var(--brand-a);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .row__meta {
        font-family: var(--mono);
        font-size: 11px;
        color: var(--ink-soft);
    }

    .row__score {
        margin-left: auto;
        font-family: var(--mono);
        font-variant-numeric: tabular-nums;
        font-weight: 500;
        font-size: 15px;
        color: var(--ink);
    }

    /* Shown only when the viewer placed below the cut, so the board always
       answers "where am I" without loading 200 rows to find out. */
    .board__standing {
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid var(--rule);
        max-width: 640px;
    }
</style>
@endpush

@section('content')
    <div class="board-head">
        <p class="eyebrow">Standings</p>
        <h1>Leaderboard</h1>
    </div>

    <div class="board-tabs">
        @foreach (\App\Http\Controllers\LeaderboardController::BOARDS as $key => $label)
            <a
                class="sort"
                href="{{ route('leaderboard.index', $key === 'daily' ? [] : ['board' => $key]) }}"
                @if ($board === $key) aria-current="true" @endif
            >{{ $label }}</a>
        @endforeach
    </div>

    @if ($rows->isEmpty())
        <div class="empty">
            <h2>Nobody has scored yet</h2>
            <p>
                @if ($board === 'daily')
                    Finish a quiz today and you'll be first on the board.
                @else
                    Points come from multiple choice and true/false quizzes. The
                    first completed session puts someone here.
                @endif
            </p>
        </div>
    @else
        <div class="board">
            @foreach ($rows as $row)
                <div @class([
                    'row',
                    'row--podium' => $row['rank'] <= 3,
                    'row--viewer' => $row['is_viewer'],
                ])>
                    <span class="row__rank">{{ $row['rank'] }}</span>
                    <span class="row__name">{{ $row['name'] ?: $row['username'] }}</span>

                    @if ($row['meta'])
                        <span class="row__meta">{{ $row['meta'] }}</span>
                    @endif

                    <span class="row__score">{{ number_format($row['score']) }}</span>
                </div>
            @endforeach
        </div>

        {{-- Only when they aren't already visible above. --}}
        @if ($viewerRank && $viewerRank['rank'] > $rows->count())
            <div class="board__standing">
                <div class="row row--viewer">
                    <span class="row__rank">{{ $viewerRank['rank'] }}</span>
                    <span class="row__name">You</span>
                    <span class="row__score">{{ number_format($viewerRank['score']) }}</span>
                </div>
            </div>
        @endif
    @endif
@endsection
