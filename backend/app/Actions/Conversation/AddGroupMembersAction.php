<?php

namespace App\Actions\Conversation;

use App\Actions\Message\PostSystemMessageAction;
use App\DTOs\Conversation\AddMembersData;
use App\Events\ConversationCreated;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AddGroupMembersAction
{
    public function __construct(private readonly PostSystemMessageAction $system) {}

    /**
     * Add people to a group chat.
     *
     * A DM is closed by definition — it is *the* conversation between two people, and the
     * dm_key that guarantees that uniqueness has no meaning for three. Wanting a third
     * person means wanting a different thing, which is a group.
     *
     * @return array<int, User> the people actually added
     */
    public function handle(Conversation $conversation, User $actor, AddMembersData $data): array
    {
        if (! $conversation->isGroup()) {
            throw ValidationException::withMessages([
                'conversation' => 'You can’t add people to a direct message — start a group chat instead.',
            ]);
        }

        $existing = $conversation->memberIds();
        $incoming = array_values(array_diff(array_unique($data->user_ids), $existing));

        if ($incoming === []) {
            return []; // they're all already in it; nothing happened, and that's fine
        }

        if (count($existing) + count($incoming) > Conversation::MAX_GROUP_MEMBERS) {
            throw ValidationException::withMessages([
                'user_ids' => sprintf(
                    'A group chat can hold %d people.',
                    Conversation::MAX_GROUP_MEMBERS,
                ),
            ]);
        }

        $conversation->members()->attach($incoming);
        $conversation->load('members', 'channel');

        $added = User::whereIn('id', $incoming)->get();

        foreach ($added as $user) {
            $this->system->handle(
                $conversation->channel,
                $actor,
                sprintf('%s added %s to the group.', $actor->name, $user->name),
            );
        }

        // The new arrivals have never heard of this conversation, so they're told
        // personally; everyone already in it just needs their member list refreshed.
        broadcast(new ConversationCreated($conversation, $incoming));
        broadcast(new ConversationUpdated($conversation));

        return $added->all();
    }
}
