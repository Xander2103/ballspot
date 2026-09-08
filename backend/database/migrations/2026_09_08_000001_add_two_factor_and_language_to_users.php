<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.9.7 auth UX sprint.
 *
 *  - two_factor_enabled: opt-in email login code (2FA). Default false so every
 *    existing user keeps logging in with email + password only.
 *  - preferred_language: nl|en|fr|de|es. Existing rows get 'en' (the current
 *    app/email language) — the app can change it from Profile.
 *
 * Additive columns with defaults: safe on a live table, no data rewrite, no
 * migrate:fresh. Guarded with hasColumn so a partial earlier run is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'two_factor_enabled')) {
                $table->boolean('two_factor_enabled')->default(false)->after('email_verified_at');
            }
            if (!Schema::hasColumn('users', 'preferred_language')) {
                $table->string('preferred_language', 5)->default('en')->after('selected_theme');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'two_factor_enabled')) {
                $table->dropColumn('two_factor_enabled');
            }
            if (Schema::hasColumn('users', 'preferred_language')) {
                $table->dropColumn('preferred_language');
            }
        });
    }
};
