<?php

namespace App\Actions\SideChat;

use App\Events\SideChatActivity;
use App\Models\SideChat;

final class UpdateSideChatAction
{
    /**
     * Retitle a post and/or reset its tags.
     *
     * Tags arrive as whatever somebody typed, so they go through the model's normaliser
     * rather than being trusted — see {@link SideChat::normalizeTags}.
     *
     * Both fields are optional, and absent means "leave alone": the edit dialog can send
     * just the title without silently clearing the tags off a post.
     *
     * @param  array<int, string>|null  $tags
     */
    public function handle(SideChat $sideChat, ?string $name, ?array $tags): SideChat
    {
        $changes = [];

        if ($name !== null) {
            $changes['name'] = $name;
        }

        if ($tags !== null) {
            $changes['tags'] = SideChat::normalizeTags($tags);
        }

        if ($changes !== []) {
            $sideChat->update($changes);
        }

        broadcast(new SideChatActivity($sideChat));

        return $sideChat;
    }
}
