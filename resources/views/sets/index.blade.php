@extends('layouts.app')

@section('title', 'Browse sets')

@section('masthead')
    <p class="masthead__meta">
        {{ number_format($sets->total()) }} {{ Str::plural('set', $sets->total()) }} in view
    </p>
@endsection

@push('styles')
<style>
    /* Page head, filter chrome and the set grid all moved out of this file:
       the head and filters into the layout's shared bits, the grid into
       partials/set-grid.blade.php. Both are used by sets.mine as well now,
       and a second copy here is the .sort situation again.

       What's left is the one control that genuinely belongs to browse. */

    .starter-toggle {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-family: var(--mono);
        font-size: 11px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--ink-soft);
        cursor: pointer;
        user-select: none;
    }

    .starter-toggle input { accent-color: var(--brand-a); width: 14px; height: 14px; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <p class="eyebrow">Library</p>
        <h1>Browse study sets</h1>
    </div>

    <form class="filters" method="get" action="{{ route('sets.index') }}" role="search">
        <div class="field">
            <input
                type="search"
                name="q"
                value="{{ $q }}"
                placeholder="Search titles, descriptions, authors"
                aria-label="Search study sets"
            >
        </div>

        <div class="field">
            <label for="min">Min cards</label>
            <input type="number" id="min" name="min" min="0" max="1000" value="{{ $min }}">
        </div>

        <input type="hidden" name="sort" value="{{ $sort }}">
        <button class="btn" type="submit">Search</button>

        <label class="starter-toggle">
            <input type="checkbox" name="starter" value="1" @checked($includeStarter)>
            Show starter packs
        </label>

        @if ($q !== '' || $min)
            <a class="btn btn--quiet" href="{{ route('sets.index', array_filter(['sort' => $sort, 'starter' => $includeStarter ? 1 : null])) }}">Clear filters</a>
        @endif

        <div class="sorts">
            @foreach (\App\Models\StudySet::SORTS as $key => $label)
                <a
                    class="sort"
                    href="{{ route('sets.index', array_filter(['q' => $q, 'min' => $min, 'sort' => $key, 'starter' => $includeStarter ? 1 : null])) }}"
                    @if ($sort === $key) aria-current="true" @endif
                >{{ $label }}</a>
            @endforeach
        </div>
    </form>

    @if ($sets->isEmpty())
        <div class="empty">
            <h2>Nothing matches that yet</h2>
            <p>
                @if ($q !== '')
                    Try a shorter search term, or lower the minimum card count.
                @else
                    No sets are visible here. Check what values <code>visibility</code> actually holds.
                @endif
            </p>
        </div>
    @else
        @include('partials.set-grid', ['sets' => $sets])
    @endif
@endsection
