<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A label a server hands out — "Griefer", "Veteran", "Playtester".
 *
 * Deliberately *not* a permission. `server_user.role` stays the only thing that decides who
 * may do what (see Server::ROLE_*, and the argument there for two rungs rather than a
 * matrix). A badge is the other half of that argument: most of what people actually want
 * from "roles" in a chat app is to be *seen* as something and to be addressable as a group,
 * and neither of those needs to touch authorisation. Keeping them apart means a reaction
 * anybody can click can grant a badge without it ever being a way to escalate.
 *
 * What a badge is for, concretely: it renders next to a name, an automation can grant or
 * revoke it, and a custom command or a giveaway can require it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            // Both optional: a badge with neither still works, it just renders as plain text.
            $table->string('emoji', 16)->nullable();
            $table->string('color', 7)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();

            // Two badges called "Veteran" in one server would be indistinguishable everywhere
            // they're shown, and an automation naming one of them would be picking at random.
            $table->unique(['server_id', 'name']);
        });

        Schema::create('badge_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // Who or what granted it — an automation, or a person doing it by hand. Null once
            // the granter's account is gone; the badge outlives them.
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Granting twice is a no-op, not a second badge.
            $table->unique(['badge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_user');
        Schema::dropIfExists('badges');
    }
};
