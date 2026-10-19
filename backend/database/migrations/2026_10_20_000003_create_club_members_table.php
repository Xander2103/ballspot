<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Club memberships (plan §4.3). One row per (club, user), kept for history:
 * status active | left | removed | banned. Left/removed members re-join by
 * reactivating the row; banned members cannot re-join (D23). The unique
 * (club_id, user_id) index is the real guard against double joins — row
 * locks are a no-op on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_members')) {
            return;
        }

        Schema::create('club_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->foreignId('invite_code_id')->nullable()->constrained('club_invite_codes')->nullOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Admin-only; never shown to the member.
            $table->string('removal_reason', 200)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'user_id']);
            $table->index(['user_id', 'status', 'last_seen_at']);
            $table->index(['club_id', 'status', 'role']);
            $table->index('invite_code_id');
            $table->index('added_by_user_id');
            $table->index('removed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_members');
    }
};
