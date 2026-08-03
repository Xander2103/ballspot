<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('friend_requests')) {
            return;
        }

        Schema::create('friend_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            // One row per direction per pair; re-requesting after a rejection
            // updates the existing row rather than inserting a duplicate.
            $table->unique(['requester_id', 'recipient_id']);
            $table->index(['recipient_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_requests');
    }
};
