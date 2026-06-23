<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->foreignId('challenge_category_id')
                ->nullable()
                ->after('sport_id')
                ->constrained('challenge_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\ChallengeCategory::class);
            $table->dropColumn('challenge_category_id');
        });
    }
};
