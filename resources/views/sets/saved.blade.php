@extends('layouts.app')

@section('title', 'Saved sets')

@section('masthead')
    <p class="masthead__meta">
        {{ number_format($sets->total()) }} {{ Str::plural('set', $sets->total()) }}
    </p>
@endsection

{{--
    No @@push('styles') here on purpose, same as sets/mine: the head and filter
    chrome come from the layout, the grid brings its own via partials/set-grid.

    The one divergence from mine is the missing sort strip. This page's order
    is the feature — what you touched last, COALESCE(last_studied_at,
    created_at) — and none of StudySet::SORTS can express it, so offering
    "Newest" and "A–Z" would mean the default sort was the one option not
    listed. Search stays; a long library needs it.
--}}

@section('content')
    <div class="page-head">
        <p class="eyebrow">Library</p>
        <h1>Saved sets</h1>
    </div>

    <form class="filters" method="get" action="{{ route('sets.saved') }}" role="search">
        <div class="field">
            <input
                type="search"
                name="q"
                value="{{ $q }}"
                placeholder="Search your library"
                aria-label="Search saved sets"
            >
        </div>

        <button class="btn" type="submit">Search</button>

        @if ($q !== '')
            <a class="btn btn--quiet" href="{{ route('sets.saved') }}">Clear search</a>
        @endif
    </form>

    @if ($sets->isEmpty())
        {{-- Two different empty states. "No results" and "nothing saved yet"
             look identical to the query but mean opposite things. --}}
        <div class="empty">
            @if ($q !== '')
                <h2>Nothing matches that</h2>
                <p>No set in your library has that in its title or description.</p>
            @else
                <h2>Your library is empty</h2>
                <p>Finishing a quiz files that set here. You can also save one from its page to come back to later.</p>
                <a class="btn" href="{{ route('sets.index') }}">Browse sets</a>
            @endif
        </div>
    @else
        {{-- showOwner on, the opposite of sets/mine: these are usually other
             people's sets, so the byline is the difference between their
             "GRE Q-Z" and yours. --}}
        @include('partials.set-grid', ['sets' => $sets, 'showOwner' => true])
    @endif
@endsection
