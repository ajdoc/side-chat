<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot accounts.
 *
 * A bot *is* a user — `users.is_bot` marks it, and everything downstream keeps working
 * without knowing: it authors messages through `messages.user_id`, sits on the server
 * roster, gets @mentioned by name, carries an avatar. A parallel author type would have
 * meant a nullable second foreign key on messages, reactions, nicknames and reads, and a
 * "who wrote this" branch in every one of them. This flag costs one column instead.
 *
 * What a bot has that a person doesn't lives here: the server that owns it, the human
 * accountable for it, and the credential it authenticates with. The token is stored
 * hashed — it's shown once, at creation, and can only ever be replaced after that.
 *
 * One bot belongs to exactly one server. A bot that roamed between servers would need a
 * per-server enable/disable and a per-server audit trail; making the server the owning
 * scope means "remove the bot" is a delete, and a leaked token reaches one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->after('email_verified_at');
        });

        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            // The account this bot posts as. Deleting the bot user retires the bot.
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            // The human who created it, and who answers for what it does. Kept even if
            // they leave the server — nulled only if the account itself is gone.
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description')->nullable();
            // sha256 of the bearer token. Looked up by exact match on every bot request,
            // hence the index; never reversed, never shown again.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_bot');
        });
    }
};
