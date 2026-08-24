@extends('layouts.app')

@section('title', 'Reorder — '.$set->title)

@section('masthead')
    <p class="masthead__meta">
        <a href="{{ route('sets.terms', $set) }}" style="text-decoration: none;">&larr; Back to terms</a>
    </p>
@endsection

@push('styles')
<style>
    .order { max-width: 720px; }

    .order__lede {
        font-size: 17px;
        line-height: 1.55;
        color: var(--ink-soft);
        margin: 0 0 24px;
        max-width: 62ch;
    }

    .order__list {
        list-style: none;
        margin: 0 0 24px;
        padding: 0;
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        overflow: hidden;
        background: var(--tile);
    }

    .order__row {
        display: grid;
        grid-template-columns: 28px 72px minmax(0, 1fr);
        align-items: center;
        gap: 14px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--rule);
        background: var(--tile);
    }

    .order__row:last-child { border-bottom: 0; }
    .order__row:nth-child(even) { background: #e9e9eb; }

    /* Only shown once the script has taken over: without it there is nothing
       to drag, and a grip that does nothing is worse than no grip. */
    .order__grip {
        display: none;
        font-size: 16px;
        color: #aeb6c0;
        cursor: grab;
        user-select: none;
        text-align: center;
    }

    .order--draggable .order__grip { display: block; }

    .order__row--lifted { opacity: 0.4; }

    .order__row--over { box-shadow: inset 0 2px 0 var(--brand-a); }

    .order__num input {
        width: 100%;
        height: 34px;
        padding: 0 8px;
        border: 1px solid var(--rule);
        border-radius: 2.5px;
        background: var(--paper);
        font-family: var(--mono);
        font-variant-numeric: tabular-nums;
        font-size: 14px;
        color: var(--ink);
        text-align: center;
    }

    .order__num input:focus { outline: 0; border-color: var(--brand-a); }

    .order__text { min-width: 0; }

    .order__term {
        font-weight: 500;
        display: block;
        overflow-wrap: anywhere;
    }

    .order__def {
        display: block;
        font-size: 14px;
        color: var(--ink-soft);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .order__actions { display: flex; gap: 12px; align-items: center; }

    .order__note {
        font-family: var(--mono);
        font-size: 11px;
        color: var(--ink-soft);
        margin: 0;
    }

    .notice {
        margin: 0 0 22px;
        padding: 12px 16px;
        border-radius: 2.5px;
        background: var(--tile);
        border-left: 3px solid var(--gold);
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        font-size: 15px;
        color: var(--gold);
    }
</style>
@endpush

@push('scripts')
<script>
    /*
        Drag is an enhancement over rows the server already rendered, not the
        mechanism. Without this file the number inputs still work, which is why
        the markup isn't built from a template — an x-for would leave a no-JS
        visitor with an empty list.

        After every drop the inputs are renumbered 1..n top to bottom, so what
        gets submitted always matches what's on screen.
    */
    document.addEventListener('DOMContentLoaded', () => {
        const list = document.getElementById('order-list');

        if (! list) return;

        list.classList.add('order--draggable');

        let lifted = null;

        const renumber = () => {
            list.querySelectorAll('.order__num input').forEach((input, index) => {
                input.value = index + 1;
            });
        };

        list.querySelectorAll('.order__row').forEach((row) => {
            row.draggable = true;

            row.addEventListener('dragstart', (event) => {
                lifted = row;
                row.classList.add('order__row--lifted');
                // Firefox won't start a drag without data set.
                event.dataTransfer.setData('text/plain', '');
                event.dataTransfer.effectAllowed = 'move';
            });

            row.addEventListener('dragend', () => {
                row.classList.remove('order__row--lifted');
                list.querySelectorAll('.order__row--over')
                    .forEach((r) => r.classList.remove('order__row--over'));
                lifted = null;
                renumber();
            });

            row.addEventListener('dragover', (event) => {
                event.preventDefault();
                if (row !== lifted) row.classList.add('order__row--over');
            });

            row.addEventListener('dragleave', () => {
                row.classList.remove('order__row--over');
            });

            row.addEventListener('drop', (event) => {
                event.preventDefault();
                row.classList.remove('order__row--over');

                if (! lifted || lifted === row) return;

                // compareDocumentPosition tells us which side to land on, so
                // dragging down puts the row after its target and dragging up
                // puts it before — which is what the drop line showed.
                const after = lifted.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_FOLLOWING;

                row.parentNode.insertBefore(lifted, after ? row.nextSibling : row);
                renumber();
            });
        });
    });
</script>
@endpush

@section('content')
    <div class="page-head">
        <p class="eyebrow">{{ $set->title }}</p>
        <h1>Reorder cards</h1>
    </div>

    <div class="order">
        @if ($overLimit)
            <p class="notice">
                This set has {{ number_format($set->cards_count) }} cards and only the
                first {{ number_format($limit) }} are shown. Saving keeps the rest in
                their current order, after these.
            </p>
        @endif

        <p class="order__lede">
            Drag a row, or type a number and save. The numbers are a ranking, not
            fixed slots — ties keep their current order, and everything is
            renumbered from 1 when you save.
        </p>

        @if ($cards->isEmpty())
            <div class="empty">
                <h2>Nothing to reorder</h2>
                <p>This set has no cards yet.</p>
                <a class="btn" href="{{ route('sets.cards.create', $set) }}">Add cards</a>
            </div>
        @else
            <form method="post" action="{{ route('sets.cards.reorder', $set) }}">
                @csrf
                @method('patch')

                <ol class="order__list" id="order-list">
                    @foreach ($cards as $index => $card)
                        <li class="order__row">
                            <span class="order__grip" aria-hidden="true">⣿</span>

                            <span class="order__num">
                                <input
                                    type="number"
                                    name="positions[{{ $card->id }}]"
                                    value="{{ $index + 1 }}"
                                    min="0"
                                    max="65535"
                                    step="1"
                                    inputmode="numeric"
                                    aria-label="Position for {{ $card->term }}"
                                >
                            </span>

                            <span class="order__text">
                                <span class="order__term">{{ $card->term }}</span>
                                <span class="order__def">{{ $card->definition }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>

                <div class="order__actions">
                    <button class="btn" type="submit">Save order</button>
                    <a class="btn btn--quiet" href="{{ route('sets.terms', $set) }}">Cancel</a>
                    <p class="order__note">{{ number_format($cards->count()) }} shown</p>
                </div>
            </form>
        @endif
    </div>
@endsection
