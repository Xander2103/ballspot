<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Expo push ticket that came back "ok". An ok ticket only means
     * Expo accepted the message; whether APNs/FCM accepted it is in the push
     * receipt, which ballspot:check-push-receipts fetches later.
     *
     * Never stores the device token or the message text: the row points at the
     * push_tokens row (nulled when that token is removed) and keeps the
     * platform so the error mix per platform stays visible afterwards.
     *
     * Additive only: no existing table or row is touched.
     */
    public function up(): void
    {
        Schema::create('push_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id', 64)->unique();
            $table->foreignId('push_token_id')->nullable()->constrained('push_tokens')->nullOnDelete();
            $table->string('platform', 16)->nullable();
            $table->string('source', 32);                      // announcement | daily_reminder | test
            $table->string('status', 16)->default('pending');  // pending | ok | error | expired
            $table->string('error', 64)->nullable();           // Expo details.error, e.g. DeviceNotRegistered
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_receipts');
    }
};
