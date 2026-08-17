{{--
    The two balance pills, bottom-left on desktop.

    Markup only — every rule lives in layouts/app.blade.php under .wealth,
    #points and #credits, including the 450 and 600x700 breakpoints. The ids
    are the styling contract; don't rename them here.

    balance() reads the denormalised users column, so this costs nothing on a
    page already loading the authenticated user. Do not swap it for
    ledgerSnapshot() or ledgerTotal() — both hit point_transactions, and this
    renders on every page.

    Numbers are unformatted to match the live site, which shows "19580".
--}}
@auth
    @php($user = auth()->user())

    <p id="points"><span>P</span>{{ $user->balance() }}</p>

    {{--
        Credits came from weekly challenges on the legacy site. Nothing in the
        new build mints them — QuizRewards only ever writes CURRENCY_POINTS —
        so this pill would show an imported balance that can never change.

        Hidden until challenges exist. The CSS is already in the layout, so
        turning it on is one config value:

            config/eduver.php  =>  ['show_credits' => true]
    --}}
    @if (config('eduver.show_credits', false))
        <p id="credits"><span>C</span>{{ $user->balance(\App\Models\PointTransaction::CURRENCY_CREDITS) }}</p>
    @endif
@endauth
