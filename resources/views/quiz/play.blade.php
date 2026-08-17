@extends('layouts.app')

@section('title', 'Quiz — '.$set->title)

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.show', $set) }}" style="text-decoration: none;">&larr; Back to set</a>
    </p>
@endsection

@push('styles')
<style>
    .quiz { max-width: 640px; }

    .quiz__head { margin-bottom: 22px; }

    .quiz__set {
        font-size: 18px;
        color: var(--brand-b);   /* .details */
        margin: 4px 0 0;
    }

    .quiz__progress {
        height: 3px;
        background: var(--rule);
        border-radius: 2px;
        overflow: hidden;
        margin: 20px 0 8px;
    }

    .quiz__progress span {
        display: block;
        height: 100%;
        background: var(--brand-fill);
    }

    .quiz__count {
        font-family: var(--mono);
        font-variant-numeric: tabular-nums;
        font-size: 13px;
        color: var(--ink-soft);
        margin: 0 0 26px;
    }

    /* ---------- verdict on the previous question ---------- */

    .verdict {
        padding: 14px 16px;
        border-radius: 2.5px;
        background: var(--tile);
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        margin-bottom: 24px;
    }

    .verdict__line {
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        margin: 0 0 6px;
    }

    .verdict--hit .verdict__line { color: var(--brand-a); }
    .verdict--miss .verdict__line { color: var(--gold); }

    .verdict__was {
        display: inline-block;
        margin-top: 6px;
        color: var(--ink);
    }

    .verdict p:last-child {
        margin: 0;
        font-size: 15px;
        color: var(--ink-soft);
    }

    /* ---------- prompt ---------- */

    .prompt {
        padding: 30px 26px;
        background: var(--tile);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        margin-bottom: 22px;
    }

    .prompt__role {
        font-family: var(--mono);
        font-size: 10px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--ink-soft);
        margin: 0 0 12px;
    }

    .prompt__term {
        font-size: clamp(22px, 3.4vw, 30px);
        line-height: 1.3;
        color: var(--brand-a);
        margin: 0;
        overflow-wrap: anywhere;
    }

    .prompt__hint {
        font-family: var(--mono);
        font-size: 12px;
        color: var(--ink-soft);
        margin: 12px 0 0;
    }

    /* ---------- options ---------- */

    .prompt__claim {
        margin: 14px 0 0;
        font-size: 19px;
        line-height: 1.45;
        color: var(--ink);
        overflow-wrap: anywhere;
    }

    .options {
        display: grid;
        gap: 12px;
        margin: 0;
        padding: 0;
        border: 0;
    }

    .option {
        display: block;
        width: 100%;
        text-align: left;
        padding: 16px 18px;
        font: inherit;
        font-size: 17px;
        color: var(--ink);
        background: var(--tile);
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        cursor: pointer;
        transition: color 120ms ease, border-color 120ms ease;
    }

    .option:hover,
    .option:focus-visible {
        color: var(--brand-a);
        border-color: var(--brand-a);
    }

    /* True and False side by side — two options read as a pair, where four
       stacked options read as a list. */
    .options--pair { grid-template-columns: 1fr 1fr; }

    .options--pair .option { text-align: center; font-size: 18px; }

    .options--pair .option__key { display: none; }

    @media (max-width: 480px) {
        .options--pair { grid-template-columns: 1fr; }
    }

    .option__key {
        font-family: var(--mono);
        font-size: 12px;
        color: var(--ink-soft);
        margin-right: 12px;
    }
</style>
@endpush

@section('content')
    <div class="quiz">
        <div class="quiz__head">
            <p class="eyebrow">{{ $session->modeLabel() }}</p>
            <p class="quiz__set">{{ $set->title }}</p>
        </div>

        <div class="quiz__progress" aria-hidden="true">
            <span style="width: {{ $session->questions_total ? ($session->questions_answered / $session->questions_total) * 100 : 0 }}%"></span>
        </div>

        <p class="quiz__count">
            Question {{ $number }} of {{ $session->questions_total }}
            · {{ $session->correct_count }} right so far
        </p>

        @if ($verdict = session('quiz.verdict'))
            <div class="verdict {{ $verdict['correct'] ? 'verdict--hit' : 'verdict--miss' }}" role="status">
                <p class="verdict__line">
                    {{ $verdict['correct'] ? 'Correct' : 'Not quite' }}@if (filled($verdict['term'] ?? null)) — {{ $verdict['term'] }}@endif
                </p>

                @if (filled($verdict['claim'] ?? null))
                    <p>
                        {{ $verdict['claim'] }}<br>
                        <span class="verdict__was">That was {{ strtolower($verdict['expected']) }}.</span>
                    </p>
                @elseif (! $verdict['correct'])
                    <p>The answer was {{ $verdict['expected'] }}</p>
                @endif
            </div>
        @endif

        <div class="prompt">
            @if ($question->isTrueFalse())
                <p class="prompt__role">True or false?</p>
                <p class="prompt__term">{{ $question->card->term }}</p>
                <p class="prompt__claim">{{ $question->claim() }}</p>
            @else
                <p class="prompt__role">Term</p>
                <p class="prompt__term">{{ $question->card->term }}</p>
            @endif

            {{-- Hints are withheld on true/false: the hint is often a usage
                 example containing the term's real meaning, which would give
                 the claim away outright. --}}
            @if (! $question->isTrueFalse() && filled($question->card->hint))
                <p class="prompt__hint">{{ $question->card->hint }}</p>
            @endif
        </div>

        {{-- One form per option rather than radios plus a submit: it's one
             action, not a choice to be reviewed, and it keeps the whole thing
             working without JavaScript. The value posted is the index into
             presented_options — the server maps it back to text. --}}
        <fieldset class="options {{ $question->isTrueFalse() ? 'options--pair' : '' }}">
            @foreach ($question->options() as $index => $option)
                <form method="post" action="{{ route('quiz.answer', $session) }}">
                    @csrf
                    <input type="hidden" name="question_id" value="{{ $question->id }}">
                    <input type="hidden" name="choice" value="{{ $index }}">

                    <button class="option" type="submit">
                        <span class="option__key">{{ chr(65 + $index) }}</span>{{ $option }}
                    </button>
                </form>
            @endforeach
        </fieldset>
    </div>
@endsection
