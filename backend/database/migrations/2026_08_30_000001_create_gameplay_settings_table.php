<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-editable gameplay knobs (key/value). Only keys the app knows about
     * are ever read (see App\Models\GameplaySetting); missing rows fall back
     * to the config default, so this table needs no seeding.
     */
    public function up(): void
    {
        Schema::create('gameplay_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameplay_settings');
    }
};
