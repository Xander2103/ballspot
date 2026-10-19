<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competition attempts — the source of truth for competition play (plan
 * §4.6, §5.9), separate from pack_attempts so normal Pack progress, replay
 * blocking, Trophy Room and badge counts keep their meaning.
 *
 * Two DB-level guards (row locks are a no-op on SQLite, so these are the
 * real ones):
 *  - unique (competition, pack, user, attempt_number): attempt limit +
 *    idempotent start (a retried start with the same number finds the row);
 *  - unique (competition, pack, user, active_slot): active_slot is 1 while
 *    the attempt is active and NULL otherwise. NULLs are distinct in unique
 *    indexes on MySQL and SQLite, so at most ONE active attempt can exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_competition_attempts')) {
            return;
        }

        Schema::create('club_competition_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_competition_id')->constrained('club_competitions')->cascadeOnDelete();
            $table->foreignId('challenge_pack_id')->constrained('challenge_packs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->json('challenge_ids');
            $table->unsignedInteger('current_index')->default(0);
            $table->unsignedInteger('guesses_count')->default(0);
            $table->integer('total_score')->default(0);
            $table->boolean('is_rewarded_run')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_scored_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['club_competition_id', 'challenge_pack_id', 'user_id', 'attempt_number'], 'cca_attempt_number_unique');
            $table->unique(['club_competition_id', 'challenge_pack_id', 'user_id', 'active_slot'], 'cca_active_slot_unique');
            $table->index(['club_competition_id', 'user_id'], 'cca_competition_user_index');
            // Scheduler: expire active attempts of ended competitions.
            $table->index(['club_competition_id', 'status'], 'cca_competition_status_index');
            $table->index('challenge_pack_id');
            $table->index(['user_id', 'challenge_pack_id', 'status'], 'cca_user_pack_status_index');
            $table->index('voided_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_competition_attempts');
    }
};
