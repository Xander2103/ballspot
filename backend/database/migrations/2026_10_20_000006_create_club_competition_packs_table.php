<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packs linked to a competition (plan §4.6). `challenge_ids` is the ordered
 * snapshot taken at rule lock, so later pack edits only affect normal play.
 * Invariant (service-enforced): pack.club_id = competition.club_id.
 *
 * The plan sketches a composite primary key; a surrogate `id` plus the
 * unique (competition, pack) pair is equivalent and keeps Eloquent simple.
 * The pack FK restricts deletes so a played competition keeps its packs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_competition_packs')) {
            return;
        }

        Schema::create('club_competition_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_competition_id')->constrained('club_competitions')->cascadeOnDelete();
            $table->foreignId('challenge_pack_id')->constrained('challenge_packs')->restrictOnDelete();
            $table->integer('sort_order')->default(0);
            $table->json('challenge_ids')->nullable();
            $table->timestamp('snapshotted_at')->nullable();
            $table->timestamps();

            $table->unique(['club_competition_id', 'challenge_pack_id'], 'ccp_competition_pack_unique');
            $table->index('challenge_pack_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_competition_packs');
    }
};
