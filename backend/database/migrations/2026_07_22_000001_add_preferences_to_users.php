<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Preferred sport/category. Nullable so existing users (and fresh
            // registrations) default to the football-first flow until they pick.
            if (!Schema::hasColumn('users', 'preferred_sport_id')) {
                $table->foreignId('preferred_sport_id')
                    ->nullable()
                    ->after('username')
                    ->constrained('sports')
                    ->nullOnDelete();
            }

            // App theme token. 'classic' keeps the current BallSpot look.
            if (!Schema::hasColumn('users', 'selected_theme')) {
                $table->string('selected_theme', 40)->default('classic')->after('preferred_sport_id');
            }

            // Relative path on the public disk (e.g. avatars/xxxx.jpg). Null = no avatar.
            if (!Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('selected_theme');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferred_sport_id')) {
                $table->dropConstrainedForeignId('preferred_sport_id');
            }
            if (Schema::hasColumn('users', 'selected_theme')) {
                $table->dropColumn('selected_theme');
            }
            if (Schema::hasColumn('users', 'avatar_path')) {
                $table->dropColumn('avatar_path');
            }
        });
    }
};
