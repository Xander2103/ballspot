<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One global-reward run per player per CLUB pack (D3, plan §5.8). The first
 * run that starts on a club pack — normal attempt or competition attempt —
 * inserts the claim; unique (user_id, challenge_pack_id) settles races.
 * Public packs never use this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_pack_reward_claims')) {
            return;
        }

        Schema::create('club_pack_reward_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('challenge_pack_id')->constrained('challenge_packs')->cascadeOnDelete();
            $table->string('source_type', 30); // pack_attempt | competition_attempt
            $table->unsignedBigInteger('source_id');
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['user_id', 'challenge_pack_id']);
            $table->index('challenge_pack_id');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_pack_reward_claims');
    }
};
