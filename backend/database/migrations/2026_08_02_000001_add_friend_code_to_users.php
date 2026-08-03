<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Unambiguous alphabet — no O/0, I/1, so codes survive being read aloud. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'friend_code')) {
                $table->string('friend_code', 12)->nullable()->unique()->after('username');
            }
        });

        // Backfill existing accounts. chunkById keys off the primary key, so
        // rows updated inside the loop cannot be skipped. Safe to re-run.
        DB::table('users')->whereNull('friend_code')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->id)->update([
                        'friend_code' => $this->uniqueCode(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'friend_code')) {
                $table->dropColumn('friend_code');
            }
        });
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (DB::table('users')->where('friend_code', $code)->exists());

        return $code;
    }
};
