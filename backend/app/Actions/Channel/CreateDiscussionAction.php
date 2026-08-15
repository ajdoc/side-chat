<?php

namespace App\Actions\Channel;

use App\Events\ChannelCreated;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateDiscussionAction
{
    /**
     * Add a discussion to a channel.
     *
     * @param  Channel  $parent  the container — never itself a discussion
     * @param  Channel|null  $copyFrom  which sibling's map to start from, for a Side Space
     * @param  string|null  $appId  which app this one is, for an app channel; null inherits
     * @param  User|null  $installedBy  recorded as the app's installer, for an app channel
     */
    public function handle(
        Channel $parent,
        string $name,
        ?Channel $copyFrom = null,
        ?string $appId = null,
        ?User $installedBy = null,
    ): Channel {
        $discussion = DB::transaction(function () use ($parent, $name, $copyFrom, $appId, $installedBy) {
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

            // A new discussion in an app channel starts as the same app its siblings are, unless
            // the caller asked for another. Same reasoning as copying a sibling's map: the
            // container is named for what it's *for* ("Design"), so the useful default is more
            // of that, and picking an app is then an override rather than a required step.
            if ($discussion->isApp()) {
                $discussion->setRelation('app', $discussion->app()->create([
                    'app_id' => $appId ?? $this->siblingApp($parent, $copyFrom) ?? 'tracker',
                    'installed_by' => $installedBy?->getKey(),
                ]));
            }

            return $discussion;
        });

        // Same nudge a new channel sends, and for the same reason — see ChannelCreated. A
        // discussion appears in everybody's sidebar, so everybody has to be told to look again.
        broadcast(new ChannelCreated($discussion));

        return $discussion;
    }

    /**
     * Which app this channel's existing discussions are, so a new one can be more of the same.
     *
     * Falls back across siblings rather than trusting one row: the named `copyFrom` is what the
     * caller pointed at, and any other discussion answers the question just as well when they
     * didn't. Null only if none of them has an app row at all, which the caller reads as "no
     * opinion" and resolves to the default.
     */
    private function siblingApp(Channel $parent, ?Channel $copyFrom): ?string
    {
        $named = $copyFrom?->loadMissing('app')->app?->app_id;

        return $named ?? $parent->discussions()
            ->whereHas('app')
            ->with('app')
            ->first()?->app?->app_id;
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
        $maps = $source?->loadMissing('spaceMaps')->spaceMaps ?? collect();

        foreach ($maps as $map) {
            $discussion->spaceMaps()->create([
                /*
                 * The slug comes along unchanged, and that is what makes the copy work.
                 *
                 * A Side Space is a building now — an overworld and its interiors, joined by
                 * portals that name their destination by *slug*. Copy the rooms under fresh
                 * slugs and every door in the copy points at a name that isn't there; copy them
                 * under the same ones and the whole building arrives with its doors working,
                 * pointing within itself, having touched nothing about the original.
                 *
                 * `portals` is copied for the same reason, and its absence here was a latent
                 * bug even while a channel had one map: a room duplicated without its doorways
                 * is not the room that was duplicated.
                 */
                'slug' => $map->slug,
                'name' => $map->name,
                'width' => $map->width,
                'height' => $map->height,
                'tiles' => $map->tiles,
                'zones' => $map->zones,
                'objects' => $map->objects,
                'spawn' => $map->spawn,
                'projection' => $map->projection,
                'backdrops' => $map->backdrops,
                'portals' => $map->portals,
                'screens' => $map->screens,
                // The frames, not what is hanging in them: the pictures are staff-uploaded rows
                // against the *original* map, and copying files into a duplicate silently is a
                // decision this action has no business making.
                'exhibits' => $map->exhibits,
            ]);
        }
    }
}
