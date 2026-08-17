@extends('layouts.app')

@section('title', 'Add cards')

@push('styles')
<style>
    .form-head { margin-bottom: 26px; }

    .form-head h1 {
        font-family: var(--display);
        font-weight: 400;
        font-size: clamp(30px, 5vw, 46px);
        letter-spacing: -0.022em;
        line-height: 1.08;
        margin: 6px 0 0;
        width: fit-content;
        color: var(--brand-b);
    }

    .pick { display: grid; gap: 10px; max-width: 620px; }

    .pick a {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 14px;
        align-items: baseline;
        padding: 15px 18px;
        background: var(--tile);
        border-radius: 2.5px;
        box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        text-decoration: none;
        transition: color 120ms ease;
    }

    .pick a:hover .pick__title,
    .pick a:focus-visible .pick__title { color: var(--brand-b); }

    .pick__title {
        font-family: var(--display);
        font-weight: 500;
        font-size: 18px;
        color: var(--brand-a);
        transition: color 120ms ease;
    }

    .pick__meta {
        font-family: var(--mono);
        font-size: 12px;
        font-variant-numeric: tabular-nums;
        color: var(--ink-soft);
        white-space: nowrap;
    }

    .pick__flag {
        font-family: var(--mono);
        font-size: 10px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--gold);
        margin-left: 8px;
    }
</style>
@endpush

@section('content')
    <div class="form-head">
        <p class="eyebrow">Library</p>
        <h1>Add cards</h1>
    </div>

    @if ($sets->isEmpty())
        <div class="empty">
            <h2>You haven't made a set yet</h2>
            <p>Cards live inside a set, so start with one of those.</p>
            <a class="btn" href="{{ route('sets.create') }}">Create a set</a>
        </div>
    @else
        <div class="pick">
            @foreach ($sets as $set)
                <a href="{{ route('sets.cards.create', $set) }}">
                    <span>
                        <span class="pick__title">{{ $set->title }}</span>
                        @unless ($set->isPublic())
                            <span class="pick__flag">{{ $set->visibility }}</span>
                        @endunless
                    </span>
                    <span class="pick__meta">
                        {{ number_format($set->cards_count) }} {{ Str::plural('card', $set->cards_count) }}
                    </span>
                </a>
            @endforeach
        </div>
    @endif
@endsection
