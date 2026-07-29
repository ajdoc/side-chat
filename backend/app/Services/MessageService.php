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
     * `around` is the other way in, and it exists for search: clicking a result from March
     * has to land on that message with the conversation either side of it, which no amount
     * of paging backwards from today will reach in reasonable time. Mutually exclusive with
     * `before` — you are either walking the timeline or jumping into it.
     *
     * @return array{messages: Collection, has_more: bool, has_newer: bool}
     */
    public function forChannel(Channel $channel, ?int $before = null, ?int $around = null): array
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

        return $around !== null
            ? $this->around($query, $around)
            : $this->keyset($query, $before);
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
            // The plain page always ends at the newest message, so there is never anything
            // below it. Only a jump (see below) can land mid-timeline.
            'has_newer' => false,
        ];
    }

    /**
     * A window centred on one message — half the page either side of it.
     *
     * Two queries rather than one clever one: "the 100 messages before id X" and "the 100
     * from X onwards" are different sorts in different directions, and expressing that as a
     * single statement costs a union and a subquery to save a round trip nobody is counting.
     *
     * The target itself comes from the *newer* half, so a jump to a message that has since
     * been deleted returns the conversation around where it was rather than an empty page —
     * the client scrolls to the nearest id it got and highlights nothing.
     *
     * @return array{messages: Collection, has_more: bool, has_newer: bool}
     */
    private function around($query, int $target): array
    {
        $half = (int) (self::PER_PAGE / 2);

        $older = (clone $query)->where('id', '<', $target)->limit($half + 1)->get();
        $newer = (clone $query)->where('id', '>=', $target)->reorder('id')->limit($half + 1)->get();

        return [
            'messages' => $older->take($half)->reverse()
                ->concat($newer->take($half))
                ->values(),
            'has_more' => $older->count() > $half,
            'has_newer' => $newer->count() > $half,
        ];
    }
}
