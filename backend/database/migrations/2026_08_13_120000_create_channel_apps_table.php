<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which app a channel *is*.
 *
 * An app channel is a channel whose body is an application rather than a timeline — the same
 * shape a Side Space already has, where the map sits on top of a real timeline and everything
 * below it is unaware. So this table is the direct sibling of `side_space_maps`: one row, one
 * channel, hanging off the *discussion* rather than the container.
 *
 * That last point is the whole of the grouping story. A discussion is a channel with a parent,
 * so an app container with three discussions is three apps under one name in the sidebar —
 * "Design" holding a tracker, a board and a doc shelf — and none of it needed a second
 * mechanism.
 *
 * `config` is free-form on purpose, exactly like `widgets.state`: its shape belongs to whatever
 * renders the app, which keeps a new app to a component and a catalogue entry with no schema
 * change. Read it off `input()` rather than `validated()` — see the note in Channel.
 *
 * See {@see \App\Models\ChannelApp} and {@see \App\Support\Apps\AppCatalogue}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_apps', function (Blueprint $table) {
            $table->id();
            // Unique: one app per channel. A channel that held a *set* of apps would be the
            // Side Desk, which already exists and which this deliberately doesn't duplicate.
            $table->foreignIdFor(Channel::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('app_id', 32);
            $table->json('config')->nullable();
            $table->foreignIdFor(User::class, 'installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_apps');
    }
};
