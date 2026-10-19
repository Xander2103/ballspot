<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived per-(competition, player) standing (plan §4.6, §5.6). Ranking order
 * total_score desc → score_reached_at asc → attempts_used asc (D17); the rank
 * is 1 + COUNT(strictly better) on the composite index — portable, no window
 * functions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_competition_entries')) {
            return;
        }

        Schema::create('club_competition_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_competition_id')->constrained('club_competitions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('total_score')->default(0);
            $table->unsignedInteger('packs_counted')->default(0);
            $table->unsignedInteger('packs_completed')->default(0);
            $table->unsignedInteger('attempts_used')->default(0);
            $table->timestamp('score_reached_at')->nullable();
            $table->timestamps();

            $table->unique(['club_competition_id', 'user_id'], 'cce_competition_user_unique');
            $table->index(['club_competition_id', 'total_score', 'score_reached_at', 'attempts_used'], 'cce_ranking_index');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_competition_entries');
    }
};
