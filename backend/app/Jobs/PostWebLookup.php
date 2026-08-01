<?php

namespace App\Jobs;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\User;
use App\Services\Web\WebLookup;
use App\Services\Web\WebLookupFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a `/web` lookup and posts the answer into the channel.
 *
 * On the queue, and that isn't optional: the lookup makes two calls to servers we don't
 * control, and doing that inside the send request would hold the composer open for as
 * long as the slower of them takes to answer — up to ten seconds when both are struggling,
 * for a command whose whole appeal is being quick to type. The same reasoning puts link
 * unfurling on the queue (see FetchLinkPreview); this is that pattern applied to a command.
 *
 * The answer lands as a system notice rather than a message from the person who asked.
 * They typed two words; they didn't write the paragraph that comes back.
 */
class PostWebLookup implements ShouldQueue
{
    use Queueable;

    /** One retry. If both sources are down, they'll still be down in a moment. */
    public int $tries = 2;

    /** Comfortably above two lookups at WebLookup's own timeout. */
    public int $timeout = 20;

    public function __construct(
        public int $channelId,
        public int $userId,
        public string $query,
    ) {}

    public function handle(WebLookup $lookup, WebLookupFormatter $formatter): void
    {
        $channel = Channel::find($this->channelId);
        $user = User::find($this->userId);

        // The channel can be deleted, or the asker can leave, between the command and the
        // answer. Both mean there's nobody to answer, not that something went wrong.
        if ($channel === null || $user === null || ! $channel->hasMember($user)) {
            return;
        }

        foreach ($formatter->format($this->query, $lookup->lookup($this->query)) as $body) {
            $message = $channel->messages()->create([
                'user_id' => $user->id,
                'body' => $body,
                'type' => 'system',
            ]);

            $message->load('user');

            broadcast(new MessageSent($message));
            // Addressed to the person who asked, so their sidebar calls it out if they've
            // since looked elsewhere — they're the one waiting on it.
            broadcast(new ChannelActivity($message, [$user->id]));
        }
    }
}
