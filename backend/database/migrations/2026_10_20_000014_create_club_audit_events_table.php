<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every club mutation (plan §4.7, §6.6), written
 * by App\Services\Clubs\ClubAuditLogger inside the same transaction as the
 * change. `action` and the metadata keys are allow-listed in
 * App\Models\ClubAuditEvent; metadata never carries emails or full invite
 * codes. club_id nulls on delete so the `club.deleted` event of an empty
 * club survives its hard delete. No updated_at: rows are never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_audit_events')) {
            return;
        }

        Schema::create('club_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_kind', 20); // platform_admin | club_admin | member | system
            $table->string('action', 60);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['club_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_audit_events');
    }
};
