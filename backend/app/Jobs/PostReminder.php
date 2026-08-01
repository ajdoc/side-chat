<?php

namespace App\Jobs;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Posts a `/remind` back into the channel when its time comes.
 *
 * A delayed job holds the whole reminder — there is no reminders table. The trade that
 * makes is worth stating plainly: the queue is the storage, so a reminder survives a deploy
 * and a worker restart (both drivers persist), but not `queue:flush` or a wiped Redis. For
 * "poke me about the migration in 20 minutes" that's the right size of promise; a reminder
 * anyone would be hurt to lose belongs in the calendar app, which is built for it.
 *
 * The reminder lands as a system notice rather than a message from the person who asked
 * for it. They didn't say anything twenty minutes later — the room did.
 */
class PostReminder implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $channelId,
        public int $userId,
        public string $text,
    ) {}

    public function handle(): void
    {
        $channel = Channel::find($this->channelId);
        $user = User::find($this->userId);

        // The channel can be deleted, or the person can leave, between asking and being
        // reminded. Both mean there's nobody to remind, not that something went wrong.
        if ($channel === null || $user === null || ! $channel->hasMember($user)) {
            return;
        }

        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'body' => "⏰ **Reminder** — {$this->text}",
            'type' => 'system',
        ]);

        $message->load('user');

        broadcast(new MessageSent($message));
        // Addressed to the one person who asked, so their sidebar calls it out rather than
        // showing it as one more unread — which for a reminder is the entire point.
        broadcast(new ChannelActivity($message, [$user->id]));
    }
}
