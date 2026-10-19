<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived per-(competition, player, pack) result (plan §4.6, §5.5). Rebuilt
 * from attempts + guesses on every scored guess and by
 * ballspot:clubs:rebuild-standings — never incremented, so it is idempotent
 * and race-free.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_competition_pack_results')) {
            return;
        }

        Schema::create('club_competition_pack_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_competition_id')->constrained('club_competitions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('challenge_pack_id')->constrained('challenge_packs')->restrictOnDelete();
            $table->unsignedInteger('attempts_used')->default(0);
            $table->foreignId('counted_attempt_id')->nullable()->constrained('club_competition_attempts')->nullOnDelete();
            $table->integer('counted_score')->default(0);
            $table->integer('best_score')->nullable();
            $table->integer('first_score')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamp('score_reached_at')->nullable();
            $table->timestamps();

            $table->unique(['club_competition_id', 'user_id', 'challenge_pack_id'], 'ccpr_competition_user_pack_unique');
            $table->index('user_id');
            $table->index('challenge_pack_id');
            $table->index('counted_attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_competition_pack_results');
    }
};
