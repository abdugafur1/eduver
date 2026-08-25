<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Accounts that cannot be closed.
 |
 | User::closeAccount() keeps public sets under a tombstone named "Deleted
 | user", which is right for a person and wrong for a content library. The
 | eduver account owns ~82 public sets that are site content rather than
 | somebody's study material; if that button were ever pressed on it, every one
 | of them would survive with no real author and no way to get one back.
 |
 | A column rather than a config list, because this is a property of the
 | account: it survives a rename, it is queryable, and it cannot drift out of
 | sync with the row it describes.
 |
 | Deliberately NOT derived from "owns starter sets" or "has more than N public
 | sets". Both are proxies that would start catching ordinary prolific users as
 | the site grows, and a heuristic that silently blocks account closure is
 | worse than no guard at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_protected')->default(false)->after('must_reset_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_protected');
        });
    }
};
