<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite (unlike MySQL/InnoDB) does not auto-index foreign key columns, so
 * these hot-path lookups were full table scans:
 *  - guesses.user_id            -> profile stats aggregates
 *  - daily_challenge_guesses.user_id -> daily stats / streaks
 *  - league_rounds(league_id, status) -> leaderboards, current-round
 *  - league_members.user_id     -> "my leagues" listing
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guesses', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('daily_challenge_guesses', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('league_rounds', function (Blueprint $table) {
            $table->index(['league_id', 'status']);
        });

        Schema::table('league_members', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('guesses', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('daily_challenge_guesses', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('league_rounds', function (Blueprint $table) {
            $table->dropIndex(['league_id', 'status']);
        });

        Schema::table('league_members', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
