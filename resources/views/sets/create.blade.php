@extends('layouts.app')

@section('title', 'New set')

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
</style>
@endpush

@section('content')
    <div class="form-head">
        <p class="eyebrow">Library</p>
        <h1>Start a new set</h1>
    </div>

    @include('partials.set-form', [
        'action' => route('sets.store'),
        'method' => 'post',
        'set' => null,
        'submit' => 'Create set',
    ])
@endsection
