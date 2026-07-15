<?php

namespace App\Actions\Server;

use App\Events\ServerDeleted;
use App\Models\Server;
use App\Services\AttachmentService;

final class DeleteServerAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * Deletes a server and everything in it: channels, threads, messages, reactions, read
     * markers, voice seats, memberships and pending join requests all cascade from the
     * server row.
     *
     * The files are the exception, as always — they are purged channel by channel first,
     * because after the delete there is no longer any row that knows where they live.
     */
    public function handle(Server $server): void
    {
        $serverId = $server->id;
        $name = $server->name;

        $this->attachments->purgeForChannels($server->channels()->pluck('id')->all());

        $server->delete();

        broadcast(new ServerDeleted($serverId, $name));
    }
}
