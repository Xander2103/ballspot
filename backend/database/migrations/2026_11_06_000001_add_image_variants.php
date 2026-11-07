<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata of the downscaled DISPLAY copies built by ImageVariantService
     * (path, width, height, bytes and the source path they were made from).
     * Additive and nullable: null = serve the original upload, exactly as
     * before. The original image columns are untouched.
     */
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->json('image_variants')->nullable()->after('original_image_path');
        });
        Schema::table('challenge_packs', function (Blueprint $table) {
            $table->json('cover_image_variant')->nullable()->after('cover_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn('image_variants');
        });
        Schema::table('challenge_packs', function (Blueprint $table) {
            $table->dropColumn('cover_image_variant');
        });
    }
};
