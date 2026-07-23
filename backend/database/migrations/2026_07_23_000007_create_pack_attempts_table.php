<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's play-through of a challenge pack. The ordered set of ready
     * challenge ids is snapshotted into metadata at start, so later admin edits
     * to the pack don't shift an in-progress attempt. Virtual progress only —
     * no prizes/money. Completed attempts stay historical for the Trophy Room.
     */
    public function up(): void
    {
        Schema::create('pack_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_pack_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');  // active | completed | abandoned
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_score')->default(0);
            $table->unsignedInteger('current_index')->default(0);
            $table->json('metadata')->nullable();          // { challenge_ids: [...] }
            $table->timestamps();

            $table->index(['user_id', 'challenge_pack_id', 'status']);
        });

        Schema::create('pack_attempt_guesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->integer('score');
            $table->decimal('guessed_x', 8, 6)->nullable();
            $table->decimal('guessed_y', 8, 6)->nullable();
            $table->decimal('distance', 10, 6)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            // A challenge is answered once per attempt.
            $table->unique(['pack_attempt_id', 'challenge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_attempt_guesses');
        Schema::dropIfExists('pack_attempts');
    }
};
