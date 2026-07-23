<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user notification preferences, synced across devices. One row per
     * user, created lazily on first read. Opt-in is enforced at the OS/device
     * layer (permission prompt) — these booleans only gate WHICH reminders the
     * app schedules once the user has granted permission. No personal data
     * beyond an optional IANA timezone string.
     */
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('daily_reminder_enabled')->default(true);
            $table->boolean('tournament_reminder_enabled')->default(true);
            $table->boolean('admin_notifications_enabled')->default(true);
            $table->string('reminder_time', 5)->default('19:00'); // HH:mm
            $table->string('timezone')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
