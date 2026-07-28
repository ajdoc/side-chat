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
     * Every field is optional, and absent means "leave alone": the edit dialog can send
     * just the title without silently clearing the tags off a post.
     *
     * The forum needs two parameters rather than one because null is a *value* here —
     * "move this post back to Uncategorised" — and so can't double as "wasn't sent".
     * `$movesForum` is the sent-or-not; `$forumId` is what it was set to.
     *
     * @param  array<int, string>|null  $tags
     */
    public function handle(SideChat $sideChat, ?string $name, ?array $tags, bool $movesForum = false, ?int $forumId = null): SideChat
    {
        $changes = [];

        if ($name !== null) {
            $changes['name'] = $name;
        }

        if ($tags !== null) {
            $changes['tags'] = SideChat::normalizeTags($tags);
        }

        if ($movesForum) {
            $changes['side_chat_forum_id'] = $forumId;
        }

        if ($changes !== []) {
            $sideChat->update($changes);
        }

        broadcast(new SideChatActivity($sideChat));

        return $sideChat;
    }
}
