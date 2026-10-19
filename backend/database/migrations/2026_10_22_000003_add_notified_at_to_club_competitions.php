<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BallPicker Clubs (Wave F) — at-most-once bookkeeping for the member
 * pushes of App\Services\Clubs\ClubCompetitionNotifier: each timestamp is
 * claimed atomically (UPDATE … WHERE notified_x_at IS NULL) BEFORE sending.
 *
 * Backfill: competitions that already went through an event before this
 * migration are marked as notified, so the first scheduler tick after the
 * deploy never pushes about old news. Additive; touches only these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_competitions', function (Blueprint $table) {
            $table->timestamp('notified_published_at')->nullable()->after('voided_at');
            $table->timestamp('notified_started_at')->nullable()->after('notified_published_at');
            $table->timestamp('notified_finalized_at')->nullable()->after('notified_started_at');
        });

        $now = now();
        DB::table('club_competitions')->where('state', '!=', 'draft')->update(['notified_published_at' => $now]);
        DB::table('club_competitions')->where('state', '!=', 'draft')->where('starts_at', '<=', $now)->update(['notified_started_at' => $now]);
        DB::table('club_competitions')->where('state', 'finalized')->update(['notified_finalized_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('club_competitions', function (Blueprint $table) {
            $table->dropColumn(['notified_published_at', 'notified_started_at', 'notified_finalized_at']);
        });
    }
};
