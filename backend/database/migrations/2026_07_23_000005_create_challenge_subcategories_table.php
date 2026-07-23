<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Curated, admin-managed subcategories used to organise and filter content
     * (Team / Country / League / Club / Difficulty / Moment type …). Distinct
     * from the existing free-text `tags` table: tags are ad-hoc labels created
     * inline on the challenge form; subcategories are a styled, activatable,
     * sport-scoped taxonomy the admin manages deliberately. Deleting a
     * subcategory only detaches challenges (pivot cascade) — never deletes them
     * or their images.
     */
    public function up(): void
    {
        Schema::create('challenge_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->nullable()->constrained()->nullOnDelete(); // null = global
            $table->string('name');
            $table->string('slug');
            $table->string('type'); // team|country|league|club|difficulty|moment_type|player_type|custom
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Slug unique within a (sport, type) scope. sport_id null groups all
            // global subcategories of a type together.
            $table->unique(['sport_id', 'type', 'slug']);
            $table->index(['type', 'is_active']);
        });

        Schema::create('challenge_subcategory', function (Blueprint $table) {
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_subcategory_id')->constrained('challenge_subcategories')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['challenge_id', 'challenge_subcategory_id'], 'challenge_subcategory_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_subcategory');
        Schema::dropIfExists('challenge_subcategories');
    }
};
