<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sets') — eduver</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,800|newsreader:400,400i,600|ibm-plex-mono:400,500" rel="stylesheet">

    {{--
      Plain CSS on purpose: this renders correctly the moment you hit /sets,
      with no Vite build in the loop. Port to Tailwind whenever you wire up
      the asset pipeline — the token names below map 1:1 to @theme vars.
    --}}
    <style>
        :root {
            --ink: #151a21;
            --ink-soft: #5c6673;
            --paper: #f4f6f8;
            --surface: #ffffff;
            --rule: #dfe4ea;
            --pine: #1f5f52;
            --pine-wash: #e8f0ed;
            --gold: #9a6f16;

            --display: 'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif;
            --body: 'Newsreader', Georgia, 'Times New Roman', serif;
            --mono: 'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, monospace;

            --shell: 1180px;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: var(--body);
            font-size: 17px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }

        :focus-visible {
            outline: 2px solid var(--pine);
            outline-offset: 2px;
            border-radius: 2px;
        }

        .shell {
            max-width: var(--shell);
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ---------- masthead ---------- */

        .masthead {
            border-bottom: 1px solid var(--rule);
            background: var(--surface);
        }

        .masthead__inner {
            display: flex;
            align-items: baseline;
            gap: 20px;
            flex-wrap: wrap;
            padding-block: 22px;
        }

        .wordmark {
            font-family: var(--display);
            font-weight: 800;
            font-size: 26px;
            letter-spacing: -0.03em;
            text-decoration: none;
        }

        .wordmark span { color: var(--pine); }

        .masthead__meta {
            font-family: var(--mono);
            font-size: 12px;
            letter-spacing: 0.04em;
            color: var(--ink-soft);
            margin-left: auto;
        }

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
            border: 1px solid var(--pine);
            background: var(--pine);
            color: #fff;
            border-radius: 3px;
            font-family: var(--display);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }

        .btn--quiet {
            background: none;
            color: var(--ink-soft);
            border-color: transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .btn--quiet:hover { color: var(--ink); }

        .empty {
            border: 1px dashed var(--rule);
            border-radius: 4px;
            padding: 56px 24px;
            text-align: center;
            background: var(--surface);
        }

        .empty h2 {
            font-family: var(--display);
            font-weight: 600;
            font-size: 20px;
            margin: 0 0 6px;
        }

        .empty p { margin: 0; color: var(--ink-soft); }

        main { padding-block: 36px 72px; }

        .pagination { margin-top: 40px; font-family: var(--mono); font-size: 13px; }
        .pagination svg { width: 16px; height: 16px; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>

    @stack('styles')
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
</body>
</html>
