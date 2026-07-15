<?php

namespace App\Actions\Thread;

use App\DTOs\Thread\CreateThreadData;
use App\Events\ThreadCreated;
use App\Models\Channel;
use App\Models\Thread;
use App\Models\User;

final class CreateThreadAction
{
    public function handle(Channel $channel, User $user, CreateThreadData $data): Thread
    {
        $thread = $channel->threads()->create([
            'user_id' => $user->id,
            'message_id' => $data->message_id,
            'name' => $data->name,
        ]);

        $thread->load(['creator', 'parentMessage.user'])->loadCount('messages');

        broadcast(new ThreadCreated($thread));

        return $thread;
    }
}
