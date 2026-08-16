<?php

namespace App\Actions\Admin;

use App\Models\Server;
use App\Models\User;
use App\Services\AttachmentService;

/**
 * Removes an account and everything the database hangs off it.
 *
 * This is a wide delete, and the panel says so before it runs: `servers.owner_id` cascades,
 * so a person who owns servers takes those servers — and their channels, messages and
 * memberships — with them. Everyone else's messages in those servers included. Banning is
 * the reversible tool; this one isn't, and is meant for spam registrations and GDPR
 * erasures rather than for moderation.
 *
 * Files are purged first, for the same reason as everywhere else: nothing in the database
 * knows how to delete bytes, and after the cascade there's no row left pointing at them.
 */
final class DeleteUserAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function handle(User $user): void
    {
        $channelIds = Server::where('owner_id', $user->getKey())
            ->with('channels:id,server_id')
            ->get()
            ->flatMap(fn (Server $server) => $server->channels->pluck('id'))
            ->all();

        if ($channelIds !== []) {
            $this->attachments->purgeForChannels($channelIds);
        }

        $user->delete();
    }
}
