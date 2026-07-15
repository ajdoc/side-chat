<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\Message;

final class MessageInfoService
{
    public function __construct(
        private readonly ReadReceiptService $reads,
        private readonly ReactionService $reactions,
    ) {}

    /**
     * Everything the "message info" panel shows: who saw it, who hasn't, who reacted.
     *
     * The sender is left out of both seen lists — they obviously saw their own message,
     * and listing them under "Not seen by" would be nonsense.
     *
     * @return array<string, mixed>
     */
    public function for(Message $message): array
    {
        $message->loadMissing('channel.server');

        $members = $message->channel->server->members()->get()
            ->reject(fn ($member) => $member->id === $message->user_id);

        $seen = $this->reads->seenBy($message);

        [$seenMembers, $unseenMembers] = $members->partition(fn ($member) => $seen->has($member->id));

        return [
            'message_id' => $message->id,
            /*
             * Read markers are per *channel*, and clients only ever advance them to
             * main-timeline messages — so for a thread reply the marker says nothing
             * about whether anyone opened the thread. Rather than show a confidently
             * wrong "Seen by", we tell the UI receipts don't apply here.
             */
            'receipts_tracked' => $message->thread_id === null,
            'seen_by' => $seenMembers
                ->map(fn ($member) => [
                    'user' => (new UserResource($member))->resolve(),
                    'read_at' => $seen->get($member->id)->read_at,
                ])
                // Most recent reader first.
                ->sortByDesc('read_at')
                ->values()
                ->all(),
            'not_seen_by' => $unseenMembers
                ->map(fn ($member) => (new UserResource($member))->resolve())
                ->values()
                ->all(),
            'reactions' => $this->reactions->summarize($message),
        ];
    }
}
