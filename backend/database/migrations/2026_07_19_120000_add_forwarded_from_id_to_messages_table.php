<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // The original this message was forwarded from (null = not a forward). Mirrors
            // reply_to_id: a compact back-reference, nulled if the original is deleted.
            $table->foreignId('forwarded_from_id')->nullable()->after('reply_to_id')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['forwarded_from_id']);
            $table->dropColumn('forwarded_from_id');
        });
    }
};
