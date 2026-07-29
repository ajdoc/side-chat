<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A message as a search *result*, which is not the same thing as a message.
 *
 * On a timeline a message needs no context — you are looking at the place it lives. Pulled
 * out of that place it is nearly useless without one: "yes, we shipped it" means nothing
 * until you know it was said in #releases, in a thread, last March. So this is the ordinary
 * message payload plus a `context` block naming where the row came from and how to get
 * back there.
 *
 * Extends MessageResource rather than reimplementing it, so a field added to a message
 * (a reaction summary, a new kind of card) shows up in search results for free.
 */
class SearchMessageResource extends MessageResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['context' => $this->context()];
    }

    /**
     * Where this message lives, flattened for a result row.
     *
     * `surface` is what the row is labelled with and `path` is where clicking it goes —
     * separate because they don't always agree: a thread reply is labelled with the
     * thread's name but navigates to the channel, which is the surface that can actually
     * scroll to a message.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $channel = $this->resource->channel;
        $conversation = $channel->relationLoaded('conversation') ? $channel->conversation : null;
        $server = $channel->relationLoaded('server') ? $channel->server : null;

        return [
            'channel_id' => $channel->id,
            'channel_name' => $channel->name,
            'channel_type' => $channel->type,
            'server_id' => $server?->id,
            'server_name' => $server?->name,
            'conversation_id' => $conversation?->id,
            // A DM has no name — the client already knows how to title one from its members,
            // so hand over the same members list the sidebar gets rather than a title that
            // would be wrong for whoever else receives it.
            'conversation_type' => $conversation?->type,
            'conversation_members' => $conversation
                ? UserResource::collection($conversation->members)
                : null,
            // The branch this was said on, if it wasn't the main timeline. Named, so the row
            // can say "in thread: deploy plan" rather than merely admitting it's elsewhere.
            'thread_id' => $this->thread_id,
            'thread_name' => $this->whenLoaded('thread', fn () => $this->thread?->name),
            'side_chat_id' => $this->side_chat_id,
            'side_chat_name' => $this->whenLoaded('sideChat', fn () => $this->sideChat?->name),
        ];
    }
}
