<?php

namespace App\Actions\SideChat;

use App\DTOs\Comment\AddCommentData;
use App\Events\SideChatActivity;
use App\Models\Comment;
use App\Models\SideChat;
use App\Models\User;

final class ToggleSideChatCommentAction
{
    /**
     * Leave a comment on a post, or take it back if this person already left that exact
     * phrase. The message-level twin is {@link \App\Actions\Comment\ToggleCommentAction},
     * and the toggle semantics are deliberately identical — a chip has to behave the same
     * wherever it's clicked.
     *
     * Normalisation goes through `Comment::normalize`, not a copy of it: a phrase must
     * group the same way on a post as it does on a message, or the same words would make
     * two chips depending on where they were typed.
     *
     * Broadcast rides SideChatActivity for the same reason the reaction does: a comment is
     * the card changing, and both listeners already redraw from the whole card.
     */
    public function handle(SideChat $sideChat, User $user, AddCommentData $data): SideChat
    {
        $bodyKey = Comment::normalize($data->body);

        $existing = $sideChat->comments()
            ->where('user_id', $user->id)
            ->where('body_key', $bodyKey)
            ->where('emoji', $data->emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $sideChat->comments()->create([
                'user_id' => $user->id,
                'body' => trim($data->body),
                'body_key' => $bodyKey,
                'emoji' => $data->emoji,
            ]);
        }

        broadcast(new SideChatActivity($sideChat));

        return $sideChat;
    }
}
