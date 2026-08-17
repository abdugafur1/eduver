@extends('layouts.app')

@section('title', 'Results — '.$set->title)

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.show', $set) }}" style="text-decoration: none;">&larr; Back to set</a>
    </p>
@endsection

@push('styles')
<style>
    .score { max-width: 700px; }

    .score h1 {
        font-family: var(--display);
        color: var(--brand-a);
        font-weight: 300;
        font-size: clamp(28px, 4.5vw, 40px);
        letter-spacing: -0.022em;
        margin: 6px 0 8px;
    }

    .score__set {
        font-size: 18px;
        color: var(--brand-b);
        margin: 0 0 26px;
    }

    .stats {
        display: flex;
        gap: 26px;
        flex-wrap: wrap;
        font-family: var(--mono);
        font-size: 12px;
        letter-spacing: 0.03em;
        color: var(--ink-soft);
        padding-bottom: 22px;
        border-bottom: 2px solid var(--ink);
        margin-bottom: 26px;
    }

    .stats b {
        display: block;
        font-weight: 500;
        font-size: 20px;
        font-variant-numeric: tabular-nums;
        color: var(--ink);
        letter-spacing: -0.01em;
    }

    .review {
        list-style: none;
        margin: 0 0 30px;
        padding: 0;
        border-radius: 2.5px;
        overflow: hidden;
        background: var(--tile);
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        counter-reset: q;
    }

    .review li {
        display: grid;
        grid-template-columns: 44px minmax(0, 1fr) 80px;
        gap: 16px;
        align-items: start;
        padding: 16px 20px;
        border-bottom: 1px solid var(--rule);
    }

    .review li:last-child { border-bottom: 0; }
    .review li:nth-child(even) { background: #e9e9eb; }

    .review li::before {
        counter-increment: q;
        content: counter(q);
        font-family: var(--mono);
        font-size: 12px;
        font-variant-numeric: tabular-nums;
        color: #aeb6c0;
        padding-top: 3px;
    }

    .review__term { font-weight: 500; }

    .review__answer {
        display: block;
        margin-top: 5px;
        font-size: 15px;
        color: var(--ink-soft);
    }

    /* Only shown when they got it wrong — repeating the right answer under a
       correct response is noise. */
    .review__claim {
        display: block;
        margin-top: 4px;
        font-size: 15px;
        color: var(--ink);
    }

    .review__expected {
        display: block;
        margin-top: 3px;
        font-size: 15px;
        color: var(--brand-a);
    }

    .review__mark {
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        text-align: right;
        padding-top: 3px;
    }

    .review__mark[data-state="hit"]     { color: var(--brand-a); }
    .review__mark[data-state="miss"]    { color: var(--gold); }
    .review__mark[data-state="skipped"] { color: #aeb6c0; }

    .score__capped {
        margin: 0 0 22px;
        padding: 12px 16px;
        border-radius: 2.5px;
        background: var(--tile);
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        font-size: 15px;
        color: var(--ink-soft);
        max-width: 52ch;
    }

    .score__actions { display: flex; gap: 12px; flex-wrap: wrap; }
</style>
@endpush

@section('content')
    <div class="score">
        <p class="eyebrow">{{ $session->modeLabel() }}</p>
        <h1>{{ $session->correct_count }} of {{ $session->questions_answered }} right</h1>
        <p class="score__set">{{ $set->title }}</p>

        <div class="stats">
            <span>
                <b>{{ $session->accuracy() === null ? '—' : round($session->accuracy() * 100).'%' }}</b>
                accuracy
            </span>
            <span>
                <b>{{ $session->questions_answered }}/{{ $session->questions_total }}</b>
                answered
            </span>
            @if ($session->durationInSeconds() !== null)
                <span><b>{{ gmdate('i:s', $session->durationInSeconds()) }}</b> taken</span>
            @endif

            <span><b>{{ number_format($session->points_awarded) }}</b> points</span>
        </div>

        <ul class="review">
            @foreach ($session->questions as $q)
                @php
                    // is_correct is nullable and the third state is real:
                    // NULL means issued but never answered, which is not the
                    // same as answered wrong.
                    $state = $q->answered_at === null ? 'skipped' : ($q->is_correct ? 'hit' : 'miss');
                @endphp
                <li>
                    <div>
                        <span class="review__term">{{ $q->card?->term ?? 'Deleted card' }}</span>

                        {{-- What the question actually asserted. Without it a
                             true/false review is just True/False against
                             True/False, with no way to see what was claimed. --}}
                        @if ($q->isTrueFalse())
                            <span class="review__claim">{{ $q->claim() }}</span>
                        @endif

                        @if ($state === 'skipped')
                            <span class="review__answer">Not answered</span>
                        @else
                            <span class="review__answer">{{ $q->submitted_answer }}</span>
                        @endif

                        @if ($state !== 'hit')
                            <span class="review__expected">
                                @if ($q->isTrueFalse())
                                    That was {{ strtolower($q->expected_answer) }}
                                @else
                                    {{ $q->expected_answer }}
                                @endif
                            </span>
                        @endif
                    </div>

                    <div class="review__mark" data-state="{{ $state }}">
                        {{ ['hit' => 'Right', 'miss' => 'Wrong', 'skipped' => '—'][$state] }}
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($session->correct_count > 0 && $session->points_awarded < $session->correct_count * \App\Services\QuizRewards::POINTS_PER_CORRECT && $session->points_awarded > 0)
            <p class="score__capped">
                You hit this set's daily allowance partway through, so some of
                these answers didn't earn. It resets tomorrow.
            </p>
        @elseif ($session->points_awarded === 0 && $session->correct_count > 0)
            <p class="score__capped">
                No points this time — you've already earned this set's daily
                allowance. Each set is worth {{ number_format($set->cards_count * \App\Services\QuizRewards::POINTS_PER_CORRECT) }}
                points a day, ten per correct answer, so bigger sets give you
                more room. Your score above still counts.
            </p>
        @endif

        <div class="score__actions">
            <a class="btn" href="{{ route('sets.quiz.'.$session->mode, $set) }}">Quiz again</a>
            <a class="btn btn--quiet" href="{{ route('sets.show', $set) }}">Back to the set</a>
        </div>
    </div>
@endsection
