<?php

namespace App\Actions\Channel;

use App\Events\ChannelDeleted;
use App\Models\Channel;
use App\Services\AttachmentService;

final class DeleteChannelAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * Deletes a channel and everything under it.
     *
     * Threads, messages, reactions, read markers and voice seats all cascade at the FK
     * level. Uploaded files do not — nothing in the database knows how to delete bytes —
     * so they go first, while their rows are still there to point at them. See
     * AttachmentService::purgeForChannels(), which also sweeps the channel's directory.
     */
    public function handle(Channel $channel): void
    {
        $channelId = $channel->id;
        $serverId = $channel->server_id;

        $this->attachments->purgeForChannels([$channelId]);

        $channel->delete();

        broadcast(new ChannelDeleted($channelId, $serverId));
    }
}
