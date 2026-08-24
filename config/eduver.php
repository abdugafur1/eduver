<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credits pill
    |--------------------------------------------------------------------------
    |
    | Whether partials/wealth renders the second balance pill.
    |
    | Off until this commit, because nothing minted credits — the legacy import
    | brought balances across and QuizRewards only ever wrote CURRENCY_POINTS,
    | so the pill would have shown a number that could never change. Daily
    | quests pay in credits, so it earns its place now.
    |
    | Note that partials/wealth passes false as config()'s default, so deleting
    | this file hides the pill rather than erroring. That was deliberate while
    | the file didn't exist; it now means a missing config silently hides real
    | balances.
    |
    */

    'show_credits' => true,

];
