<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Final standings for a completed tournament — one row per member, written
     * once when the tournament completes. Virtual recognition only (no prizes/
     * money). Historical; never hard-deleted with the league in normal flow.
     */
    public function up(): void
    {
        Schema::create('tournament_finishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('placement');        // 1 = winner
            $table->integer('total_score');
            $table->unsignedInteger('rounds_played')->nullable();
            $table->json('metadata')->nullable();        // e.g. total_players
            $table->timestamps();

            // A user has exactly one finish per tournament — makes completion idempotent.
            $table->unique(['league_id', 'user_id']);
            $table->index(['user_id', 'placement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_finishes');
    }
};
