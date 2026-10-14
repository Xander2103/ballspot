<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal growth-event log for the admin "Growth" page: share taps and
     * review/rate attempts (see App\Models\AppEvent for the allow-list).
     *
     * Deliberately tiny and privacy-safe: no shared message text, no review
     * text, no platform names — only the event name, the acting user and a
     * small allow-listed metadata object (score, daily_challenge_id, trigger).
     * Additive migration; nothing existing is touched.
     */
    public function up(): void
    {
        Schema::create('app_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_name', 60);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event_name', 'created_at']);
            $table->index(['user_id', 'event_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_events');
    }
};
