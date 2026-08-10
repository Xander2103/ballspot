<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'anonymized_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('anonymized_at')->nullable()->after('email_verified_at');
            });
        }

        // Backfill accounts deleted before this column existed. updated_at is
        // the closest available proxy for when the anonymization happened.
        DB::table('users')
            ->where('email', 'like', '%@ballspot.deleted')
            ->whereNull('anonymized_at')
            ->update(['anonymized_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
    }
};
