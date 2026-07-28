<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who can see a channel.
 *
 * Until now a server channel was visible to every member of the server, full stop — the
 * container answered the membership question and the channel had no say. That's the right
 * default and it stays the default: `is_private` is false, and a false here means the
 * channel behaves exactly as it always has, with no rows in `channel_user` at all.
 *
 * Flip it and the channel gains a roster: `channel_user` is the allow-list, and the
 * server's staff (owner + admins) are on it implicitly, because a channel the person
 * running the place can't get into is a channel nobody can unlock. Everything downstream
 * — the sidebar, the message stream's broadcast auth, every request scoped to the channel
 * — asks `Channel::hasMember()`, so this single flag reaches all of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('type');
        });

        Schema::create('channel_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['channel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_user');

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
