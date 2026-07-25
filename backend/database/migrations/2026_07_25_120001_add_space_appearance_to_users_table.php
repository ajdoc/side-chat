<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What you look like in a Side Space, and what's trotting along behind you.
 *
 * On the user rather than on the room, because it's *you* — walk into a different space and
 * you're still the same person, the same way your display name is. One row, read wherever a
 * user is serialised, and therefore already on every roster the room draws from.
 *
 * `space_avatar` is a small closed object (body, hair, hair colour, skin, outfit) whose every
 * value is one of a named few — see {@see \App\Support\SideSpace\Avatars}. It's JSON rather
 * than five columns because it is one setting that happens to have five parts: nothing ever
 * reads the hair without the rest of the head, and nothing ever queries by it.
 *
 * Null in both columns means "hasn't chosen" — a default trainer and no pet, which is what
 * everybody currently has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('space_avatar')->nullable()->after('theme_color');
            $table->string('space_pet')->nullable()->after('space_avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['space_avatar', 'space_pet']);
        });
    }
};
