<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->string('provider')->nullable()->after('avatar');
            $table->string('provider_id')->nullable()->after('provider');

            // Social accounts have no local password.
            $table->string('password')->nullable()->change();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
            $table->dropColumn(['avatar', 'provider', 'provider_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
