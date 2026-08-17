@extends('layouts.app')

@section('title', 'Add cards to '.$set->title)

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.terms', $set) }}" style="text-decoration: none;">&larr; Back to set</a>
    </p>
@endsection

@push('styles')
<style>
    .form-head { margin-bottom: 24px; }

    .form-head h1 {
        font-family: var(--display);
        color: var(--brand-a);
        font-weight: 300;
        font-size: clamp(28px, 4.5vw, 40px);
        letter-spacing: -0.022em;
        line-height: 1.12;
        margin: 6px 0 0;
        max-width: 22ch;
    }

    .rows { display: grid; gap: 14px; }

    /* Numbered because position is real: these become cards in this order,
       appended after whatever the set already holds. */
    .row {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr) minmax(0, 1.2fr) 34px;
        gap: 14px;
        align-items: start;
        padding: 16px 18px;
        background: var(--tile);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
    }

    .row__n {
        font-family: var(--mono);
        font-size: 12px;
        font-variant-numeric: tabular-nums;
        color: #aeb6c0;
        padding-top: 11px;
    }

    .row label {
        display: block;
        font-family: var(--mono);
        font-size: 10px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ink-soft);
        margin-bottom: 5px;
    }

    .row input[type="text"] {
        display: block;
        width: 100%;
        padding: 8px 11px;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--paper);
        font-family: var(--body);
        font-weight: 300;
        font-size: 16px;
        color: var(--ink);
    }

    .row input[type="text"]:focus { outline: 0; border-color: var(--brand-a); }
    .row input[aria-invalid="true"] { border-color: var(--gold); }

    .row__hint { margin-top: 9px; }

    .row__drop {
        margin-top: 22px;
        width: 30px;
        height: 30px;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--paper);
        color: var(--ink-soft);
        font-size: 17px;
        line-height: 1;
        cursor: pointer;
    }

    .row__drop:hover { border-color: var(--gold); color: var(--gold); }

    .row__error {
        grid-column: 2 / -1;
        margin: 0;
        font-family: var(--mono);
        font-size: 12px;
        line-height: 1.45;
        color: var(--gold);
    }

    .entry-actions {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 22px;
        padding-top: 20px;
        border-top: 1px solid var(--rule);
    }

    .entry-count {
        font-family: var(--mono);
        font-size: 12px;
        color: var(--ink-soft);
        margin-left: auto;
    }

    .form-error {
        margin: 0 0 16px;
        font-family: var(--mono);
        font-size: 13px;
        color: var(--gold);
    }

    @media (max-width: 720px) {
        .row { grid-template-columns: 24px minmax(0, 1fr) 30px; }
        .row__def { grid-column: 2; }
        .row__error { grid-column: 2 / -1; }
    }
</style>
@endpush

@section('content')
    <div class="form-head">
        <p class="eyebrow">{{ $set->title }} &middot; {{ number_format($set->cards_count) }} {{ Str::plural('card', $set->cards_count) }} so far</p>
        <h1>Add cards</h1>
    </div>

    @error('cards')
        <p class="form-error">{{ $message }}</p>
    @enderror

    @php
        // Repopulate from old() after a validation error, preserving keys so an
        // error on cards.3 stays attached to the row it came from. Falls back
        // to a set of blanks on first load.
        $rows = old('cards') ?: array_fill(0, $blankRows, ['term' => '', 'definition' => '', 'hint' => '']);
    @endphp

    <form
        method="post"
        action="{{ route('sets.cards.store', $set) }}"
        x-data="{
            rows: {{ Js::from(array_values($rows)) }},
            add(n = 1) { for (let i = 0; i < n; i++) this.rows.push({ term: '', definition: '', hint: '' }); },
            drop(i) { this.rows.splice(i, 1); if (!this.rows.length) this.add(); },
            get filled() { return this.rows.filter(r => r.term.trim() || r.definition.trim()).length; },
        }"
    >
        @csrf

        <div class="rows">
            <template x-for="(row, i) in rows" :key="i">
                <div class="row">
                    <span class="row__n" x-text="i + 1"></span>

                    <div>
                        <label :for="'term-' + i">Term</label>
                        <input
                            type="text"
                            :id="'term-' + i"
                            :name="'cards[' + i + '][term]'"
                            x-model="row.term"
                            maxlength="500"
                        >

                        <div class="row__hint">
                            <label :for="'hint-' + i">Hint (optional)</label>
                            <input
                                type="text"
                                :id="'hint-' + i"
                                :name="'cards[' + i + '][hint]'"
                                x-model="row.hint"
                                maxlength="500"
                            >
                        </div>
                    </div>

                    <div class="row__def">
                        <label :for="'definition-' + i">Definition</label>
                        <input
                            type="text"
                            :id="'definition-' + i"
                            :name="'cards[' + i + '][definition]'"
                            x-model="row.definition"
                            maxlength="2000"
                        >
                    </div>

                    <button
                        type="button"
                        class="row__drop"
                        @click="drop(i)"
                        :aria-label="'Remove row ' + (i + 1)"
                    >&times;</button>
                </div>
            </template>
        </div>

        {{-- Server-side errors, rendered outside the Alpine template because
             x-for can't see PHP. Keyed by the same index the inputs use. --}}
        @foreach ($rows as $i => $row)
            @error("cards.$i.term")
                <p class="row__error">Row {{ $i + 1 }}: {{ $message }}</p>
            @enderror
            @error("cards.$i.definition")
                <p class="row__error">Row {{ $i + 1 }}: {{ $message }}</p>
            @enderror
        @endforeach

        <div class="entry-actions">
            <button class="btn" type="submit">Save cards</button>
            <button class="btn btn--quiet" type="button" @click="add()">Add another row</button>
            <button class="btn btn--quiet" type="button" @click="add(5)">Add five</button>
            <a class="btn btn--quiet" href="{{ route('sets.terms', $set) }}">Cancel</a>
            <span class="entry-count" x-text="filled + ' of ' + rows.length + ' rows filled'"></span>
        </div>
    </form>
@endsection
