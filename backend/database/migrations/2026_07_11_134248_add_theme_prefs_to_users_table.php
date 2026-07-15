<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme_mode')->default('system');  // light | dark | system
            $table->string('theme_color')->default('blue');   // see UpdatePreferencesData for the accepted accents
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['theme_mode', 'theme_color']);
        });
    }
};
