<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('league_members', function (Blueprint $table) {
            // Per-user "remove from my list" for finished tournaments. The row
            // itself stays, so history, leaderboards and XP are untouched.
            if (!Schema::hasColumn('league_members', 'hidden_at')) {
                $table->timestamp('hidden_at')->nullable();
                $table->index(['user_id', 'hidden_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('league_members', function (Blueprint $table) {
            if (Schema::hasColumn('league_members', 'hidden_at')) {
                $table->dropIndex(['user_id', 'hidden_at']);
                $table->dropColumn('hidden_at');
            }
        });
    }
};
