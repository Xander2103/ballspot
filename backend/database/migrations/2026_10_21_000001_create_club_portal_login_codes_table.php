<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BallPicker Clubs — club portal (/club) emailed 6-digit sign-in codes.
 *
 * Deliberately separate from login_verification_codes (the app's opt-in
 * 2FA): a portal code must never be redeemable on POST /api/login/verify
 * (which mints an API token), and issuing one must not wipe an app login
 * that is in flight for the same user. At most one row per user is kept
 * (issuing a code deletes the user's older rows; a used code is deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_portal_login_codes', function (Blueprint $table) {
            $table->id();
            // Unguessable handle kept in the pending portal session only.
            $table->uuid('challenge_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Only the HASH of the code is stored; the plain code lives in the email.
            $table->string('code_hash');
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('sends')->default(1);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_portal_login_codes');
    }
};
