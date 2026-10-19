<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BallPicker Clubs (Wave F, plan §11.9) — member reports of club content
 * (the club itself, a competition, another member) for platform moderation.
 *
 * `subject_ref` is the public identifier the app sent: the club public_id,
 * the competition public_id or the reported user's id. The reporter is
 * nulled on account deletion (AccountDeletionService; the FK also nulls on a
 * hard delete). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 20);   // club | competition | member
            $table->string('subject_ref', 64)->nullable();
            $table->string('reason', 20);         // inappropriate | offensive | spam | privacy | other
            $table->text('details')->nullable();  // ≤ 500 chars (validated)
            $table->string('status', 20)->default('open'); // open | reviewed | dismissed | actioned
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['club_id', 'status']);
            $table->index('reporter_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_content_reports');
    }
};
