@extends('layouts.app')

@section('title', 'Edit '.$set->title)

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
        max-width: 22ch;
    }


    /* Not on the brand ramp and not --gold, which already means "flagged" in
    three places. Local to this page; promote to :root if delete appears
    anywhere else. */
    .danger {
        margin-top: 34px;
        padding-top: 22px;
        border-top: 1px solid var(--rule);
    }

    .danger p { font-size: 14px; color: var(--ink-soft); margin: 0 0 12px; max-width: 46ch; }

    .btn--danger {
        height: 40px;
        padding: 0 18px;
        border: 1px solid var(--rule);
        border-radius: 5px;
        background: none;
        color: #b3261e;
        font-family: var(--body);
        font-weight: 500;
        font-size: 14px;
        cursor: pointer;
    }

    .btn--danger:hover { background: #b3261e; border-color: #b3261e; color: var(--paper); }
</style>
@endpush

@section('content')
    <div class="form-head">
        <p class="eyebrow">{{ $set->slug }} &middot; {{ number_format($set->cards_count) }} {{ Str::plural('card', $set->cards_count) }}</p>
        <h1>{{ $set->title }}</h1>
    </div>

    @include('partials.set-form', [
        'action' => route('sets.update', $set),
        'method' => 'patch',
        'set' => $set,
        'submit' => 'Save changes',
    ])

    <div class="danger">
        <p>Deleting removes this set from your sets and from browse. Cards go with it.</p>
        <form method="post" action="{{ route('sets.destroy', $set) }}"
            onsubmit="return confirm(@js('Delete “'.$set->title.'” and its '.$set->cards_count.' cards?'))">
            @csrf
            @method('delete')
            <button type="submit" class="btn--danger">Delete this set</button>
        </form>
    </div>
@endsection
