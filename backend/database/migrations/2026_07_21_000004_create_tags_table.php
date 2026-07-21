<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // team|country|league|moment_type|difficulty_label|custom (nullable)
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('challenge_tag', function (Blueprint $table) {
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['challenge_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_tag');
        Schema::dropIfExists('tags');
    }
};
