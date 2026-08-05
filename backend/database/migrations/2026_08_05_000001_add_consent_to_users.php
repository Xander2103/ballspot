<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a user accepted the Terms/Privacy Policy and confirmed the
 * minimum age at registration.
 *
 * GDPR Art. 7(1) requires being able to DEMONSTRATE acceptance — a client-side
 * checkbox proves nothing. Nullable so existing accounts (who registered before
 * this was recorded) are untouched rather than back-dated with a false claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
            }
            if (!Schema::hasColumn('users', 'terms_version')) {
                $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['terms_accepted_at', 'terms_version'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
