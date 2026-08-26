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

        /* In the masthead row, in normal flow. This used to be fixed at
           bottom: 10px / left: 96px, which put it permanently over the
           bottom-left of the content column — and there is no free corner to
           move it to: body reserves --rail on the right, .shell is centred in
           what's left, so at ~1190px the content runs edge to edge and both
           bottom corners are page. Fixed chrome there covers whatever happens
           to be scrolled under it, which on sets.show is the Multiple Choice
           button. Padding can't fix that (it only clears the end of the
           document, not every scroll position) and pointer-events can only
           make the covered control clickable-but-invisible.

           The cost is that the balance scrolls away with the masthead instead
           of being permanently on screen. If it has to stay visible, this
           wants position: sticky on .masthead, not a return to fixed. */
        .wealth {
            display: flex;
            width: auto;
            gap: 12px;
            /* .masthead__inner aligns on baseline, which would hang the pills
               off the wordmark's. They're a block of colour, not text on the
               same line. */
            align-self: center;
        }

        /* Flat fills, not gradients — the live pills are solid, and they swap
           colours between the pill and its badge. */
        .wealth > p {
            /* There's no global p reset in this sheet, so these carried the UA
               default 1em — 18px at this font-size — which as flex-item margins
               made the pills' box 36px taller than the pills. Harmless now that
               they're in flow, but it would put 18px of dead air above and
               below them inside the masthead row. */
            margin: 0;
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

        /* Tab / sort links. Lives here rather than in a page's pushed styles
           because both the browse filters and the leaderboard boards use it —
           pushed styles are page-scoped, so the second page to want them gets
           bare anchors. Delete the duplicate .sort block from
           sets/index.blade.php.

           (Careful writing comments in here: Blade compiles its directives
           inside style blocks too, so a bare at-push in this text becomes a
           directive and 500s the page. Escape it as a double-at if you need
           to name one.) */
        .sort {
            font-family: var(--mono);
            font-size: 12px;
            letter-spacing: 0.02em;
            text-decoration: none;
            color: var(--ink-soft);
            padding: 7px 11px;
            border-radius: 3px;
            border: 1px solid transparent;
        }

        .sort:hover { color: var(--brand-b); }

        .sort[aria-current="true"] {
            color: var(--brand-b);
            background: var(--tile);
            border-color: var(--rule);
        }

        /* Page head and filter chrome. Same reasoning as .sort above: browse
           and your-sets both use these, and pushed styles are page-scoped, so
           the second page to want them gets an unstyled bar. Moved out of
           sets/index.blade.php — .browse-head was renamed .page-head on the
           way, since it is no longer only browse. */
        .page-head { margin-bottom: 28px; }

        /*
          Flat magenta, not a gradient clip. Two reasons: the live site's
          section headings are flat magenta and the gradient stays on chrome;
          and a clipped gradient paints across the element box, so on a
          block-level h1 the words only ever saw the first half of the ramp —
          all blue, no pink.
          3.2:1 on paper, so weight goes to 400 to give the strokes body, and
          this colour never appears below 24px.
        */
        .page-head h1 {
            font-family: var(--display);
            font-weight: 400;
            font-size: clamp(30px, 5vw, 46px);
            letter-spacing: -0.022em;
            line-height: 1.08;
            margin: 6px 0 0;
            width: fit-content;
            color: var(--brand-b);
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            padding: 14px 0 20px;
            border-bottom: 1px solid var(--rule);
            margin-bottom: 28px;
        }

        .field {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--tile);
            border: 1px solid var(--rule);
            border-radius: 2.5px;
            padding: 0 12px;
            height: 40px;
        }

        .field:focus-within { border-color: var(--brand-a); }

        .field input {
            border: 0;
            outline: 0;
            background: none;
            font-family: var(--body);
            font-weight: 300;
            font-size: 16px;
            color: var(--ink);
        }

        .field input[type="search"] { width: 260px; max-width: 44vw; }
        .field input[type="number"] { width: 62px; font-family: var(--mono); font-size: 14px; }

        .field label {
            font-family: var(--mono);
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink-soft);
            white-space: nowrap;
        }

        /* The container. .sort, the links inside it, is defined above. */
        .sorts {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            margin-left: auto;
        }

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

        /* ---------- the more-menu ----------
           Only exists on the phone bar: the side rail shows every destination
           already, so there is nothing to overflow. Declared display:none here
           rather than only inside the media query because .rail is a grid with
           named areas at this width, and an unplaced child would auto-place
           itself into a row. */

        .more { display: none; }

        .more__toggle {
            position: relative;
            z-index: 3;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            list-style: none;          /* Firefox */
            transition: opacity 120ms ease;
        }

        /* Safari draws its own disclosure triangle from this pseudo-element,
           which list-style alone doesn't remove. */
        .more__toggle::-webkit-details-marker { display: none; }

        .more__toggle:hover { opacity: 0.75; }

        /* A strip spanning the bar, not a dropdown hanging off the button —
           the live menu's shape. Fixed rather than absolute so it can span the
           viewport while living inside .more: the same 14px inset as .rail's
           own padding, so its ends line up with the bar's end icons.

           72px clears the 60px bar with a 12px gap. */
        /* .extraIcons, ported: same insets, height, radius and shadow. The
           right: 20% is the edge column's lane, and it's the same 20% live
           keeps clear of .content-cont, so the strip and the page agree about
           where that column lives.

           No background — it's a backdrop blur, which is why the crest reads
           through it in the reference shots. That was the thing a solid tile
           couldn't reproduce. */
        .more__panel {
            position: fixed;
            left: 2.5%;
            right: 20%;
            bottom: 80px;
            height: 60px;
            z-index: 3;
            padding: 0 6px;
            border-radius: 5px;
            box-shadow: 2.5px 2.5px 5px 2px var(--shadow);
            backdrop-filter: blur(35px);
            -webkit-backdrop-filter: blur(35px);
            color: var(--ink);
            animation: more-reveal 220ms ease;
        }

        /* Firefox before 103 and anything older: a blur that does nothing
           leaves icons on bare page. */
        @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
            .more__panel { background: var(--tile); }
        }

        /* An animation rather than a transition: details hides its content
           outright, so there is no from-state to transition out of when it
           opens. Closing is therefore instant — the reduced-motion block at
           the foot of this sheet flattens the open too. */
        @keyframes more-reveal {
            from { clip-path: circle(0% at 100% 100%); }
            to   { clip-path: circle(150% at 100% 100%); }
        }

        @keyframes more-reveal--top {
            from { clip-path: circle(0% at 100% 0%); }
            to   { clip-path: circle(150% at 100% 0%); }
        }

        .more__list {
            display: flex;
            align-items: center;
            justify-content: space-around;   /* .extraIcons */
            height: 100%;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        /* space-around does the spacing, so the items keep their own width —
           live's strip is three glyphs at natural size, not three equal cells
           with icons centred in them. */
        .more__list li { flex: 0 0 auto; }

        /* Live carries no labels. They stay in the markup and go to assistive
           tech and to a no-CSS render; only the sighted layout drops them.
           Deleting this rule is most of the way back to a labelled list. */
        .more__list span {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }

        /* Two classes deep, to beat `.rail a` (0,1,1) rather than relying on
           source order — that rule sizes its icons for a grid cell on the side
           rail, which is not what a slot in this strip wants. */
        .rail .more__list a,
        .rail .more__list button {
            display: flex;
            align-items: center;
            justify-content: center;
            justify-self: stretch;
            padding: 4px 10px;
            border-radius: 5px;
            font: inherit;
            text-decoration: none;
            color: var(--ink);
        }

        /* --rule, not --paper: the panel is --tile (#eeeeee) and paper is
           #eeeef0, so a paper hover would be invisible on it. The panel reads
           as floating on its shadow rather than its fill, same as .order__list
           and the set tiles. */
        .rail .more__list a:hover,
        .rail .more__list button:hover {
            opacity: 1;
            background: var(--rule);
        }

        .more__list form { margin: 0; }

        /* No size override anywhere in the ported CSS: the glyphs carry their
           own dimensions (36x48 for the documents, 40x40 for the rest) and
           live scales them with a transform at the extremes rather than
           setting a box. Forcing 28px square was squashing the two documents. */
        .more__list .icon { flex: none; }

        /* The glyphs are built to be the background punched out of the brand
           gradient — right on the rail, wrong here, where inheriting --ink
           turned 'quests' into a black square and the two document icons into
           dark blocks.

           So the two layers swap: the currentColor body takes the gradient,
           the way .chrome does it, and the inner shape is knocked out white.
           That's literally what live ships — its .extraIcons glyphs are a
           separate set of SVGs with gradient bodies and fill="white" inside,
           rather than the rail's white bodies. White rather than --tile
           because the panel has no fill of its own to match.

           Attribute selectors rather than classes on the shapes, so a glyph
           added to rail-icon later is handled without being marked up for it.
           Every inner shape in that file is a url(#paint…) gradient. */
        .more__list .icon [fill="currentColor"] { fill: url(#chromeGrad); }
        .more__list .icon [fill^="url(#paint"] { fill: #fff; }

        .more__list :focus-visible { outline-color: var(--brand-a); }

        /* ---------- narrow ----------
           The live breakpoint is min-width 600 AND min-height 700; below that
           the whole rail becomes a bottom bar. Same idea here, minus the curve,
           which can't survive a 68px strip. */
        /* The live ladder, verbatim: side rail only at 600px AND 700px tall,
           a docked bar below that. header-phone.css owns everything under
           599px; header-tablet.css takes over above it. */
        @media (max-width: 599px), (max-height: 699px) {
            /* 72.5px is where live floats .content-cont above the bar; the
               bar itself is 50px, so that's the bar plus a 22px gap. */
            body { padding-right: 0; padding-bottom: 72px; }

            /* .content-cont is inset right: 20% — the same 20% the strip stops
               at. That's what keeps the page from running under the edge
               column, which ours had nothing reserving space for. */
            main.shell { padding-right: 20%; }

            /* The phone bar has its own curve — .nav-spice, 375x232, cresting
               on the right. The bar is 60px; the SVG is taller and sits behind
               it so the crest rises above the icon row. */
            /* nav, ported: 50px is the grid's last row, and the icons hang
               from the bottom of it rather than centring — which is why live's
               home glyph looks like it's resting on the edge of the screen. */
            .rail {
                top: auto;
                bottom: 0;
                left: 0;
                width: 100%;
                height: 50px;
                padding: 0 10px;
                background: none;
                display: flex;
                align-items: flex-end;
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

            /* The live phone bar carries four: home, create set, add cards,
               and a "more" control that opens the rest. Everything hidden here
               is in that menu — see partials/more-menu — so this is a move,
               not a cut. Six controls in a 375px row is what it replaces.

               Two classes, so these beat `.rail a` (0,1,1). A bare
               .rail__profile loses to it and the icon stays visible. */
            .rail .rail__stub,
            .rail .rail__frSets,
            .rail .rail__profile,
            .rail .rail__logout { display: none; }

            .more { display: block; }

            /* The edge column, ported from .bottom-nav.
               ------------------------------------------------------------
               Live doesn't position these individually: .bottom-nav is a
               285px-tall fixed grid whose LAST ROW IS THE BAR, with a 17.5%
               right column, rows 70 / 1fr / 1fr / 50 reading top to bottom.
               From the bottom that's nav 50, mySets 82.5, quests 82.5,
               leaderboard 70 — and 50+82.5+82.5 = 215, which is why the
               trophy clears the 230px crest and the other two ride it. All
               the offsets I'd been guessing fall out of that one grid.

               Our .rail is a flex bar rather than that grid, so each icon
               becomes its own fixed box of the same row height, centred in
               the same 17.5% column. Same geometry, reached differently. */
            .rail .rail__mySets,
            .rail .rail__quests,
            .rail .rail__leaderboard {
                position: fixed;
                right: 0;
                width: 17.5%;
                display: flex;
                justify-content: center;   /* justify-self: center */
                align-items: center;       /* align-self: center */
            }

            /* The edge rules above are as specific as the stub rule and come
               after it, so an unrouted one of these three would reappear. All
               three are routed today; this keeps that from being load-bearing. */
            .rail span.rail__stub { display: none; }

            .rail .rail__mySets { bottom: 50px;  height: 82.5px; }
            .rail .rail__quests { bottom: 132.5px; height: 82.5px; }

            /* align-self: flex-start on live, i.e. the top of its 70px row. */
            .rail .rail__leaderboard {
                bottom: 215px;
                height: 70px;
                align-items: flex-start;
            }

            /* Fills aren't in what came across — header-comp.css arrived empty
               — so these stay as measured off the reference: white on the
               crest for the two that sit on it, gradient for the trophy, which
               the grid puts above it. */
            .rail .rail__leaderboard .icon [fill="currentColor"] { fill: url(#chromeGrad); }
            .rail .rail__leaderboard .icon [fill^="url(#paint"] { fill: var(--paper); }

            /* Empty when neither create route is wired — otherwise it still
               takes a flex slot and skews the spacing. */
            .rail__create:empty { display: none; }

            .rail__create {
                flex-direction: row;
                gap: 18px;
                justify-content: flex-start;
            }

            /* No .wealth override here any more — it rides in the masthead,
               which is already in flow above the content at every width. */

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
            /* Fixed, now that the shape is. The band's lower edge runs between
               y=63 and y=94 across the width of a 232-tall viewBox, and the
               shape no longer grows with the viewport, so there's nothing left
               to scale against — the vw expression that used to be here just
               pushed content further down the wider the window got.

               Live starts .content-cont at 67.5px, i.e. tucked under the band
               rather than below it, because its content scrolls in its own box
               over the top of the crest. Ours is in normal flow, so it clears
               the deepest point instead. */
            body { padding-top: 112px; padding-bottom: 0; }

            /* 60px row here, and the icons hang from the top instead — the bar
               is docked to the top of the screen in this band. */
            .rail {
                top: 0;
                bottom: auto;
                height: 60px;
                padding-top: 10px;
                align-items: flex-start;
            }

            .rail__shape--bottom { display: none; }

            /* Fixed 232px tall, stretched horizontally — the same shape as
               .nav-spice at the bottom, and for the same reason.

               An earlier note here had this keeping its 451:232 ratio because
               .nav-spice-top sets no height in CSS. It doesn't need to: the
               SVG carries height="232" as an attribute, so only the width is
               overridden and the height stays put. With preserveAspectRatio
               ="none" the artwork stretches sideways to fill it, which is the
               intent — aspect-ratio made it grow downward instead, 308px deep
               at 599px, which is what was drowning the trophy. */
            .rail__shape--top {
                display: block;
                top: 0;
                bottom: auto;
                width: 100%;
                height: 232px;
                pointer-events: none;
            }

            /* The bar is at the top in this band, so the panel has to fall
               out of it rather than rise from it — and the reveal has to
               originate from the top corner to match. */
            /* .extraIcons at this width: top: 80px, same insets. */
            .more__panel {
                bottom: auto;
                top: 80px;
                animation-name: more-reveal--top;
            }

            /* The column follows the bar to the top rather than staying at the
               bottom of the page, riding the top crest's tail the way it rides
               the bottom crest below 450px. Same offsets, same white fill,
               measured from the other edge — the shape is 451:232 and solid
               down its right side to about 215/232, so 192 + a 34px icon still
               lands on blue.

               Which also flips the reading order, correctly: the column always
               starts next to the bar and grows away from it, so your sets is
               nearest the bar in both bands and the rail's own top-to-bottom
               order comes out right here. */
            /* Live's second .bottom-nav: 305px tall from the top, a 12.5%
               column, rows 60 / 1fr / 1fr / 1fr with the bar first — so three
               equal 81.67px rows under a 60px bar, and the order reverses
               because the grid is read downward from the bar instead of upward
               to it. That's the flip I'd arrived at by eye. */
            .rail .rail__mySets,
            .rail .rail__quests,
            .rail .rail__leaderboard {
                width: 12.5%;
                bottom: auto;
                height: 81.67px;
            }

            .rail .rail__mySets      { top: 60px; }
            .rail .rail__quests      { top: 141.67px; }

            /* align-self: flex-end here, the opposite end of its row to the
               band below — live sets it in both places. */
            .rail .rail__leaderboard {
                top: 223.33px;
                align-items: flex-end;
            }

            /* Only the current-page icon needs moving now; the pills sit in
               the masthead, which the body padding-top above already clears. */
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

    {{-- Alpine from the bundle, not the CDN: pinned to package.json rather
         than floating on 3.x.x, and no third-party request per page load.
         @vite emits type="module", which defers — so the alpine:init
         listeners pushed by sets.show are registered before Alpine.start()
         runs. Only the JS: resources/css/app.css is Breeze's Tailwind entry
         and its Preflight reset would flatten the stylesheet above. --}}
    @vite(['resources/js/app.js'])
    @stack('scripts')
</head>
<body>
    <header class="masthead">
        <div class="shell masthead__inner">
            <a class="wordmark" href="{{ route('sets.index') }}">edu<span>ver</span></a>

            {{-- Default rather than opt-in. Per-view @section('wealth') still
                 wins, but a page that forgets it gets the pills instead of
                 silently losing them. Sits between the wordmark and the meta
                 slot, which keeps its margin-left: auto and stays right. --}}
            @hasSection('wealth')
                <div class="wealth">@yield('wealth')</div>
            @else
                @auth
                    <div class="wealth">@include('partials.wealth')</div>
                @endauth
            @endif

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
            // Route::has() knows nothing about middleware, so this is live for
            // guests too and bounces them to login. Every other rail item is
            // public; make it auth()->check() ? 'sets.mine' : null to keep it
            // a stub when logged out.
            ['icon' => 'my-sets',    'label' => 'Your sets',    'area' => 'mySets',  'route' => 'sets.mine'],
            ['icon' => 'fr-sets',    'label' => 'Saved sets',   'area' => 'frSets',  'route' => 'sets.saved'],
        ];
        $railLower = [
            ['icon' => 'quests',     'label' => 'Daily quests', 'area' => 'quests',  'route' => 'quests.index'],
            ['icon' => 'profile',    'label' => 'Profile',      'area' => 'profile', 'route' => 'profile.edit'],
        ];

        /*
         | How the rail divides at phone width, which is three ways rather than
         | two. The bar takes home and the two create icons. These three stay
         | out on the page edge as their own column — that's what leaves live's
         | menu holding only three icons, so it's the reason the strip is the
         | shape it is, not a coincidence of how many routes exist.
         |
         | Whatever is in neither group falls into the menu. Derived rather than
         | listed again, so a new rail entry lands somewhere by default.
         |
         | The edge column's positions are in the stylesheet by area class, so
         | this list and that block have to agree — see .rail__mySets there.
         */
        $edgeAreas = ['mySets', 'quests', 'leaderboard'];

        // Home keeps its own slot in the bar; leaderboard is appended because
        // it's rendered outside the loop below, off on the rail's bottom blob.
        $moreItems = array_values(array_filter(
            array_merge(
                array_slice($railItems, 1),
                $railLower,
                [['icon' => 'leaderboard', 'label' => 'Leaderboard', 'area' => 'leaderboard', 'route' => 'leaderboard.index']],
            ),
            fn (array $item) => ! in_array($item['area'], $edgeAreas, true),
        ));
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

        {{-- Last child, so it lands at the right end of the phone bar's
             space-between row — the corner the reveal grows from. Hidden at
             rail widths, where nothing needs overflowing. --}}
        @include('partials.more-menu', ['items' => $moreItems])
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

    <script>
        /*
            Enhancement only. <details> opens and closes on its own, with the
            keyboard, before this runs — what's added here is the behaviour a
            disclosure widget doesn't have but a menu is expected to: dismiss
            on an outside tap, and on Escape.

            Vanilla to match cards/order, the app's only other script. Alpine
            is loaded in the head and currently drives nothing; this would be
            three attributes in Alpine, but reaching for it here would make the
            layout the first file that needs it.
        */
        (() => {
            const menu = document.getElementById('more-menu');

            if (! menu) return;

            const close = () => { menu.open = false; };

            // Pointerdown rather than click: closing on the press means the
            // panel is gone before whatever was underneath it reacts.
            document.addEventListener('pointerdown', (event) => {
                if (menu.open && ! menu.contains(event.target)) close();
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && menu.open) {
                    close();
                    // Focus would otherwise be left on a hidden element.
                    menu.querySelector('summary')?.focus();
                }
            });

            // Safari restores a page from the back-forward cache with the
            // details still open, over a page you've already navigated away
            // from and back to.
            menu.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', close);
            });
        })();
    </script>
</body>
</html>
