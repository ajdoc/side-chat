<?php

namespace App\Actions\Channel;

use App\Events\ChannelUpdated;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\VoiceParticipant;
use App\Support\SideSpace\MapSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning a channel into a different kind of channel.
 *
 * ## Why this is now allowed
 *
 * `UpdateChannelData` used to say "a channel's type is what it *is* — text and voice aren't
 * interchangeable", and as a statement about the *body* that's still true: a text channel shows
 * a timeline, a voice channel a call, a Side Space a map. What changed is the observation that
 * **everything underneath is shared anyway**. A Side Space is a timeline with a map over it; a
 * voice channel is a timeline with a call over it. Messages, threads, side chats, reads, pins,
 * the Side Desk and every app hang off the channel and have never known which of the three they
 * were in.
 *
 * So a conversion moves the lid, not the contents. Nothing is copied, nothing is deleted, and the
 * history stays exactly where it was — which is what makes "we should have made this a voice
 * channel" a two-second fix instead of a new channel and a dead one beside it.
 *
 * ## What it refuses
 *
 * **App channels, both ways.** An app channel's body is an application with a row in
 * `channel_apps` and storage of its own; converting one out would leave that row pointing at a
 * channel that no longer renders it, and converting one *in* would have to invent which app.
 * Installing and uninstalling an app is the operation that means this, and it already exists.
 *
 * ## What it carries over
 *
 * - **Becoming a Side Space** seeds a map if the channel hasn't got one, because a space with no
 *   map is a room nobody can stand in. An existing map is left alone: converting away and back
 *   must not bulldoze the furniture somebody placed.
 * - **Leaving a room** ends the call in it. A server's text channel doesn't allow calls
 *   ({@see Channel::allowsCalls}), so the people sitting in one would become participants of a
 *   room that no longer exists — present in the sidebar, unreachable in the UI. A conversation's
 *   channel always allows calls, so a group chat that becomes a Side Space and back keeps its
 *   call running.
 */
final class ChangeChannelTypeAction
{
    /** The three that are interchangeable. `app` is deliberately not among them. */
    public const CONVERTIBLE = ['text', 'voice', 'space'];

    public function handle(Channel $channel, string $type, ?string $mapPreset = null): Channel
    {
        if (! in_array($type, self::CONVERTIBLE, true) || ! in_array($channel->type, self::CONVERTIBLE, true)) {
            throw ValidationException::withMessages([
                'type' => 'Only text, voice and Side Space channels can be converted into each other.',
            ]);
        }

        if ($channel->type === $type) {
            return $channel;
        }

        $was = $channel->type;

        DB::transaction(function () use ($channel, $type, $was, $mapPreset) {
            $channel->update(['type' => $type]);

            if ($type === 'space') {
                MapSeeder::ensure($channel, $mapPreset);
            }

            /*
             * A discussion is a channel with a parent, and it inherited the parent's type when it
             * was made (see CreateDiscussionAction). Converting the container converts them with
             * it, so a "Design" voice channel's discussions don't stay voice rooms nobody can see
             * a call in. Only the ones that still match what the container *was* — a discussion
             * somebody deliberately made different is left as they made it.
             */
            foreach ($channel->discussions()->where('type', $was)->get() as $discussion) {
                $discussion->update(['type' => $type]);

                if ($type === 'space') {
                    MapSeeder::ensure($discussion, $mapPreset);
                }

                if (! $discussion->allowsCalls()) {
                    $this->endCall($discussion);
                }

                broadcast(new ChannelUpdated($discussion));
            }

            // Refreshed, because `allowsCalls` reads the type we just wrote.
            if (! $channel->refresh()->allowsCalls()) {
                $this->endCall($channel);
            }
        });

        broadcast(new ChannelUpdated($channel));

        return $channel;
    }

    /**
     * Turn out whoever is still in the call.
     *
     * The rows, not a polite request: their clients find out from the broadcast, and a seat in a
     * room the UI no longer draws is a ghost in the sidebar that nothing would ever clear.
     */
    private function endCall(Channel $channel): void
    {
        if (VoiceParticipant::where('channel_id', $channel->getKey())->delete() > 0) {
            broadcast(new VoiceStateUpdated($channel));
        }
    }
}
