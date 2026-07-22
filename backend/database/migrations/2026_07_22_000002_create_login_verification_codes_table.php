<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_verification_codes', function (Blueprint $table) {
            $table->id();
            // Public, unguessable handle the client references (never the DB id).
            $table->uuid('verification_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            // Only the HASH of the 6-digit code is ever stored.
            $table->string('code_hash');
            // When the current code was issued (drives the resend cooldown).
            $table->timestamp('code_sent_at');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_verification_codes');
    }
};
