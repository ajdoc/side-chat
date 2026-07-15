<?php

namespace App\Actions\Conversation;

use App\DTOs\Conversation\CreateGroupData;
use App\Events\ConversationCreated;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateGroupAction
{
    /**
     * Start a group chat. The creator owns it — the only thing that buys them is the right
     * to rename it and to add people; there is no power to delete everyone's history.
     */
    public function handle(User $user, CreateGroupData $data): Conversation
    {
        $conversation = DB::transaction(function () use ($user, $data): Conversation {
            $conversation = Conversation::create([
                'type' => 'group',
                'name' => $data->name,
                'owner_id' => $user->id,
            ]);

            $conversation->members()->attach(array_unique([$user->id, ...$data->user_ids]));
            $conversation->channel()->create(['name' => 'group', 'type' => 'text']);

            return $conversation;
        });

        $conversation->load('members', 'channel');

        broadcast(new ConversationCreated($conversation));

        return $conversation;
    }
}
