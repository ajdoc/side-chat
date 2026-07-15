<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // How far this user has read. Nulled (rather than cascade-deleted) if that
            // message is removed, so losing a message doesn't reset someone to "unread".
            $table->foreignIdFor(Message::class, 'last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
            // "who has read up to message X" — the seen-by row.
            $table->index(['channel_id', 'last_read_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_reads');
    }
};
