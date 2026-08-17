@extends('layouts.app')

@section('title', 'Edit card')

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.terms', $set) }}" style="text-decoration: none;">&larr; Back to set</a>
    </p>
@endsection

@push('styles')
<style>
    .form-head { margin-bottom: 26px; }

    .form-head h1 {
        font-family: var(--display);
        color: var(--brand-a);
        font-weight: 300;
        font-size: clamp(28px, 4.5vw, 40px);
        letter-spacing: -0.022em;
        line-height: 1.12;
        margin: 6px 0 0;
    }

    .form-shell {
        max-width: 620px;
        background: var(--tile);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        padding: 26px;
    }

    .form-row { margin-bottom: 20px; }

    .form-row > label {
        display: block;
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ink-soft);
        margin-bottom: 7px;
    }

    .form-row input[type="text"] {
        display: block;
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--paper);
        font-family: var(--body);
        font-weight: 300;
        font-size: 17px;
        color: var(--ink);
    }

    .form-row input[type="text"]:focus { outline: 0; border-color: var(--brand-a); }
    .form-row input[aria-invalid="true"] { border-color: var(--gold); }

    .form-error {
        margin: 6px 0 0;
        font-family: var(--mono);
        font-size: 12px;
        color: var(--gold);
    }

    .form-actions {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid var(--rule);
    }

    .form-actions form { margin-left: auto; }

    .btn--danger { color: var(--gold); }

    .card-stats {
        margin: 18px 0 0;
        font-family: var(--mono);
        font-size: 12px;
        color: var(--ink-soft);
    }
</style>
@endpush

@section('content')
    <div class="form-head">
        <p class="eyebrow">{{ $set->title }}</p>
        <h1>Edit card</h1>
    </div>

    <div class="form-shell">
        <form method="post" action="{{ route('cards.update', $card) }}">
            @csrf
            @method('patch')

            <div class="form-row">
                <label for="term">Term</label>
                <input
                    type="text" id="term" name="term" maxlength="500" required autofocus
                    value="{{ old('term', $card->term) }}"
                    @error('term') aria-invalid="true" @enderror
                >
                @error('term')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-row">
                <label for="definition">Definition</label>
                <input
                    type="text" id="definition" name="definition" maxlength="2000" required
                    value="{{ old('definition', $card->definition) }}"
                    @error('definition') aria-invalid="true" @enderror
                >
                @error('definition')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-row">
                <label for="hint">Hint (optional)</label>
                <input
                    type="text" id="hint" name="hint" maxlength="500"
                    value="{{ old('hint', $card->hint) }}"
                >
                @error('hint')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-actions">
                <button class="btn" type="submit">Save card</button>
                <a class="btn btn--quiet" href="{{ route('sets.terms', $set) }}">Cancel</a>
            </div>
        </form>

        {{-- Its own form: a delete button nested inside the edit form would
             submit the edit instead. --}}
        <form
            method="post"
            action="{{ route('cards.destroy', $card) }}"
            onsubmit="return confirm('Delete this card? Quiz history that references it goes too.')"
            style="margin-top: 16px;"
        >
            @csrf
            @method('delete')
            <button class="btn btn--quiet btn--danger" type="submit">Delete card</button>
        </form>

        @if ($card->times_tested > 0)
            <p class="card-stats">
                Answered {{ number_format($card->times_tested) }} {{ Str::plural('time', $card->times_tested) }},
                missed {{ number_format($card->times_wrong) }}.
            </p>
        @endif
    </div>
@endsection
