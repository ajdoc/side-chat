<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\SideChat;
use App\Models\Thread;
use Illuminate\Support\Collection;

final class MessageService
{
    public const PER_PAGE = 200;

    /**
     * Latest page of a channel's main timeline; `before` walks backwards in time.
     *
     * @return array{messages: Collection, has_more: bool}
     */
    public function forChannel(Channel $channel, ?int $before = null): array
    {
        $query = $channel->messages()
            ->whereNull('thread_id')    // thread replies live in their thread
            ->whereNull('side_chat_id') // side-chat messages live in their side chat
            ->with([
                'user', 'replyTo.user', 'forwardedFrom.user', 'attachments', 'reactions.user', 'comments.user', 'linkPreviews',
                'startedThread' => fn ($q) => $q->withCount('messages'),
                'startedSideChat' => fn ($q) => app(SideChatService::class)->applyCardData($q),
                'widget',
            ])
            ->orderByDesc('id');

        return $this->keyset($query, $before);
    }

    /**
     * @return array{messages: Collection, has_more: bool}
     */
    public function forThread(Thread $thread, ?int $before = null): array
    {
        $query = $thread->messages()
            ->with(['user', 'replyTo.user', 'attachments', 'reactions.user', 'comments.user', 'linkPreviews'])
            ->orderByDesc('id');

        return $this->keyset($query, $before);
    }

    /**
     * A side chat's timeline. Same shape as a thread's — the only difference is which
     * branch column addresses it.
     *
     * @return array{messages: Collection, has_more: bool}
     */
    public function forSideChat(SideChat $sideChat, ?int $before = null): array
    {
        $query = $sideChat->messages()
            ->with([
                'user', 'replyTo.user', 'attachments', 'reactions.user', 'comments.user', 'linkPreviews',
                // A side chat's messages can start threads of their own, so load the indicator
                // the same way the channel timeline does — otherwise the thread never shows on
                // the message it was branched from.
                'startedThread' => fn ($q) => $q->withCount('messages'),
            ])
            ->orderByDesc('id');

        return $this->keyset($query, $before);
    }

    /**
     * Keyset pagination: fetch one extra row to know whether older messages exist,
     * then return the page in chronological order.
     *
     * @return array{messages: Collection, has_more: bool}
     */
    private function keyset($query, ?int $before): array
    {
        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $rows = $query->limit(self::PER_PAGE + 1)->get();

        return [
            'messages' => $rows->take(self::PER_PAGE)->reverse()->values(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }
}
