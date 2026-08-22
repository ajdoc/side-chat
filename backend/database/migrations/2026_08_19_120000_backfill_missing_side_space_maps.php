<?php

use App\Models\Channel;
use App\Support\SideSpace\MapSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Give a map to the Side Spaces that were created without one.
 *
 * A meeting made in a server built its channel with `type = 'space'` already set and then asked
 * the *conversion* action to convert it — which correctly did nothing, so no map was ever seeded
 * and the room answered "could not load this space". The code is fixed; the rooms it already made
 * are not, and nobody can repair one from the UI: the map editor needs a map to edit.
 *
 * Only the channels that genuinely lack one. A space **container** having no map is normal and
 * expected — its discussions hold them, one room each — so a container is left alone unless it
 * has no discussions at all to hold anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Channel::query()
            ->where('type', 'space')
            ->whereDoesntHave('spaceMap')
            ->with('discussions')
            ->each(function (Channel $channel) {
                // A container whose discussions are the rooms is already correct.
                if ($channel->parent_id === null && $channel->discussions->isNotEmpty()) {
                    return;
                }

                MapSeeder::ensure($channel, 'meeting-room');
            });
    }

    public function down(): void
    {
        // Nothing. The maps handed out here are indistinguishable from any other room's, and
        // people will have moved the furniture — deleting them on a rollback would throw away
        // work to undo a repair.
    }
};
