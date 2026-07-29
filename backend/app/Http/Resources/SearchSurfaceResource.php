<?php

namespace App\Http\Resources;

use App\Models\SideChat;
use App\Models\SideChatForum;
use App\Models\Thread;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A thread, a side chat, or a side-chat group, as one result row.
 *
 * Three models, one shape, because from the searcher's point of view they are three
 * spellings of the same thing: a named place inside a channel that you open by clicking
 * it. The palette shouldn't need three row components and three sets of routing rules to
 * say so, and the existing resources are the wrong tool — SideChatResource is the living
 * *card*, with reaction summaries, participant lists and four aggregate counts on it, all
 * of which a one-line result row throws away.
 *
 * `kind` is what the client routes on. It's derived from the model rather than passed in,
 * so a row can't be built claiming to be something it isn't.
 */
class SearchSurfaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $channel = $this->resource->channel;
        $conversation = $channel->relationLoaded('conversation') ? $channel->conversation : null;
        $server = $channel->relationLoaded('server') ? $channel->server : null;

        return [
            'kind' => $this->kind(),
            'id' => $this->id,
            'name' => $this->name,
            'channel_id' => $channel->id,
            'channel_name' => $channel->name,
            'server_id' => $server?->id,
            'conversation_id' => $conversation?->id,
            // A DM has no name, so the client titles it from the members exactly as the
            // sidebar does. Same reasoning as SearchMessageResource.
            'conversation_members' => $conversation
                ? UserResource::collection($conversation->members)
                : null,
            // Side chats only: the group this post is filed under, or null for Uncategorised.
            // It's the subtitle on the row — "Deploy plan, in Triage" locates a post the way
            // people actually remember it.
            'group_id' => $this->when($this->resource instanceof SideChat, fn () => $this->resource->side_chat_forum_id),
            'group_name' => $this->when(
                $this->resource instanceof SideChat,
                fn () => $this->resource->relationLoaded('forum') ? $this->resource->forum?->name : null,
            ),
            // Threads only, and only side-chat ones: opening the thread means opening the
            // side chat it lives in first, so the client needs both ids to build the URL.
            'side_chat_id' => $this->when($this->resource instanceof Thread, fn () => $this->resource->side_chat_id),
            'side_chat_name' => $this->when(
                $this->resource instanceof Thread,
                fn () => $this->resource->relationLoaded('sideChat') ? $this->resource->sideChat?->name : null,
            ),
            'created_at' => $this->created_at,
        ];
    }

    private function kind(): string
    {
        return match (true) {
            $this->resource instanceof Thread => 'thread',
            $this->resource instanceof SideChat => 'side_chat',
            $this->resource instanceof SideChatForum => 'side_chat_group',
        };
    }
}
