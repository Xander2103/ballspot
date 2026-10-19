<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private club competitions (plan §4.6). Only the `state` is stored
 * (draft | published | cancelled | finalized | voided); the user-facing phase
 * (upcoming / active / ended …) is derived from state + window in
 * App\Models\ClubCompetition::phase() so it can never drift.
 *
 * Window is [starts_at, ends_at) in UTC, shown in `timezone`. Rules
 * (replay_mode, max_attempts, score_rule, normal_play_policy, linked packs,
 * starts_at) lock at starts_at (`rules_locked_at`). `tie_break` is reserved:
 * v1 is fixed to earliest_reached (D17).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('club_competitions')) {
            return;
        }

        Schema::create('club_competitions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('state', 20)->default('draft');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->string('replay_mode', 30)->default('single_attempt');
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->string('score_rule', 10)->default('first');
            $table->string('tie_break', 30)->default('earliest_reached');
            $table->string('normal_play_policy', 10)->default('blocked');
            $table->timestamp('rules_locked_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->unsignedInteger('participants_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['club_id', 'state', 'starts_at']);
            // Scheduler tick: published competitions whose window ended.
            $table->index(['state', 'ends_at']);
            $table->index(['state', 'starts_at']);
            $table->index('created_by_user_id');
            $table->index('updated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_competitions');
    }
};
