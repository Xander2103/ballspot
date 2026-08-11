<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The friend-suggestion "active player" signal scans daily_challenge_guesses by
 * submitted_at (FriendSuggestionService), and that column had no index — a full
 * table scan on every /friends/suggestions call as guess volume grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_challenge_guesses', function (Blueprint $table) {
            $table->index('submitted_at', 'dcg_submitted_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('daily_challenge_guesses', function (Blueprint $table) {
            $table->dropIndex('dcg_submitted_at_index');
        });
    }
};
