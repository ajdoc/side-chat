<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Inline reply: the message this one is replying to (null = not a reply).
            $table->foreignId('reply_to_id')->nullable()->after('thread_id')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_id']);
            $table->dropColumn('reply_to_id');
        });
    }
};
