<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fairness hardening (v1.8.9): every challenge belongs to one usage pool.
 *
 *   daily      - may only be scheduled as a Daily Challenge
 *   tournament - may only be drawn into tournament rounds
 *   pack       - curated pack content only (never auto-selected anywhere)
 *   general    - eligible for BOTH daily and tournament selection
 *
 * Additive and production-safe: a new column with a default, plus an
 * UPDATE-only backfill. Nothing is deleted, no rows are rewritten beyond the
 * new column, and historical rounds/guesses are untouched.
 *
 * Backfill: challenges that were already scheduled as a daily become 'daily'
 * (they are permanently Daily-used anyway); everything else stays 'general',
 * which is exactly the behaviour they had before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('challenges', 'usage_pool')) {
            Schema::table('challenges', function (Blueprint $table) {
                $table->string('usage_pool', 20)->default('general')->after('status');
                $table->index('usage_pool');
            });
        }

        // Idempotent backfill: only touches rows still on the default.
        DB::table('challenges')
            ->where('usage_pool', 'general')
            ->whereIn('id', fn ($q) => $q->select('challenge_id')->from('daily_challenges'))
            ->update(['usage_pool' => 'daily']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('challenges', 'usage_pool')) {
            Schema::table('challenges', function (Blueprint $table) {
                $table->dropIndex(['usage_pool']);
                $table->dropColumn('usage_pool');
            });
        }
    }
};
