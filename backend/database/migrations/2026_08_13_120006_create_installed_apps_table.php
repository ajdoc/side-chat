<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app catalogue, as far as it can grow at runtime.
 *
 * Built-in apps are rows in {@see \App\Support\Apps\AppRegistry} and always will be — they ship
 * with the client that renders them. This table is the other half: apps that were *installed*,
 * which a PHP constant cannot represent, and without which "third-party apps" can never be more
 * than a redeploy.
 *
 * Deliberately arriving before any of the sandbox work. It is the prerequisite for all of it —
 * a dynamic id has to exist before there's anything to sandbox — and it earns its place on its
 * own: it's also how a first-party app ships without a client release once the external
 * renderer exists.
 *
 * ## What a row is
 *
 * `slug` is what lands in `channel_apps.app_id`, so it shares that column's namespace with the
 * built-ins and must not collide with one — enforced on insert, not by the schema, since the
 * built-in list lives in PHP.
 *
 * `entry_url` is where the app's client bundle is served from. **It must be an origin we do not
 * serve the app itself from**: the sandboxed iframe is only a boundary if the framed document
 * can't reach our cookies, and `allow-same-origin` on our own origin would hand it everything.
 * Nothing renders this yet — the column exists so an install is describable before the renderer
 * that honours it is written.
 *
 * `enabled` is the kill switch. A misbehaving app should stop rendering everywhere at once
 * without deleting the channels that point at it, because deleting those would take their
 * timelines with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installed_apps', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            // A lucide icon name the client resolves, falling back to a generic app glyph when
            // it doesn't know it. Not an uploaded image: an arbitrary URL in every sidebar row
            // is a tracking pixel with extra steps.
            $table->string('icon', 64)->nullable();
            $table->string('entry_url')->nullable();
            $table->string('version', 32)->default('0.0.0');
            $table->boolean('enabled')->default(true);
            // Which bot identity the app acts as, once it does anything server-side. Null until
            // then — an app that only draws needs no identity at all.
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installed_apps');
    }
};
