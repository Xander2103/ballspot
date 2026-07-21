<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('icon')->default('🏅');       // emoji — no copyrighted assets
            $table->string('category')->default('general'); // general|daily|tournament|streak|skill|sport
            $table->string('rarity')->default('common');    // common|rare|epic|legendary
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};
