@if ($paginator->hasPages())
    <style>
        .pager { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .pager a, .pager span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            border: 1px solid var(--rule);
            border-radius: 3px;
            background: var(--surface);
            color: var(--ink-soft);
            text-decoration: none;
            font-variant-numeric: tabular-nums;
        }
        .pager a:hover { color: var(--ink); border-color: var(--ink-soft); }
        .pager .is-current { background: var(--pine); border-color: var(--pine); color: #fff; }
        .pager .is-disabled { opacity: 0.4; }
        .pager .is-gap { border-color: transparent; background: none; }
        .pager__total { margin-left: 10px; color: var(--ink-soft); }
    </style>

    <nav class="pager" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="is-disabled" aria-disabled="true">&larr;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">&larr;</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="is-gap" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">&rarr;</a>
        @else
            <span class="is-disabled" aria-disabled="true">&rarr;</span>
        @endif

        <span class="pager__total">
            {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}
            of {{ number_format($paginator->total()) }}
        </span>
    </nav>
@endif
