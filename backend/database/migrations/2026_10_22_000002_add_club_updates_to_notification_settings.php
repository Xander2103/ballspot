<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BallPicker Clubs (Wave F) — notification category `club_updates`
 * (competition published / started / finalized pushes of the clubs a user is
 * an active member of). Default ON, like the other categories; existing rows
 * get true. Exposed as `club_updates_enabled` on /api/me/notification-settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('club_updates_enabled')->default(true)->after('admin_notifications_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', fn (Blueprint $table) => $table->dropColumn('club_updates_enabled'));
    }
};
