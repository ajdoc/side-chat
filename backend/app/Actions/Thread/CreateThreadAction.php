<?php

namespace App\Actions\Thread;

use App\DTOs\Thread\CreateThreadData;
use App\Events\ThreadCreated;
use App\Models\Channel;
use App\Models\SideChat;
use App\Models\Thread;
use App\Models\User;

final class CreateThreadAction
{
    public function handle(Channel $channel, User $user, CreateThreadData $data): Thread
    {
        return $this->create($channel->threads(), $user, $data);
    }

    /**
     * A thread that belongs to a side chat rather than the channel at large. It still
     * carries its channel_id (a side chat lives in a channel), but its side_chat_id is what
     * keeps it in the side chat's workspace and out of the channel's Threads list.
     */
    public function handleForSideChat(SideChat $sideChat, User $user, CreateThreadData $data): Thread
    {
        return $this->create($sideChat->threads(), $user, $data, ['channel_id' => $sideChat->channel_id]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Relations\HasMany<Thread, \Illuminate\Database\Eloquent\Model>  $relation
     * @param  array<string, mixed>  $extra
     */
    private function create($relation, User $user, CreateThreadData $data, array $extra = []): Thread
    {
        $thread = $relation->create([
            'user_id' => $user->id,
            'message_id' => $data->message_id,
            'name' => $data->name,
            ...$extra,
        ]);

        $thread->load(['creator', 'parentMessage.user'])->loadCount('messages');

        broadcast(new ThreadCreated($thread));

        return $thread;
    }
}
