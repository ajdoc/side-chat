<?php

namespace App\Actions\Conversation;

use App\DTOs\Conversation\UpdateConversationData;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use Illuminate\Validation\ValidationException;

final class RenameConversationAction
{
    /**
     * Rename a group chat.
     *
     * A DM has no name to change: it is called "Ana" to you and "Ben" to Ana, resolved per
     * viewer at serialisation time (Conversation::titleFor), so there is nothing here to
     * write to.
     */
    public function handle(Conversation $conversation, UpdateConversationData $data): Conversation
    {
        if (! $conversation->isGroup()) {
            throw ValidationException::withMessages([
                'conversation' => 'A direct message can’t be renamed.',
            ]);
        }

        $conversation->update(['name' => $data->name]);
        $conversation->load('members');

        broadcast(new ConversationUpdated($conversation));

        return $conversation;
    }
}
