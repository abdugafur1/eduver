<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Replaces Laravel's stock users migration. Keeps the framework's three
 | tables (users, password_reset_tokens, sessions) so nothing in Auth breaks,
 | and adds the gamification columns the old site kept scattered across
 | cpUsers and cpData.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('username', 30)->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // Daily rollover for streaks and quests happens at the user's
            // local midnight, not the server's. The old site used server time,
            // which quietly broke streaks for anyone outside US hosting hours.
            $table->string('timezone', 64)->default('UTC');

            // Denormalised counters. Source of truth is point_transactions and
            // daily_activities; these are caches written in the same
            // transaction so the leaderboard stays one indexed read.
            $table->unsignedBigInteger('total_points')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedSmallInteger('level')->default(1);
            $table->unsignedSmallInteger('current_streak')->default(0);
            $table->unsignedSmallInteger('longest_streak')->default(0);
            $table->date('last_active_on')->nullable();

            // Set only by the legacy import. The old site echoed passwords
            // into a plaintext cookie, so every migrated account is treated as
            // compromised and must reset before it can be used.
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->boolean('must_reset_password')->default(false);

            $table->timestamps();

            $table->index('total_points');   // global leaderboard
            $table->index(['level', 'xp']);  // level-banded views
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
