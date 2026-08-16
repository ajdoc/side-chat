<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel in the admin list.
 *
 * Flatter than ChannelResource, and deliberately: the panel is a table, not a sidebar, so
 * it wants the counts and the settings rather than read state, unread badges or anything
 * scoped to who's looking. `parent_id` still matters — a discussion is a channel with a
 * parent (see DISCUSSIONS.md), and the table indents them under their container.
 */
class AdminChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'conversation_id' => $this->conversation_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'type' => $this->type,
            'position' => $this->position,
            'is_private' => (bool) $this->is_private,
            'encrypted' => (bool) $this->encrypted,
            'messages_count' => $this->whenCounted('messages'),
            'discussions_count' => $this->whenCounted('discussions'),
            'created_at' => $this->created_at,
        ];
    }
}
