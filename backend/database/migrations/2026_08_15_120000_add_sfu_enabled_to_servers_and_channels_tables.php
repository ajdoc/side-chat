<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // The owner's policy for the whole place. Defaults to on because a deployment with
            // no SFU configured never reaches this flag anyway — the resolver stops earlier —
            // so defaulting to off would only mean every operator who sets one up has to go
            // and find a switch to make it work.
            $table->boolean('sfu_enabled')->default(true);
        });

        Schema::table('channels', function (Blueprint $table) {
            // Per-room override. Null means "whatever the server says", which is what almost
            // every channel will be; a value is a deliberate exception for one room.
            $table->boolean('sfu_enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('sfu_enabled');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('sfu_enabled');
        });
    }
};
