<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Introduce a clear 3-way availability status alongside the existing
     * is_active flag:
     *   active      = visible + selectable/playable
     *   coming_soon = visible in the app, disabled ("Coming soon")
     *   hidden      = not shown to normal users
     * is_active is kept and mirrored (is_active == status 'active') so existing
     * queries keep working.
     */
    public function up(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            if (!Schema::hasColumn('sports', 'status')) {
                $table->string('status', 20)->default('coming_soon')->after('is_active');
            }
        });

        // Backfill from current is_active: active stays active, everything else
        // becomes "coming soon" (visible, disabled). Football is always active.
        DB::table('sports')->where('is_active', true)->update(['status' => 'active']);
        DB::table('sports')->where('is_active', false)->update(['status' => 'coming_soon']);
        DB::table('sports')->where('slug', 'football')->update(['status' => 'active', 'is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            if (Schema::hasColumn('sports', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
