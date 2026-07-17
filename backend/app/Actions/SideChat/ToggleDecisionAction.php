<?php

namespace App\Actions\SideChat;

use App\Events\MessageUpdated;
use App\Events\SideChatActivity;
use App\Models\Message;
use App\Models\User;

final class ToggleDecisionAction
{
    /**
     * Mark a side-chat message as a decision, or take the mark back. Modelled on a pin:
     * a nullable timestamp plus who set it. The refreshed message goes out on the side
     * chat's stream; the ✅ count on the card is refreshed by SideChatActivity.
     */
    public function handle(Message $message, User $user): Message
    {
        if ($message->isDecision()) {
            $message->update(['decided_at' => null, 'decided_by' => null]);
        } else {
            $message->update(['decided_at' => now(), 'decided_by' => $user->id]);
        }

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'comments.user', 'linkPreviews');

        broadcast(new MessageUpdated($message));

        $sideChat = $message->sideChat;
        if ($sideChat !== null) {
            broadcast(new SideChatActivity($sideChat));
        }

        return $message;
    }
}
