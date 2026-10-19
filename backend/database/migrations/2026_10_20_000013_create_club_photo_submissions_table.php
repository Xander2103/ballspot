<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos supplied for a club (plan §4.7), reviewed and manually prepared by
 * BallPicker into club challenges. Files live on the PRIVATE club_private
 * disk (never /storage), GD re-encoded without EXIF/GPS. `sha256` is the
 * per-club duplicate check. The club FK restricts deletes: a club with
 * submissions has history and can only be archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_photo_submissions')) {
            return;
        }

        Schema::create('club_photo_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('admin_upload'); // admin_upload | club_upload
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('bytes')->default(0);
            $table->char('sha256', 64);
            $table->string('status', 20)->default('pending');
            $table->string('club_note', 500)->nullable();
            $table->text('admin_note')->nullable();
            // Shown to the club's admins.
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('challenge_id')->nullable()->constrained('challenges')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('rights_confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['club_id', 'sha256']);
            $table->index('uploaded_by_user_id');
            $table->index('challenge_id');
            $table->index('assigned_to_user_id');
            $table->index('reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_photo_submissions');
    }
};
