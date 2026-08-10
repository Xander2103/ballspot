<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            // Server bookkeeping for the daily-reminder push: the challenge_date
            // the user was last reminded about. Never exposed via the API.
            $table->date('last_daily_reminder_date')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', fn (Blueprint $table) => $table->dropColumn('last_daily_reminder_date'));
    }
};
