<?php

namespace App\Actions\Channel;

use App\DTOs\Channel\CreateChannelData;
use App\Models\Channel;
use App\Models\Server;
use App\Support\SideSpace\MapPresets;
use Illuminate\Support\Facades\DB;

final class CreateChannelAction
{
    public function handle(Server $server, CreateChannelData $data): Channel
    {
        return DB::transaction(function () use ($server, $data) {
            $channel = $server->channels()->create([
                'name' => $data->name,
                'type' => $data->type,
                'position' => ((int) $server->channels()->max('position')) + 1,
            ]);

            // A Side Space is a room, and a room without a map is a channel you can open but not
            // stand in. So it's seeded here, inside the transaction, rather than lazily on first
            // visit — there is no moment at which the channel exists and the room doesn't.
            if ($channel->isSpace()) {
                $this->seedMap($channel, (string) $data->preset);
            }

            return $channel;
        });
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
            'spawn' => $map['spawn'],
        ]);
    }
}
