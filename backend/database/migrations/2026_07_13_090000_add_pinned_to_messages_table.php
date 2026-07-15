<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            // Who pinned it. Nulled rather than cascaded if that account goes: losing the
            // person must not silently unpin what the channel agreed was worth keeping.
            $table->foreignIdFor(User::class, 'pinned_by')->nullable()->constrained('users')->nullOnDelete();

            // "the pinned messages in this channel, newest pin first" — the Pinned tab.
            $table->index(['channel_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'pinned_at']);
            $table->dropConstrainedForeignId('pinned_by');
            $table->dropColumn('pinned_at');
        });
    }
};
