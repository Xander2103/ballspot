<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only XP ledger. Each row is one XP award with its source, so a
     * player's total XP (and rank) is auditable and extendable. Rows are never
     * deleted when a user is anonymized — rank/leaderboard history stays intact.
     */
    public function up(): void
    {
        Schema::create('xp_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // e.g. daily_guess, tournament_guess, badge_unlock, streak_bonus,
            // tournament_win, weekly_finish, admin_adjustment.
            $table->string('source_type', 40);
            // The originating row id (guess id, badge id, streak milestone, …).
            $table->unsignedBigInteger('source_id')->nullable();
            $table->integer('amount'); // signed; MVP awards are positive.
            $table->string('reason');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            // At most one XP event per (user, source_type, source_id). NULL
            // source_id rows (e.g. admin adjustments) are exempt — SQL treats
            // NULLs as distinct — so unlimited manual adjustments are allowed.
            $table->unique(['user_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xp_events');
    }
};
