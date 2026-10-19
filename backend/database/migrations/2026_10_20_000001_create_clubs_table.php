<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BallPicker Clubs (plan §4.2): private groups created by platform admins
 * (D1). `id` is internal only — the mobile API addresses a club by its ULID
 * `public_id`, admin URLs may use the readable `slug`.
 *
 * Counters are denormalised for admin sorting and My Clubs cards; they are
 * maintained by App\Services\Clubs\ClubMembershipService::refreshCounters()
 * and reconciled nightly. `needs_owner` flags a non-archived club without an
 * active owner (D6) so the admin index can surface it. `version` is the
 * optimistic lock for admin edits (stale_version).
 *
 * Additive; nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clubs')) {
            return;
        }

        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->foreignId('sport_id')->nullable()->constrained('sports')->nullOnDelete();
            $table->string('club_type', 20)->default('sports_club');
            $table->string('city', 80)->nullable();
            $table->string('region', 80)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('external_ref', 60)->nullable();
            // Admin-only personal data (privacy inventory) — never in the API.
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('timezone', 64);
            $table->string('status', 20)->default('active');
            $table->boolean('join_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->unsignedInteger('max_members')->nullable();
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedInteger('active_packs_count')->default(0);
            $table->unsignedInteger('live_competitions_count')->default(0);
            $table->boolean('needs_owner')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 200)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_reason', 200)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['status', 'name']);
            $table->index(['sport_id', 'status']);
            $table->index(['country_code', 'city']);
            $table->index(['status', 'last_activity_at']);
            $table->index(['status', 'members_count']);
            $table->index(['needs_owner', 'status']);
            $table->index('external_ref');
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
