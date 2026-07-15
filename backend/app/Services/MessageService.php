<?php

namespace App\Services;

use App\Models\Channel;
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
            ->whereNull('thread_id') // thread replies live in their thread
            ->with([
                'user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews',
                'startedThread' => fn ($q) => $q->withCount('messages'),
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
            ->with(['user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews'])
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
