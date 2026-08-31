<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional per-pack completion trophy. Null = no trophy (existing packs
     * keep working unchanged). The badge row itself lives in `badges` with a
     * stable per-pack code so repeated admin edits never duplicate it.
     */
    public function up(): void
    {
        Schema::table('challenge_packs', function (Blueprint $table) {
            $table->foreignId('completion_badge_id')
                ->nullable()
                ->after('is_featured')
                ->constrained('badges')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('challenge_packs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completion_badge_id');
        });
    }
};
