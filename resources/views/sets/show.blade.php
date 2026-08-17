@extends('layouts.app')

@section('title', $set->title)

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.index') }}" style="text-decoration: none;">&larr; All sets</a>
    </p>
@endsection

@push('styles')
<style>
    [x-cloak] { display: none !important; }

    .notice {
        margin: 0 0 22px;
        padding: 12px 16px;
        border-radius: 2.5px;
        background: var(--tile);
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        font-size: 15px;
        color: var(--gold);
    }

    .set-head {
        padding-bottom: 22px;
        margin-bottom: 30px;
    }

    /* Blue, per the live .title rule. */
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

    .set-head .byline {
        font-size: 18px;
        color: var(--brand-b);   /* .details */
        margin: 0;
    }

    /* ---------- the card ---------- */

    .deck { max-width: 640px; }

    .flip {
        perspective: 1400px;
        width: 100%;
        margin-bottom: 18px;
    }

    .flip__inner {
        position: relative;
        width: 100%;
        min-height: 320px;
        transition: transform 460ms cubic-bezier(0.2, 0.7, 0.3, 1);
        transform-style: preserve-3d;
        cursor: pointer;
        border: 0;
        padding: 0;
        background: none;
        font: inherit;
        color: inherit;
        display: block;
    }

    .flip__inner[data-flipped="true"] { transform: rotateY(180deg); }

    .face {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 14px;
        padding: 36px 32px;
        background: var(--tile);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
    }

    .face--back { transform: rotateY(180deg); }

    .face__text {
        font-size: clamp(22px, 3.4vw, 30px);
        line-height: 1.35;
        text-align: center;
        color: var(--ink);
        overflow-wrap: anywhere;
        margin: 0;
    }

    .face--front .face__text { color: var(--brand-a); }

    .face__hint {
        font-family: var(--mono);
        font-size: 12px;
        letter-spacing: 0.03em;
        color: var(--ink-soft);
        text-align: center;
        margin: 0;
    }

    .face__role {
        position: absolute;
        top: 14px;
        left: 16px;
        font-family: var(--mono);
        font-size: 10px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--ink-soft);
    }

    /* The flip is the whole interaction, so it keeps a reduced-motion
       fallback rather than being dropped: faces swap instantly. */
    @media (prefers-reduced-motion: reduce) {
        .flip__inner { transition: none; }
    }

    /* ---------- controls ---------- */

    .deck__bar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .deck__count {
        font-family: var(--mono);
        font-variant-numeric: tabular-nums;
        font-size: 13px;
        color: var(--ink-soft);
        margin: 0 auto 0 0;
    }

    .step {
        width: 44px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--tile);
        color: var(--brand-a);
        font-size: 18px;
        cursor: pointer;
    }

    .step:disabled { color: #b6b6ba; cursor: default; }

    .step:not(:disabled):hover { color: var(--brand-b); }

    .deck__progress {
        height: 3px;
        background: var(--rule);
        border-radius: 2px;
        overflow: hidden;
        margin: 18px 0 10px;
    }

    .deck__progress span {
        display: block;
        height: 100%;
        background: var(--brand-fill);
        transition: width 200ms ease;
    }

    .deck__keys {
        font-family: var(--mono);
        font-size: 11px;
        color: var(--ink-soft);
        margin: 14px 0 0;
    }
</style>
@endpush

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('deck', (cards) => ({
            cards,
            // Indices rather than the cards themselves, so shuffling never
            // mutates the source and "reset" is a re-sort, not a reload.
            order: cards.map((_, i) => i),
            i: 0,
            flipped: false,
            shuffled: false,

            get card() { return this.cards[this.order[this.i]] ?? { term: '', definition: '', hint: null } },
            get total() { return this.order.length },
            get pct() { return this.total ? ((this.i + 1) / this.total) * 100 : 0 },

            flip() { this.flipped = ! this.flipped },

            go(step) {
                const next = this.i + step;
                if (next < 0 || next >= this.total) return;
                this.i = next;
                // Always land face-up: carrying the flip over would show an
                // answer before its question.
                this.flipped = false;
            },

            shuffle() {
                for (let n = this.order.length - 1; n > 0; n--) {
                    const m = Math.floor(Math.random() * (n + 1));
                    [this.order[n], this.order[m]] = [this.order[m], this.order[n]];
                }
                this.i = 0;
                this.flipped = false;
                this.shuffled = true;
            },

            reset() {
                this.order = this.cards.map((_, n) => n);
                this.i = 0;
                this.flipped = false;
                this.shuffled = false;
            },
        }));
    });
</script>
@endpush

@section('content')
    @if (session('quiz.error'))
        <p class="notice">{{ session('quiz.error') }}</p>
    @endif

    <div class="set-head">
        <p class="eyebrow">
            {{ $set->slug }}
            @unless ($set->isPublic()) · {{ $set->visibility }} @endunless
        </p>

        <h1>{{ $set->title }}</h1>

        <p class="byline">{{ $set->user?->name ?? 'Unknown author' }}</p>

        @if (filled($set->description))
            <p class="lede">{{ $set->description }}</p>
        @endif
    </div>

    @if ($cards->isEmpty())
        <div class="empty">
            <h2>Nothing to study yet</h2>
            <p>This set has no cards. Worth checking against the legacy export.</p>
        </div>
    @else
        <div
            class="deck"
            x-data="deck(@js($cards))"
            x-cloak
            @keydown.window.space.prevent="flip()"
            @keydown.window.arrow-left="go(-1)"
            @keydown.window.arrow-right="go(1)"
        >
            <div class="flip">
                <button
                    class="flip__inner"
                    type="button"
                    :data-flipped="flipped"
                    @click="flip()"
                    :aria-label="flipped ? 'Show term' : 'Show definition'"
                >
                    <div class="face face--front">
                        <span class="face__role">Term</span>
                        <p class="face__text" x-text="card.term"></p>
                        <template x-if="card.hint">
                            <p class="face__hint" x-text="card.hint"></p>
                        </template>
                    </div>

                    <div class="face face--back">
                        <span class="face__role">Definition</span>
                        <p class="face__text" x-text="card.definition"></p>
                    </div>
                </button>
            </div>

            <div class="deck__progress" aria-hidden="true">
                <span :style="`width: ${pct}%`"></span>
            </div>

            <div class="deck__bar">
                <p class="deck__count">
                    <span x-text="i + 1"></span> / <span x-text="total"></span>
                    <span x-show="shuffled"> · shuffled</span>
                </p>

                <button class="step" type="button" @click="go(-1)" :disabled="i === 0" aria-label="Previous card">&larr;</button>
                <button class="step" type="button" @click="go(1)" :disabled="i === total - 1" aria-label="Next card">&rarr;</button>

                <button class="btn btn--quiet" type="button" @click="shuffle()">Shuffle</button>
                <button class="btn btn--quiet" type="button" x-show="shuffled" @click="reset()">Reset order</button>
            </div>

            <p class="deck__keys">Space flips · arrow keys move</p>
        </div>
    @endif

    @include('partials.mode-strip', ['set' => $set, 'current' => 'flashcards'])
@endsection
