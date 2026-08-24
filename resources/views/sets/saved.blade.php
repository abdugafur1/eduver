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

    This page used to have no sort strip, on the grounds that its order is the
    feature — what you touched last, COALESCE(last_studied_at, created_at) —
    and none of StudySet::SORTS could express it, so a strip would have left
    the default as the one option not listed. That was the right objection to
    the wrong thing: the fix is naming the order, not hiding it. It's
    `library` now, first in StudySet::LIBRARY_SORTS, and the strip below is
    the same markup as sets/mine with a different map behind it.
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

        <input type="hidden" name="sort" value="{{ $sort }}">
        <button class="btn" type="submit">Search</button>

        @if ($q !== '')
            <a class="btn btn--quiet" href="{{ route('sets.saved', array_filter(['sort' => $sort])) }}">Clear search</a>
        @endif

        <div class="sorts">
            @foreach (\App\Models\StudySet::LIBRARY_SORTS as $key => $label)
                <a
                    class="sort"
                    href="{{ route('sets.saved', array_filter(['q' => $q, 'sort' => $key])) }}"
                    @if ($sort === $key) aria-current="true" @endif
                >{{ $label }}</a>
            @endforeach
        </div>
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
