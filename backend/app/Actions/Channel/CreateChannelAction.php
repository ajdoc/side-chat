<?php

namespace App\Actions\Channel;

use App\DTOs\Channel\CreateChannelData;
use App\Events\ChannelCreated;
use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use App\Support\SideSpace\MapSeeder;
use Illuminate\Support\Facades\DB;

final class CreateChannelAction
{
    /**
     * @param  User|null  $installedBy  who is creating it — recorded only for an app channel,
     *                                  as the "installed by" on its app row. Optional so the
     *                                  factories and tests that predate app channels still call
     *                                  this with two arguments.
     */
    public function handle(Server $server, CreateChannelData $data, ?User $installedBy = null): Channel
    {
        $channel = DB::transaction(function () use ($server, $data, $installedBy) {
            $channel = $server->channels()->create([
                'name' => $data->name,
                'type' => $data->type,
                'position' => ((int) $server->channels()->whereNull('parent_id')->max('position')) + 1,
            ]);

            // Every container is born with a discussion. A container holds no timeline of its
            // own, so a channel without one is a channel you can create but not open — and
            // making the first discussion the creator's job would mean every new channel spends
            // its first moments broken.
            $general = $channel->discussions()->create([
                'server_id' => $server->getKey(),
                'name' => 'General',
                'type' => $channel->type,
                'position' => 0,
            ]);

            // A Side Space is a room, and a room without a map is a channel you can open but not
            // stand in. So it's seeded here, inside the transaction, rather than lazily on first
            // visit — there is no moment at which the channel exists and the room doesn't. The map
            // hangs off the discussion, not the container: each discussion is its own room.
            if ($channel->isSpace()) {
                MapSeeder::ensure($general, (string) $data->preset);
            }

            // Same argument as the map, one layer up: an app channel whose app row didn't exist
            // yet is a channel that opens onto nothing. It goes on the discussion, because the
            // discussion is what people actually open — see ChannelApp.
            if ($channel->isApp()) {
                $app = $general->app()->create([
                    'app_id' => (string) $data->app_id,
                    'installed_by' => $installedBy?->getKey(),
                ]);

                // Set on both, so the response to "create this channel" already says which app
                // it is and the client can route straight into it. The container carries a copy
                // because the sidebar draws the trunk from it.
                $general->setRelation('app', $app);
                $channel->setRelation('app', $app);
            }

            return $channel->setRelation('discussions', collect([$general]));
        });

        // After the transaction, not inside it: a listener that fires on a rolled-back write is
        // a sidebar row for a channel that never existed.
        broadcast(new ChannelCreated($channel));

        return $channel;
    }

}
