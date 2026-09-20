<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed in-app notices ("Daily login starts tomorrow"), one row per
 * placement. Additive table, no data rewrite, no migrate:fresh. Messages are
 * stored per supported language; the API resolves one for the caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notices', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 40)->default('home_daily_card')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('type', 20)->default('info'); // info | warning | success
            $table->string('message_nl', 300)->nullable();
            $table->string('message_en', 300)->nullable();
            $table->string('message_fr', 300)->nullable();
            $table->string('message_de', 300)->nullable();
            $table->string('message_es', 300)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notices');
    }
};
