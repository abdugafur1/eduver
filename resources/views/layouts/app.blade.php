<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sets') — eduver</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=heebo:300,400,500,700|ibm-plex-mono:400,500" rel="stylesheet">

    {{--
      Values here are taken from the live CSS, not matched by eye:
        #eeeef0  page          (my-fr-sets.css body)
        #eeeeee  tile fill     (.menu-div) — a hair darker than the page
        #ababad  shadow        (.menu-div, #options a)
        #0064ff  links, page titles   (.title, .set-name)
        #c864c8  section labels, and every link's hover  (.menu-cont p, .menu-div a:hover)
        #ffa500  hover on gradient fills (#options a:hover, .submitBtn:hover)

      The rail gradient runs LEFT to RIGHT (nav-ipad.svg: x1=133 blue, x2=348
      magenta), so blue lands on the inner curve and magenta at the screen edge.

      The live site declares no font-family at all — every page renders in the
      browser's default serif. Heebo is a deliberate addition, not a port.
    --}}
    <style>
        :root {
            --brand-a: #0064ff;
            --brand-b: #c864c8;
            --brand-fill: linear-gradient(135deg, #0064ff 0%, #c864c8 100%);
            --brand-hover: #ffa500;

            --paper: #eeeef0;
            --tile: #eeeeee;
            --shadow: #ababad;
            --ink: #14171f;
            --ink-soft: #5b6474;
            --rule: #dcdce0;

            /* Kept off the brand ramp: flags private visibility, missing
               definitions and high-miss cards. The live site's own accent for
               this job is #ffa500, which is 1.9:1 on paper — fine as text on a
               gradient button, unreadable as a flag. This is the same hue at a
               contrast that works. */
            --gold: #9a6f16;

            --body: 'Heebo', 'Helvetica Neue', Arial, sans-serif;
            --display: 'Heebo', 'Helvetica Neue', Arial, sans-serif;
            --mono: 'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, monospace;

            --shell: 1180px;
            --rail: 250px;   /* .ipad-nav width, verbatim */
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: var(--body);
            font-weight: 300;
            font-size: 17px;
            line-height: 1.6;
            padding-right: var(--rail);
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }

        :focus-visible {
            outline: 2px solid var(--brand-a);
            outline-offset: 2px;
            border-radius: 2px;
        }

        .shell {
            max-width: var(--shell);
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ---------- masthead ---------- */

        /* Full-bleed: body reserves --rail on the right, which left a grey
           notch between the end of the white band and the start of the rail's
           narrow top section. Cancelling the padding here runs the white to the
           viewport edge and lets the rail float over it, which is what the rail
           does to everything else anyway. */
        .masthead {
            border-bottom: 3px solid transparent;
            border-image: var(--brand-fill) 1;
            background: #fff;
            margin-right: calc(-1 * var(--rail));
            padding-right: var(--rail);
        }

        .masthead__inner {
            display: flex;
            align-items: baseline;
            gap: 20px;
            flex-wrap: wrap;
            padding-block: 22px;
        }

        .wordmark {
            font-weight: 700;
            font-size: 26px;
            letter-spacing: -0.02em;
            text-decoration: none;
        }

        .wordmark span { color: var(--brand-a); }

        @supports (-webkit-background-clip: text) or (background-clip: text) {
            .wordmark span {
                background: var(--brand-fill);
                -webkit-background-clip: text;
                background-clip: text;
                color: transparent;
            }
        }

        .masthead__meta {
            font-family: var(--mono);
            font-size: 12px;
            letter-spacing: 0.04em;
            color: var(--ink-soft);
            margin-left: auto;
        }

        /* ---------- the rail ----------
           Structure copied from header-tablet.css: a 250px fixed grid, icons in
           the right 70px column, the left 180px given over to the curve's swing
           and the leaderboard icon sitting out on the bottom blob. */

        .rail {
            position: fixed;
            top: 0; right: 0;
            height: 100%;
            width: var(--rail);
            z-index: 20;
            padding-top: 5px;
            color: var(--paper);   /* icon bodies read as the page punched out */

            display: grid;
            grid-template-columns: 180px 70px;
            grid-template-rows: 1fr 1fr 1fr 1.5fr 1fr 1fr 1fr;
            grid-template-areas:
                ". home"
                ". mySets"
                ". frSets"
                ". create"
                ". quests"
                ". profile"
                "leaderboard logout";
        }

        .rail__shape {
            position: absolute;
            top: 0; left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        .rail__shape--bottom,
        .rail__shape--top { display: none; }

        .rail a,
        .rail button {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            padding: 0;
            background: none;
            color: inherit;
            cursor: pointer;
            justify-self: center;
            transition: opacity 120ms ease;
        }

        .rail a:hover,
        .rail button:hover { opacity: 0.75; }

        .rail :focus-visible { outline-color: #fff; }

        .rail__home       { grid-area: home;        align-self: flex-start; }
        .rail__mySets     { grid-area: mySets;      align-self: flex-start; }
        .rail__frSets     { grid-area: frSets;      align-self: flex-start; }
        .rail__quests     { grid-area: quests;      align-self: flex-end; }
        .rail__profile    { grid-area: profile;     align-self: flex-end; }
        .rail__logout     { grid-area: logout;      align-self: flex-end; }
        .rail__leaderboard{ grid-area: leaderboard; align-self: flex-end; }

        /* Two icons share this row on the live rail, stacked. */
        .rail__create {
            grid-area: create;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
        }

        /* Not routed yet: stays visible so the rail keeps its shape, but inert.
           Delete the fallback branch in the loop once everything's wired.
           (Careful writing directive names in here — Blade scans CSS comments
           too, so an at-else in this block compiles into a real directive.) */
        .rail__stub {
            position: relative;
            z-index: 2;
            justify-self: center;
            display: inline-flex;
            opacity: 0.4;
        }

        /* ---------- bottom-left chrome ----------
           .current-icon on the live site: the icon for the page you're on,
           pinned bottom-left and tinted blue because it sits off the rail. */

        .chrome {
            position: fixed;
            left: 0;
            bottom: 0;
            z-index: 20;
            padding: 10px 15px;
            color: var(--brand-a);   /* fallback if the gradient def is missing */
        }

        /* The live current-icon is gradient-filled, not flat. CSS beats a
           presentation attribute, so this repaints only the fill="currentColor"
           shapes and leaves each icon's own inner gradient alone. */
        .chrome .icon [fill="currentColor"],
        .chrome .icon path[fill="currentColor"] { fill: url(#chromeGrad); }

        .wealth {
            position: fixed;
            bottom: 10px;
            left: 14%;
            z-index: 20;
            width: 250px;
            display: flex;
            justify-content: space-between;
        }

        /* Flat fills, not gradients — the live pills are solid, and they swap
           colours between the pill and its badge. */
        .wealth > p {
            padding: 5px 15px 5px 10px;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            font-size: 18px;
            color: var(--paper);
        }

        #points  { width: 125px; background-color: var(--brand-a); }
        #credits { width: 105px; background-color: var(--brand-b); }

        #points span,
        #credits span {
            font-size: 12px;
            color: var(--paper);
            border-radius: 50%;
            padding: 1.5px 4px;
            align-self: center;
        }

        #points span  { background-color: var(--brand-b); }
        #credits span { background-color: var(--brand-a); }

        /* ---------- shared bits ---------- */

        .eyebrow {
            font-family: var(--mono);
            font-size: 11px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--ink-soft);
        }

        .count {
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 12px;
            color: var(--ink-soft);
        }

        .btn {
            height: 40px;
            padding: 0 18px;
            border: 0;
            background: var(--brand-fill);
            color: var(--paper);
            border-radius: 5px;
            box-shadow: 2.5px 3px 6px var(--shadow);
            font-family: var(--body);
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
        }

        .btn:hover,
        .btn:focus,
        .btn:active { color: var(--brand-hover); }

        .btn--quiet {
            height: 40px;
            padding: 0 12px;
            background: none;
            box-shadow: none;
            color: var(--ink-soft);
            border: 0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .btn--quiet:hover { color: var(--brand-b); }

        .empty {
            border-radius: 2.5px;
            padding: 56px 24px;
            text-align: center;
            background: var(--tile);
            box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
        }

        .empty h2 {
            font-weight: 400;
            font-size: 22px;
            color: var(--brand-b);
            margin: 0 0 6px;
        }

        .empty p { margin: 0; color: var(--ink-soft); }

        main { padding-block: 36px 96px; }

        .pagination { margin-top: 40px; font-family: var(--mono); font-size: 13px; }
        .pagination svg { width: 16px; height: 16px; }

        /* ---------- narrow ----------
           The live breakpoint is min-width 600 AND min-height 700; below that
           the whole rail becomes a bottom bar. Same idea here, minus the curve,
           which can't survive a 68px strip. */
        /* The live ladder, verbatim: side rail only at 600px AND 700px tall,
           a docked bar below that. header-phone.css owns everything under
           599px; header-tablet.css takes over above it. */
        @media (max-width: 599px), (max-height: 699px) {
            body { padding-right: 0; padding-bottom: 92px; }

            /* The phone bar has its own curve — .nav-spice, 375x232, cresting
               on the right. The bar is 60px; the SVG is taller and sits behind
               it so the crest rises above the icon row. */
            .rail {
                top: auto;
                bottom: 0;
                left: 0;
                width: 100%;
                height: 60px;
                padding: 0 14px;
                background: none;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .rail__shape--side { display: none; }

            /* .nav-spice is pinned at 230px on the live site — a fixed height,
               unlike the top one. */
            .rail__shape--bottom {
                display: block;
                top: auto;
                bottom: 0;
                height: 230px;
                pointer-events: none;
            }

            /* The live phone bar carries four: home, create set, add cards, and
               a "more" control that opens the rest. Without that menu the stubs
               are dead weight in a 375px row. */
            /* Two classes, so these beat `.rail a` (0,1,1). A bare
               .rail__profile loses to it and the icon stays visible. */
            .rail .rail__stub,
            .rail .rail__quests,
            .rail .rail__profile,
            .rail .rail__leaderboard { display: none; }

            /* Empty when neither create route is wired — otherwise it still
               takes a flex slot and skews the spacing. */
            .rail__create:empty { display: none; }

            .rail__create {
                flex-direction: row;
                gap: 18px;
                justify-content: flex-start;
            }

            /* Pills go to the top on phones, since the bar has the bottom. */
            .wealth {
                position: fixed;
                top: 10px;
                bottom: auto;
                left: 12px;
                width: auto;
                gap: 10px;
            }

            .chrome {
                top: 4px;
                bottom: auto;
                left: auto;
                right: 0;
            }

            .masthead { margin-right: 0; padding-right: 0; }
        }

        /* 450-599px: same bar, docked to the top, with its own shape.
           header-phone.css does exactly this at min-width 450 — .nav-spice
           hides, .nav-spice-top shows, and .bottom-nav moves to top: 0. */
        @media (min-width: 450px) and (max-width: 599px),
               (min-width: 450px) and (max-height: 699px) {
            /* The band's lower edge lands at roughly 40% of the shape height,
               and the shape is 232/451 of the viewport width — so ~20.6vw.
               Content starts below that instead of at a fixed offset. */
            body { padding-top: calc(20.6vw + 18px); padding-bottom: 0; }

            .rail {
                top: 0;
                bottom: auto;
            }

            .rail__shape--bottom { display: none; }

            /* .nav-spice-top sets width: 100% and no height, so it keeps its
               451:232 ratio and grows with the viewport — ~251px tall at 489px
               wide. Forcing a height here is what flattened it. */
            .rail__shape--top {
                display: block;
                top: 0;
                bottom: auto;
                width: 100%;
                height: auto;
                aspect-ratio: 451 / 232;
                pointer-events: none;
            }

            /* Pills clear the bar; the current-page icon takes the corner the
               bar has vacated. */
            .wealth {
                top: calc(20.6vw + 26px);
                left: 14px;
            }

            .chrome {
                top: auto;
                bottom: 0;
                left: auto;
                right: 0;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>

    @stack('styles')

    {{-- Alpine from the CDN: no build step, and it survives you wiring Vite
         later. defer matters — Alpine has to initialise after the DOM it
         binds to exists. --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @stack('scripts')
</head>
<body>
    <header class="masthead">
        <div class="shell masthead__inner">
            <a class="wordmark" href="{{ route('sets.index') }}">edu<span>ver</span></a>
            @hasSection('masthead')
                @yield('masthead')
            @endif
        </div>
    </header>

    <main class="shell">
        @yield('content')
    </main>

    @php
        // Live rail, in its original order. Point `route` at a real route name
        // to make an icon live; leave it null and it renders as a dimmed stub.
        $railItems = [
            ['icon' => 'home',       'label' => 'Home',         'area' => 'home',    'route' => 'sets.index'],
            ['icon' => 'my-sets',    'label' => 'Your sets',    'area' => 'mySets',  'route' => null],
            ['icon' => 'fr-sets',    'label' => 'Shared sets',  'area' => 'frSets',  'route' => null],
        ];
        $railLower = [
            ['icon' => 'quests',     'label' => 'Daily quests', 'area' => 'quests',  'route' => null],
            ['icon' => 'profile',    'label' => 'Profile',      'area' => 'profile', 'route' => 'profile.edit'],
        ];
    @endphp

    <nav class="rail" aria-label="Main">
        {{-- nav-ipad.svg, path and gradient verbatim. preserveAspectRatio="none"
             is how the live site stretches 348x1133 into a 250px column. --}}
        {{-- nav-ipad.svg: path and gradient verbatim. preserveAspectRatio="none"
             is how the live site stretches 348x1133 into a 250px column. --}}
        <svg class="rail__shape rail__shape--side" viewBox="0 0 348 1133" preserveAspectRatio="none"
             fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M253 1057.5C280 1011.5 186.5 4.22613e-05 253 7.35998e-06L348 7.35998e-06L348 1133H44.4999C44.4999 1133 -26.5646 1105.84 12 1057.5C50.5646 1009.16 226 1103.5 253 1057.5Z" fill="url(#railGrad)"/>
            <defs>
                <linearGradient id="railGrad" x1="133.14" y1="566.5" x2="348" y2="566.5" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#0064FF"/>
                    <stop offset="1" stop-color="#C864C8"/>
                </linearGradient>
            </defs>
        </svg>

        {{-- nav-top.svg, 451x232: the bar docked to the top between 450 and
             599px. Gradient runs bottom-to-top, blue at the bottom. --}}
        <svg class="rail__shape rail__shape--top" viewBox="0 0 451 232" preserveAspectRatio="none"
             fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M395 63.1058C378.764 24.0402 9.52153e-06 94.6585 2.9297e-06 63.1058V0H451V214.86C451 214.86 408.243 253.425 389 214.86C369.757 176.295 411.236 102.171 395 63.1058Z" fill="url(#topGrad)"/>
            <defs>
                <linearGradient id="topGrad" x1="225.5" y1="214.86" x2="225.5" y2="-0.000200104" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#0064FF"/>
                    <stop offset="1" stop-color="#C864C8"/>
                </linearGradient>
            </defs>
        </svg>

        {{-- nav-spice, the phone bar's crest. Vertical gradient here, unlike
             the side rail's horizontal one. --}}
        <svg class="rail__shape rail__shape--bottom" viewBox="0 0 375 232" preserveAspectRatio="none"
             fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M311 169C297.5 208 6.49024e-06 137.5 0 169V232H375V17.4998C375 17.4998 327 -21.0002 311 17.4998C295 55.9998 324.5 130 311 169Z" fill="url(#spiceGrad)"/>
            <defs>
                <linearGradient id="spiceGrad" x1="187.5" y1="17.5" x2="187.5" y2="232" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#0064FF"/>
                    <stop offset="1" stop-color="#C864C8"/>
                </linearGradient>
            </defs>
        </svg>

        @foreach (array_merge($railItems, $railLower) as $item)
            @if ($item['route'] && Route::has($item['route']))
                <a class="rail__{{ $item['area'] }}" href="{{ route($item['route']) }}" aria-label="{{ $item['label'] }}">
                    @include('partials.rail-icon', ['name' => $item['icon']])
                </a>
            @else
                <span class="rail__stub rail__{{ $item['area'] }}" title="{{ $item['label'] }} — not wired up yet" aria-hidden="true">
                    @include('partials.rail-icon', ['name' => $item['icon']])
                </span>
            @endif
        @endforeach

        <div class="rail__create">
            @if (Route::has('sets.create'))
                <a href="{{ route('sets.create') }}" aria-label="Create a set">
                    @include('partials.rail-icon', ['name' => 'add-set'])
                </a>
            @endif
            @if (Route::has('cards.create'))
                <a href="{{ route('cards.create') }}" aria-label="Add cards">
                    @include('partials.rail-icon', ['name' => 'add-subset'])
                </a>
            @endif
        </div>

        @if (Route::has('leaderboard.index'))
            <a class="rail__leaderboard" href="{{ route('leaderboard.index') }}" aria-label="Leaderboard">
                @include('partials.rail-icon', ['name' => 'leaderboard'])
            </a>
        @else
            <span class="rail__stub rail__leaderboard" title="Leaderboard — not wired up yet" aria-hidden="true">
                @include('partials.rail-icon', ['name' => 'leaderboard'])
            </span>
        @endif

        @auth
            <form class="rail__logout" method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit" aria-label="Log out">
                    @include('partials.rail-icon', ['name' => 'logout'])
                </button>
            </form>
        @endauth
    </nav>

    <svg width="0" height="0" aria-hidden="true" style="position:absolute">
        <defs>
            <linearGradient id="chromeGrad" x1="0" y1="0" x2="0.35" y2="1">
                <stop stop-color="#0064FF"/>
                <stop offset="1" stop-color="#C864C8"/>
            </linearGradient>
        </defs>
    </svg>

    @hasSection('current-icon')
        <div class="chrome">@yield('current-icon')</div>
    @endif

    @hasSection('wealth')
        <div class="wealth">@yield('wealth')</div>
    @endif
</body>
</html>
