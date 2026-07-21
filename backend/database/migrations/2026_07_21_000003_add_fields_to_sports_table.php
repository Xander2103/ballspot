<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->string('emoji')->default('⚽')->after('slug');
            $table->string('object_name')->default('ball')->after('emoji'); // ball|puck
            $table->string('primary_color')->default('#00c853')->after('object_name');
            $table->boolean('is_active')->default(false)->after('primary_color');
            $table->integer('sort_order')->default(0)->after('is_active');
            // Foundation only — different sports may later use different scoring curves.
            $table->string('scoring_profile')->default('default')->after('sort_order');
        });

        // Football remains the default active sport.
        Schema::table('sports', function (Blueprint $table) {});
        \Illuminate\Support\Facades\DB::table('sports')
            ->where('slug', 'football')
            ->update(['is_active' => true, 'sort_order' => 1]);
    }

    public function down(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->dropColumn(['emoji', 'object_name', 'primary_color', 'is_active', 'sort_order', 'scoring_profile']);
        });
    }
};
