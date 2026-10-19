<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Club ownership of content (plan §4.5). Additive: every existing row gets
 * club_id = NULL, which means "BallPicker content" — public behaviour is
 * unchanged.
 *
 *  - challenge_packs.club_id / challenges.club_id: restrictOnDelete, so a club
 *    with content can never be hard-deleted (archive instead).
 *  - badges.club_id: per-pack trophies of club packs. Restrict as well — a
 *    NULL fallback would turn a private club badge into a global one.
 *  - pack_attempts(challenge_pack_id, status): competition linking warns
 *    about in-progress normal attempts per pack; no other change.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['challenge_packs', 'challenges'] as $name) {
            if (!Schema::hasColumn($name, 'club_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('club_id')->nullable()->constrained('clubs')->restrictOnDelete();
                    $table->index(['club_id', 'status']);
                });
            }
        }

        if (!Schema::hasColumn('badges', 'club_id')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->foreignId('club_id')->nullable()->constrained('clubs')->restrictOnDelete();
                $table->index('club_id');
            });
        }

        if (!Schema::hasIndex('pack_attempts', ['challenge_pack_id', 'status'])) {
            Schema::table('pack_attempts', function (Blueprint $table) {
                $table->index(['challenge_pack_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // MySQL/MariaDB silently drop the implicit index behind a foreign key
        // once another index can serve it, and refuse to drop an index a
        // foreign key still needs (error 1553). So: keep a plain index for the
        // pack_attempts FK before removing ours, and drop each club_id foreign
        // key BEFORE the indexes that back it.
        if (Schema::hasIndex('pack_attempts', ['challenge_pack_id', 'status'])) {
            $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
            Schema::table('pack_attempts', function (Blueprint $table) use ($mysql) {
                if ($mysql && !Schema::hasIndex('pack_attempts', ['challenge_pack_id'])) {
                    $table->index('challenge_pack_id');
                }
                $table->dropIndex(['challenge_pack_id', 'status']);
            });
        }

        if (Schema::hasColumn('badges', 'club_id')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->dropForeign(['club_id']);
            });
            Schema::table('badges', function (Blueprint $table) {
                $table->dropIndex(['club_id']);
                $table->dropColumn('club_id');
            });
        }

        foreach (['challenges', 'challenge_packs'] as $name) {
            if (Schema::hasColumn($name, 'club_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropForeign(['club_id']);
                });
                Schema::table($name, function (Blueprint $table) {
                    $table->dropIndex(['club_id', 'status']);
                    $table->dropColumn('club_id');
                });
            }
        }
    }
};
