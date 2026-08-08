<?php

namespace App\Actions\Channel;

use App\Events\ChannelCreated;
use App\Models\Channel;
use Illuminate\Support\Facades\DB;

final class CreateDiscussionAction
{
    /**
     * Add a discussion to a channel.
     *
     * @param  Channel  $parent  the container — never itself a discussion
     * @param  Channel|null  $copyFrom  which sibling's map to start from, for a Side Space
     */
    public function handle(Channel $parent, string $name, ?Channel $copyFrom = null): Channel
    {
        $discussion = DB::transaction(function () use ($parent, $name, $copyFrom) {
            $discussion = $parent->discussions()->create([
                'server_id' => $parent->server_id,
                'conversation_id' => $parent->conversation_id,
                'name' => $name,
                // A discussion is the same kind of thing as the channel it hangs under: a
                // discussion of a voice channel holds a call, one of a Side Space holds a map.
                'type' => $parent->type,
                'position' => ((int) $parent->discussions()->max('position')) + 1,
            ]);

            if ($discussion->isSpace()) {
                $this->copyMap($discussion, $copyFrom ?? $parent->discussions()->first());
            }

            return $discussion;
        });

        // Same nudge a new channel sends, and for the same reason — see ChannelCreated. A
        // discussion appears in everybody's sidebar, so everybody has to be told to look again.
        broadcast(new ChannelCreated($discussion));

        return $discussion;
    }

    /**
     * Start the new room as a copy of a sibling's, walls, furniture and all.
     *
     * Not a blank map, and not a preset. A second discussion in a Side Space is somewhere else
     * to talk in the same *place*, and making people rebuild the room they already decorated
     * before they can use it is how a feature goes unused. Copying the General map is the
     * default because it's the one that exists; the picker only matters once there are three.
     *
     * The locks and room assignments deliberately don't come along: they name people and
     * passwords, and inheriting somebody else's locked door is a door nobody can open.
     */
    private function copyMap(Channel $discussion, ?Channel $source): void
    {
        $map = $source?->loadMissing('spaceMap')->spaceMap;

        if ($map === null) {
            return;
        }

        $discussion->spaceMap()->create([
            'name' => $map->name,
            'width' => $map->width,
            'height' => $map->height,
            'tiles' => $map->tiles,
            'zones' => $map->zones,
            'objects' => $map->objects,
            'spawn' => $map->spawn,
        ]);
    }
}
