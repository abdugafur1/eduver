@extends('layouts.app')

@section('title', 'Your sets')

@section('masthead')
    <p class="masthead__meta">
        {{ number_format($sets->total()) }} {{ Str::plural('set', $sets->total()) }}
    </p>
@endsection

{{--
    No @@push('styles') here on purpose. The head and filter chrome come from
    the layout, the grid brings its own via partials/set-grid. If this page
    grows a control of its own, that is when a push block earns its place.
--}}

@section('content')
    <div class="page-head">
        <p class="eyebrow">Library</p>
        <h1>Your sets</h1>
    </div>

    {{-- No min-cards filter: that exists to skim 1,400 browse results, not
         your dozen. One less thing to keep in sync with browse. --}}
    <form class="filters" method="get" action="{{ route('sets.mine') }}" role="search">
        <div class="field">
            <input
                type="search"
                name="q"
                value="{{ $q }}"
                placeholder="Search your sets"
                aria-label="Search your sets"
            >
        </div>

        <input type="hidden" name="sort" value="{{ $sort }}">
        <button class="btn" type="submit">Search</button>

        @if ($q !== '')
            <a class="btn btn--quiet" href="{{ route('sets.mine', array_filter(['sort' => $sort])) }}">Clear search</a>
        @endif

        <a class="btn btn--quiet" href="{{ route('sets.create') }}">New set</a>

        <div class="sorts">
            @foreach (\App\Models\StudySet::SORTS as $key => $label)
                <a
                    class="sort"
                    href="{{ route('sets.mine', array_filter(['q' => $q, 'sort' => $key])) }}"
                    @if ($sort === $key) aria-current="true" @endif
                >{{ $label }}</a>
            @endforeach
        </div>
    </form>

    @if ($sets->isEmpty())
        {{-- Two different empty states. "No results" and "no sets at all" look
             identical to the query but mean opposite things to the person. --}}
        <div class="empty">
            @if ($q !== '')
                <h2>Nothing matches that</h2>
                <p>No set of yours has that in its title or description.</p>
            @else
                <h2>You haven't made a set yet</h2>
                <p>A set holds your cards. Starter packs from the old site aren't listed here.</p>
                <a class="btn" href="{{ route('sets.create') }}">Create a set</a>
            @endif
        </div>
    @else
        {{-- showOwner off: every tile here belongs to the viewer, so the name
             is noise — and printing it would mean eager-loading user to say
             the same thing 24 times. --}}
        @include('partials.set-grid', ['sets' => $sets, 'showOwner' => false])
    @endif
@endsection
