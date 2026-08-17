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
@endsection
