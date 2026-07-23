<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Challenge packs — curated content collections (e.g. "Belgium Pack",
     * "Easy Starter Pack"). CONTENT ONLY: no price, no purchase, no payment in
     * this sprint. A pack groups challenges for discovery; gameplay over a pack
     * is future work. Deleting/detaching never touches challenge images.
     */
    public function up(): void
    {
        Schema::create('challenge_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->string('status')->default('draft');      // draft | active | archived
            $table->string('visibility')->default('public');  // public | hidden
            $table->string('difficulty')->nullable();         // easy | medium | hard | mixed
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index(['status', 'visibility']);
        });

        Schema::create('challenge_pack_challenge', function (Blueprint $table) {
            $table->foreignId('challenge_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->primary(['challenge_pack_id', 'challenge_id'], 'pack_challenge_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_pack_challenge');
        Schema::dropIfExists('challenge_packs');
    }
};
