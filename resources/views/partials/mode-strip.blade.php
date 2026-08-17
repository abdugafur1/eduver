{{--
    resources/views/partials/mode-strip.blade.php

    Every way of working through a set. Two categories, and the difference is
    structural rather than cosmetic:

      study  — flashcards, terms. Create nothing, write nothing, work logged
               out. quiz_sessions.user_id is constrained() and not nullable,
               so these are the only modes a guest can reach at all.

      quiz   — multiple choice, true/false, written. Each issues questions
               server-side with expected_answer withheld and expires_at set,
               then writes to quiz_sessions, cards.times_tested/times_wrong,
               point_transactions and daily_activities.

    quiz_sessions.mode is a 24-char string, not an enum, so a new mode is a new
    row here plus a route — no migration. Modes without a route render as
    disabled, which is also what a guest sees for the quiz group.

    In normal flow deliberately, not fixed: under 600px the rail becomes a
    docked bottom bar and a fixed strip would sit underneath it.

    Usage: @include('partials.mode-strip', ['set' => $set, 'current' => 'flashcards'])
--}}
@php
    $modes = [
        ['key' => 'flashcards', 'label' => 'Flashcards',      'group' => 'study', 'route' => 'sets.show'],
        ['key' => 'terms',      'label' => 'List the Terms',  'group' => 'study', 'route' => 'sets.terms'],
        ['key' => 'multiple_choice', 'label' => 'Multiple Choice', 'group' => 'quiz', 'route' => 'sets.quiz.multiple_choice'],
        ['key' => 'true_false',      'label' => 'True and False',  'group' => 'quiz', 'route' => 'sets.quiz.true_false'],
        ['key' => 'written',         'label' => 'Written',         'group' => 'quiz', 'route' => 'sets.quiz.written'],
    ];

    $empty = ($set->cards_count ?? 0) === 0;
@endphp

@push('styles')
<style>
    .modes {
        margin-top: 56px;
        padding-top: 26px;
        border-top: 1px solid var(--rule);
    }

    .modes__label {
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--ink-soft);
        margin: 0 0 14px;
    }

    .modes__row {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        margin-bottom: 26px;
    }

    /* #options a on the live site: 225x50, 5px radius, 135deg gradient,
       #ababad shadow, and #ffa500 on hover. */
    .mode {
        width: 225px;
        height: 50px;
        border-radius: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 18px;
        color: var(--paper);
        background: var(--brand-fill);
        box-shadow: 2.5px 3px 6px var(--shadow);
    }

    .mode:hover,
    .mode:focus,
    .mode:active { color: var(--brand-hover); }

    .mode[aria-current="page"] {
        outline: 2px solid var(--brand-a);
        outline-offset: 3px;
    }

    /* No route yet, no cards, or a guest looking at the quiz group. Flat and
       inert rather than hidden, so the set of modes stays legible. */
    .mode--off {
        background: none;
        box-shadow: none;
        border: 1px dashed var(--rule);
        color: var(--ink-soft);
        cursor: not-allowed;
    }

    .mode--off:hover { color: var(--ink-soft); }

    .modes__note {
        font-family: var(--mono);
        font-size: 11px;
        color: var(--ink-soft);
        margin: 0;
    }

    @media (max-width: 520px) {
        .mode { width: 100%; }
    }
</style>
@endpush

<nav class="modes" aria-label="Study modes">
    @foreach (['study' => 'Study', 'quiz' => 'Quiz yourself'] as $group => $heading)
        <p class="modes__label">{{ $heading }}</p>

        <div class="modes__row">
            @foreach (array_filter($modes, fn ($m) => $m['group'] === $group) as $mode)
                @php
                    $needsAuth = $group === 'quiz';
                    $live = Route::has($mode['route'])
                        && ! $empty
                        && (! $needsAuth || auth()->check());
                @endphp

                @if ($live)
                    <a
                        class="mode"
                        href="{{ route($mode['route'], $set) }}"
                        @if ($current === $mode['key']) aria-current="page" @endif
                    >{{ $mode['label'] }}</a>
                @else
                    <span class="mode mode--off" aria-disabled="true">{{ $mode['label'] }}</span>
                @endif
            @endforeach
        </div>

        @if ($group === 'quiz' && ! auth()->check())
            <p class="modes__note">Quiz modes record your score, so they need an account.</p>
        @endif
    @endforeach

    @if ($empty)
        <p class="modes__note">This set has no cards yet.</p>
    @endif
</nav>
