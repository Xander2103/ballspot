<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_challenge_guesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_challenge_id')->constrained('daily_challenges')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('guess_x_ratio', 8, 6);
            $table->decimal('guess_y_ratio', 8, 6);
            $table->decimal('distance', 10, 6);
            $table->integer('score');
            $table->dateTime('submitted_at');
            $table->timestamps();
            $table->unique(['daily_challenge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_challenge_guesses');
    }
};
