<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guesses of a competition attempt — source of truth for standings (plan
 * §4.6). `submitted_at` is the SERVER receive time; standings count only
 * guesses inside [starts_at, ends_at). The two unique indexes make a retried
 * or duplicate submission idempotent (a challenge is answered once per
 * attempt, and every position once).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_competition_attempt_guesses')) {
            return;
        }

        Schema::create('club_competition_attempt_guesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('club_competition_attempts')->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained('challenges')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->decimal('guess_x', 8, 6);
            $table->decimal('guess_y', 8, 6);
            $table->decimal('distance', 10, 6);
            $table->integer('score');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['attempt_id', 'challenge_id'], 'ccag_attempt_challenge_unique');
            $table->unique(['attempt_id', 'position'], 'ccag_attempt_position_unique');
            $table->index('challenge_id');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_competition_attempt_guesses');
    }
};
