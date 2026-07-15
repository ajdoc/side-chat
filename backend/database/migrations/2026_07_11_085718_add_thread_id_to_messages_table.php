<?php

use App\Models\Thread;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Null = main channel timeline; set = message belongs to a thread.
            $table->foreignIdFor(Thread::class)->nullable()->after('channel_id')
                ->constrained()->cascadeOnDelete();
            $table->index(['thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->dropIndex(['thread_id', 'id']);
            $table->dropColumn('thread_id');
        });
    }
};
