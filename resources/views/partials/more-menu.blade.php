{{--
    resources/views/partials/more-menu.blade.php

    The overflow menu on the phone bar. The live bar carries four controls —
    home, create set, add cards, and this — so everything else in the rail
    moves in here rather than being cut.

    <details> rather than a button and a class toggle, for the same reason
    cards/order renders its rows server-side: the mechanism has to work before
    the script does. A summary is already a real disclosure control — keyboard
    operable, and announced with its expanded state — so the script in the
    layout only adds what the native element doesn't do: dismiss on an outside
    click, and Escape.

    Items come from the layout's $moreItems, which is built from the same
    arrays the rail loops over. A new rail entry therefore appears here on its
    own; nothing needs adding in two places.
--}}
<details class="more" id="more-menu">
    <summary class="more__toggle" title="More" aria-label="More">
        @include('partials.rail-icon', ['name' => 'more'])
    </summary>

    <div class="more__panel">
        <ul class="more__list">
            @foreach ($items as $item)
                @if ($item['route'] && Route::has($item['route']))
                    <li>
                        <a href="{{ route($item['route']) }}">
                            @include('partials.rail-icon', ['name' => $item['icon']])
                            <span>{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endif
                {{-- Unrouted entries are simply absent. The rail keeps its
                     stubs because they hold the grid's shape; a list has no
                     shape to hold, and a dimmed row that does nothing is
                     worse than a shorter menu. --}}
            @endforeach

            @auth
                <li>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">
                            @include('partials.rail-icon', ['name' => 'logout'])
                            <span>Log out</span>
                        </button>
                    </form>
                </li>
            @else
                {{-- Every item above is auth-only and bounces a guest to
                     login, which is the rail's existing behaviour. Offering
                     the destination directly is friendlier than letting them
                     find out by being redirected. --}}
                <li>
                    <a href="{{ route('login') }}">
                        @include('partials.rail-icon', ['name' => 'profile'])
                        <span>Log in</span>
                    </a>
                </li>
            @endauth
        </ul>
    </div>
</details>
