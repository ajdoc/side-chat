<?php

namespace App\Actions\SideChat;

use App\Events\SideChatActivity;
use App\Models\SideChat;
use App\Models\User;

final class ToggleSideChatReactionAction
{
    /**
     * React to a post, or take the reaction back if it's already there.
     *
     * Reuses SideChatActivity rather than adding an event of its own: a reaction *is* the
     * card changing, and the card is what both listeners (the timeline card and the open
     * panel header) already redraw from. Piggybacking means the forum list's reaction
     * chips go live with no new handler on the client at all.
     */
    public function handle(SideChat $sideChat, User $user, string $emoji): SideChat
    {
        $existing = $sideChat->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $sideChat->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]);
        }

        broadcast(new SideChatActivity($sideChat));

        return $sideChat;
    }
}
