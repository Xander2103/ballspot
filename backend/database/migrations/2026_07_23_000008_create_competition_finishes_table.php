<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Final standings for a CLOSED competition period (monthly/weekly daily-
     * challenge leaderboard). Written once, idempotently, by CompetitionClose
     * service/command — never for the current in-progress period. Virtual
     * recognition only: no prizes, money, or payment fields. Historical: the
     * user FK nulls on account deletion so the record (and its placement)
     * survives anonymisation.
     */
    public function up(): void
    {
        Schema::create('competition_finishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_type');           // monthly | weekly
            $table->string('period_label');          // e.g. "July 2026"
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('placement');    // 1 = winner
            $table->integer('total_score');
            $table->unsignedInteger('total_players');
            $table->integer('xp_awarded')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();

            // One finish per user per closed period — makes closing idempotent.
            $table->unique(['period_type', 'period_start', 'period_end', 'user_id'], 'competition_finish_unique');
            $table->index(['user_id', 'placement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_finishes');
    }
};
