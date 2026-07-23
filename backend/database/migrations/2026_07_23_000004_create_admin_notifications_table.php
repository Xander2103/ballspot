<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-composed announcements. Plain title/body (no HTML). Delivery is via
     * Expo push to opted-in users' registered tokens. Status reflects the real
     * outcome — draft (not sent yet), sent, or failed — we never fake success.
     * metadata records the send summary (recipients, successes, failures).
     */
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('target_type')->default('opted_in'); // all | opted_in
            $table->string('status')->default('draft');          // draft | sent | failed
            $table->timestamp('send_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
