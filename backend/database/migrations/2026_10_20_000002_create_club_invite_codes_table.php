<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revocable, expiring, use-limited invite codes (plan §4.4). `code` is stored
 * normalised (upper case, no dashes/spaces), 8 characters from an alphabet
 * without look-alikes; it is shown as ABCD-EFGH. Globally unique so a join
 * never needs the club id. Created before club_members, which records the
 * code a member joined with.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_invite_codes')) {
            return;
        }

        Schema::create('club_invite_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('label', 60)->nullable();
            $table->string('grants_role', 20)->default('member');
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['club_id', 'revoked_at']);
            $table->index('created_by_user_id');
            $table->index('revoked_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_invite_codes');
    }
};
