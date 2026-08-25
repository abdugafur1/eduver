<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | Turns the guard on for the account it was built for.
 |
 | is_protected defaults to false, so adding the column installed a check that
 | pointed at nothing. This is the other half.
 |
 | A migration rather than a tinker command, because the flag has to be set in
 | every environment and "remember to run this by hand after deploying" is how
 | a guard ends up off in the only environment where it matters.
 |
 | Matched on username, not id: 588 is this machine's dev database and the
 | production row will not share it.
 |
 | A raw statement so it doesn't depend on the User model's fillable rules —
 | is_protected is deliberately absent from $fillable, and a model update()
 | here would silently do nothing.
 |
 | No-ops where the account doesn't exist, which includes every test run.
 */
return new class extends Migration
{
    private const PROTECTED_USERNAMES = ['eduver'];

    public function up(): void
    {
        DB::table('users')
            ->whereIn('username', self::PROTECTED_USERNAMES)
            ->update(['is_protected' => true]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('username', self::PROTECTED_USERNAMES)
            ->update(['is_protected' => false]);
    }
};
