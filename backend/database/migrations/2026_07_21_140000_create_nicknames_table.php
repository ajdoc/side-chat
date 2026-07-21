<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody is called *in one place* — a server, a DM, or a group chat.
 *
 * One table covers two features that look different but are the same row shape:
 *
 *  - `viewer_id IS NULL` — a **public** nickname. This is what you (or the server's owner)
 *    have decided you are called here, and everyone in the place sees it. Discord's
 *    server nickname.
 *  - `viewer_id` set — a **private** alias. This is what *that one viewer* calls somebody
 *    else, and nobody else ever sees it. Messenger's "set nickname" for a contact.
 *
 * The place is polymorphic because there are two of them (Server, Conversation) and
 * nothing here cares which — same as everything else downstream of MessageContainer.
 *
 * Uniqueness needs two partial indexes rather than one plain unique: Postgres treats NULLs
 * as distinct, so a single `unique(place, user_id, viewer_id)` would happily let the same
 * person collect a dozen public nicknames in one server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nicknames', function (Blueprint $table) {
            $table->id();
            // place_type + place_id: the Server or Conversation this naming applies in.
            $table->morphs('place');
            // Whose name this is.
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // Who gets to see it. Null means everyone.
            $table->foreignId('viewer_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('nickname', 50);
            $table->timestamps();

            // The read path: "every naming in this place that applies to me" — the public
            // ones plus my own private ones, in a single scan.
            $table->index(['place_type', 'place_id', 'viewer_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX nicknames_public_unique ON nicknames (place_type, place_id, user_id) WHERE viewer_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX nicknames_private_unique ON nicknames (place_type, place_id, user_id, viewer_id) WHERE viewer_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('nicknames');
    }
};
