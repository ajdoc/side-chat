<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A message may be attachments-only (e.g. just an image).
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body')->nullable(false)->change();
        });
    }
};
