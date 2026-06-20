<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('hidden_image_path');
            $table->string('original_image_path')->nullable();
            $table->decimal('ball_x_ratio', 8, 6);
            $table->decimal('ball_y_ratio', 8, 6);
            $table->string('difficulty')->default('medium'); // easy|medium|hard
            $table->string('status')->default('active'); // draft|active|archived
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
