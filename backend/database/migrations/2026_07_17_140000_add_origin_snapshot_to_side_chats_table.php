<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A snapshot of the message a side chat was started from, frozen at creation.
 *
 * `message_id` is a live link that goes null when the origin message is deleted — good for
 * jumping to it while it exists, useless once it's gone. But "Started from: Alice — Should we
 * redesign the dashboard?" is context the room shouldn't lose just because someone tidied up
 * the parent message. So we keep our own copy of who said it and what they said, and the
 * panel falls back to this when the live message is no longer there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_chats', function (Blueprint $table) {
            $table->string('origin_author')->nullable()->after('message_id');
            $table->text('origin_excerpt')->nullable()->after('origin_author');
        });
    }

    public function down(): void
    {
        Schema::table('side_chats', function (Blueprint $table) {
            $table->dropColumn(['origin_author', 'origin_excerpt']);
        });
    }
};
