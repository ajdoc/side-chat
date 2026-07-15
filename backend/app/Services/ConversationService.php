<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

final class ConversationService
{
    public const PER_PAGE = 200;

    public function __construct(private readonly ReadReceiptService $reads) {}

    /**
     * The Chats section of the sidebar: every DM and group chat this user is in, each with
     * its unread count, most recently active first.
     *
     * Ordered by the last thing *said* rather than by when the chat was made, because a
     * chat list sorted by creation date is a chat list you have to search. The sub-select
     * is one query for the whole page — the alternative (loading each conversation's
     * channel and asking it for its latest message) is the N+1 that Model::preventLazyLoading
     * exists to catch.
     */
    public function forUser(User $user): LengthAwarePaginator
    {
        $conversations = $user->conversations()
            ->with(['members', 'channel'])
            ->addSelect([
                'last_message_at' => Message::query()
                    ->selectRaw('max(messages.created_at)')
                    ->join('channels', 'channels.id', '=', 'messages.channel_id')
                    ->whereColumn('channels.conversation_id', 'conversations.id'),
            ])
            // A brand-new chat with nothing in it yet belongs at the bottom, not nowhere.
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('conversations.id')
            ->paginate(self::PER_PAGE);

        $this->attachUnreadCounts($user, $conversations->getCollection());

        return $conversations;
    }

    /**
     * People this user is allowed to start a chat with: anyone they share a server with.
     *
     * The rule is the point. Without it, "search users" is a directory of every account on
     * the instance and a DM is a message anyone can put in front of anyone — which is spam,
     * and which no amount of blocking afterwards really undoes. Sharing a server is the
     * weakest thing that still means "we have somewhere in common", and it's already how
     * you'd have met.
     *
     * @return Collection<int, User>
     */
    public function contactsFor(User $user, ?string $query = null, int $limit = 20): Collection
    {
        return User::query()
            ->whereKeyNot($user->getKey())
            ->whereHas('servers', fn ($servers) => $servers->whereIn(
                'servers.id',
                $user->servers()->select('servers.id'),
            ))
            ->when($query, fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'ilike', '%'.$query.'%')
                    ->orWhere('email', 'ilike', '%'.$query.'%'),
            ))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function sharesAServerWith(User $user, User $other): bool
    {
        return $other->servers()
            ->whereIn('servers.id', $user->servers()->select('servers.id'))
            ->exists();
    }

    /**
     * Badge each chat with what the caller hasn't read.
     *
     * Reuses the channel-level read receipts wholesale — a chat's unread count *is* its
     * channel's unread count, because a chat is a channel. Which is exactly the payoff of
     * having built DMs on top of channels rather than beside them.
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    private function attachUnreadCounts(User $user, Collection $conversations): void
    {
        $channelIds = $conversations
            ->map(fn (Conversation $c) => $c->channel?->id)
            ->filter()
            ->all();

        $counts = $this->reads->unreadCounts($user, $channelIds);

        $conversations->each(function (Conversation $conversation) use ($counts): void {
            $conversation->unread_count = (int) ($counts[$conversation->channel?->id] ?? 0);
        });
    }
}
