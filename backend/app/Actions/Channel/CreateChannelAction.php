<?php

namespace App\Actions\Channel;

use App\DTOs\Channel\CreateChannelData;
use App\Events\ChannelCreated;
use App\Models\Channel;
use App\Models\Server;
use App\Support\SideSpace\MapPresets;
use Illuminate\Support\Facades\DB;

final class CreateChannelAction
{
    public function handle(Server $server, CreateChannelData $data): Channel
    {
        $channel = DB::transaction(function () use ($server, $data) {
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
                $this->seedMap($general, (string) $data->preset);
            }

            return $channel->setRelation('discussions', collect([$general]));
        });

        // After the transaction, not inside it: a listener that fires on a rolled-back write is
        // a sidebar row for a channel that never existed.
        broadcast(new ChannelCreated($channel));

        return $channel;
    }

    private function seedMap(Channel $channel, string $preset): void
    {
        // Validation has already checked the key is one of ours; the fallback is belt and braces
        // against a future caller that skips the FormRequest.
        $map = MapPresets::find($preset) ?? MapPresets::find('blank');

        $channel->spaceMap()->create([
            'name' => $map['name'],
            'width' => $map['width'],
            'height' => $map['height'],
            'tiles' => $map['tiles'],
            'zones' => $map['zones'],
            'objects' => $map['objects'],
            'spawn' => $map['spawn'],
            // Only the artwork-backed presets carry any; everything else draws its tiles.
            'backdrops' => $map['backdrops'] ?? [],
            'portals' => $map['portals'] ?? [],
        ]);
    }
}
