<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final placements of a finalized club competition — club-only trophies, no
 * global XP (D3). Written idempotently (unique competition + user), like
 * competition_finishes. The user FK nulls on delete so a placement survives
 * anonymisation; full ties share the placement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_competition_finishes')) {
            return;
        }

        Schema::create('club_competition_finishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_competition_id')->constrained('club_competitions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('placement');
            $table->integer('total_score');
            $table->unsignedInteger('total_players');
            $table->json('metadata')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();

            $table->unique(['club_competition_id', 'user_id'], 'ccf_competition_user_unique');
            $table->index(['user_id', 'placement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_competition_finishes');
    }
};
