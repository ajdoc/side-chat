<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\SideChat;
use App\Models\SideChatForum;
use App\Models\Thread;
use App\Models\User;
use App\Search\SearchDriver;
use App\Search\SearchFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Search, for every kind of thing this app holds.
 *
 * They look like many features and are really two. **Retrieval** — messages, channels, DMs,
 * group chats, side chats, threads and the groups those fold under — is "show me the things
 * I already have", and every one of them reduces to the same question: which channels may
 * this person read? That question has one answer, {@see Channel::scopeVisibleTo}, and every
 * method below joins against it rather than restating it. **Discovery** — servers — is the
 * odd one out, and the only one where a result can be something you aren't already in; see
 * {@see servers()} for what that costs.
 *
 * Note the shape of the retrieval half: one prose search (message bodies) and a pile of
 * *name* searches. That isn't padding. A channel, a side chat, a thread and a group are all
 * things somebody deliberately titled so it could be found again, and until each of them is
 * searchable the only way to reach one is to remember something said inside it.
 *
 * Nothing here knows how a text match is expressed. That's the driver's job, so that the
 * visibility rules and the filters — the parts where a mistake leaks somebody's private
 * channel — exist exactly once regardless of which database is underneath.
 */
final class SearchService
{
    public const PER_PAGE = 25;

    /** How many of each kind the command palette gets. It is a jump list, not a report. */
    private const PALETTE_LIMITS = [
        'conversations' => 5,
        'channels' => 5,
        'side_chats' => 4,
        'threads' => 3,
        'side_chat_groups' => 2,
        'servers' => 3,
        'messages' => 6,
    ];

    public function __construct(private readonly SearchDriver $driver) {}

    /**
     * Everything at once, a few of each — what ⌘K opens onto.
     *
     * Places first and messages last, and both the order and the small message allowance
     * are the point: someone who hits the palette and types three letters is almost always
     * trying to *go* somewhere, and a jump list buried under eight message excerpts is a
     * jump list you scroll past.
     *
     * @return array<string, Collection>
     */
    public function everything(User $user, string $term, SearchFilters $filters): array
    {
        return [
            'conversations' => $this->conversations($user, $term)->take(self::PALETTE_LIMITS['conversations'])->get(),
            'channels' => $this->channels($user, $term, $filters)->take(self::PALETTE_LIMITS['channels'])->get(),
            // Between the channels and the servers on purpose: a side chat and a thread are
            // places *inside* a channel, and the list reads top-down from the smallest thing
            // you might have meant to the largest.
            'side_chats' => $this->sideChats($user, $term, $filters)->take(self::PALETTE_LIMITS['side_chats'])->get(),
            'threads' => $this->threads($user, $term, $filters)->take(self::PALETTE_LIMITS['threads'])->get(),
            'side_chat_groups' => $this->sideChatGroups($user, $term, $filters)->take(self::PALETTE_LIMITS['side_chat_groups'])->get(),
            'servers' => $this->servers($user, $term)->take(self::PALETTE_LIMITS['servers'])->get(),
            'messages' => $this->messages($user, $term, $filters)->take(self::PALETTE_LIMITS['messages'])->get(),
        ];
    }

    /** @return LengthAwarePaginator<SideChat> */
    public function sideChatPage(User $user, string $term, SearchFilters $filters): LengthAwarePaginator
    {
        return $this->sideChats($user, $term, $filters)->paginate(self::PER_PAGE);
    }

    /** @return LengthAwarePaginator<Thread> */
    public function threadPage(User $user, string $term, SearchFilters $filters): LengthAwarePaginator
    {
        return $this->threads($user, $term, $filters)->paginate(self::PER_PAGE);
    }

    /** @return LengthAwarePaginator<SideChatForum> */
    public function sideChatGroupPage(User $user, string $term, SearchFilters $filters): LengthAwarePaginator
    {
        return $this->sideChatGroups($user, $term, $filters)->paginate(self::PER_PAGE);
    }

    /** @return LengthAwarePaginator<Message> */
    public function messagePage(User $user, string $term, SearchFilters $filters): LengthAwarePaginator
    {
        return $this->messages($user, $term, $filters)->paginate(self::PER_PAGE);
    }

    /** @return LengthAwarePaginator<Channel> */
    public function channelPage(User $user, string $term, SearchFilters $filters): LengthAwarePaginator
    {
        return $this->channels($user, $term, $filters)->paginate(self::PER_PAGE);
    }

    /** @return LengthAwarePaginator<Conversation> */
    public function conversationPage(User $user, string $term): LengthAwarePaginator
    {
        return $this->conversations($user, $term)->paginate(self::PER_PAGE);
    }

    /** @return LengthAwarePaginator<Server> */
    public function serverPage(User $user, string $term): LengthAwarePaginator
    {
        return $this->servers($user, $term)->paginate(self::PER_PAGE);
    }

    /**
     * Messages you may read whose body matches.
     *
     * Note what is *not* excluded: thread replies and side-chat messages are in scope. A
     * thing said in this channel is a thing said in this channel, and the person searching
     * knows perfectly well that they said it in a thread — hiding it because of which
     * column addresses it would be the search failing at its one job. The results carry
     * where they live (see SearchMessageResource) so the UI can say so, and the side-chat
     * roster check inside {@see Message::scopeVisibleTo} keeps the private ones private.
     *
     * @return Builder<Message>
     */
    private function messages(User $user, string $term, SearchFilters $filters): Builder
    {
        $query = Message::query()
            ->visibleTo($user)
            // System messages ("X joined the server") are furniture. They match common
            // words, there are a lot of them, and nobody has ever gone looking for one.
            ->where('type', '!=', 'system')
            ->with([
                'user',
                'attachments',
                'channel.server',
                'channel.conversation.members',
                'sideChat:id,name',
                'thread:id,name',
            ]);

        $this->applyScope($query, $filters, 'channel_id');

        if ($filters->fromUserId !== null) {
            $query->where('user_id', $filters->fromUserId);
        }

        if ($filters->after !== null) {
            $query->where('created_at', '>=', $filters->after);
        }

        if ($filters->before !== null) {
            $query->where('created_at', '<', $filters->before);
        }

        match ($filters->has) {
            'link' => $query->whereHas('linkPreviews'),
            'file' => $query->whereHas('attachments'),
            'image' => $query->whereHas('attachments', fn ($q) => $q->where('mime_type', 'like', 'image/%')),
            default => null,
        };

        $this->driver->matchProse($query, 'body', $term);

        return $query;
    }

    /**
     * Channels you may read whose name matches.
     *
     * Voice channels and Side Spaces are in scope, not just text ones — "where is the
     * standup room" is the same question as "where is the standup channel", and the
     * sidebar's own grouping is what tells them apart once you're looking at the result.
     *
     * @return Builder<Channel>
     */
    private function channels(User $user, string $term, SearchFilters $filters): Builder
    {
        $query = Channel::query()
            ->visibleTo($user)
            // A DM's channel has no name of its own and is reached as a conversation — it
            // would list as a nameless row that duplicates one already in the Chats group.
            ->whereNotNull('server_id')
            ->with('server:id,name');

        if ($filters->serverId !== null) {
            $query->where('server_id', $filters->serverId);
        }

        $this->driver->matchName($query, 'name', $term);

        return $query->orderBy('name');
    }

    /**
     * Side chats — the posts — by title.
     *
     * Worth searching separately from their messages, and this is the case for the whole
     * of this group of methods: a side chat's title is the one line somebody *wrote to be
     * remembered by*. "Deploy plan" is how people refer to it out loud, and until now the
     * only way to find it was to remember a phrase from inside it.
     *
     * @return Builder<SideChat>
     */
    private function sideChats(User $user, string $term, SearchFilters $filters): Builder
    {
        $query = SideChat::query()
            ->visibleTo($user)
            ->with(['channel.server', 'channel.conversation.members', 'forum:id,name']);

        $this->applyScope($query, $filters, 'channel_id');
        $this->driver->matchName($query, 'name', $term);

        return $query->orderByDesc('id');
    }

    /**
     * Threads by title.
     *
     * Includes a side chat's own threads, for whoever is in that side chat — the roster
     * check is inside {@see Thread::scopeVisibleTo}. The result carries the side chat's id,
     * because opening one of those threads means opening the side chat around it first.
     *
     * @return Builder<Thread>
     */
    private function threads(User $user, string $term, SearchFilters $filters): Builder
    {
        $query = Thread::query()
            ->visibleTo($user)
            ->with(['channel.server', 'channel.conversation.members', 'sideChat:id,name']);

        $this->applyScope($query, $filters, 'channel_id');
        $this->driver->matchName($query, 'name', $term);

        return $query->orderByDesc('id');
    }

    /**
     * The groups a channel's side chat list folds under.
     *
     * Last and smallest of the three, and the one most likely to be what somebody means by
     * a bare word: "Triage" is a heading far more often than it is a message. Finding one
     * opens the list with that group unfolded — see the client's routing.
     *
     * @return Builder<SideChatForum>
     */
    private function sideChatGroups(User $user, string $term, SearchFilters $filters): Builder
    {
        $query = SideChatForum::query()
            ->visibleTo($user)
            ->with(['channel.server', 'channel.conversation.members']);

        $this->applyScope($query, $filters, 'channel_id');
        $this->driver->matchName($query, 'name', $term);

        return $query->orderBy('name');
    }

    /**
     * Your DMs and group chats.
     *
     * Matched on the *members*' names as well as the chat's own, because a DM has no name
     * — searching "ana" and being told there are no chats called that, while a DM with Ana
     * sits in the sidebar, is the single most obvious way this feature could be wrong.
     * Yourself excluded from that match: every chat you're in contains you, so including
     * you would make your own name return your entire chat list.
     *
     * @return Builder<Conversation>
     */
    private function conversations(User $user, string $term): Builder
    {
        return Conversation::query()
            ->whereIn('id', $user->conversations()->select('conversations.id'))
            ->where(function (Builder $q) use ($user, $term) {
                $q->where(fn (Builder $named) => $this->driver->matchName($named, 'name', $term))
                    ->orWhereHas('members', function ($member) use ($user, $term) {
                        $member->whereKeyNot($user->getKey());
                        $this->driver->matchName($member, 'users.name', $term);
                    });
            })
            ->with(['members', 'channel:id,conversation_id'])
            ->orderByDesc('updated_at');
    }

    /**
     * Servers — the one search that can return somewhere you aren't.
     *
     * Yours by name, plus an *exact* invite-code match. The asymmetry is the privacy rule:
     * a server you're in is yours to find by any fragment of its name, and a server you're
     * not in is only findable by someone who was already given the code — which is exactly
     * what a code is for. Substring-matching every server in the database would turn this
     * box into a directory of every private group in the app, discoverable by guessing.
     *
     * @return Builder<Server>
     */
    private function servers(User $user, string $term): Builder
    {
        return Server::query()
            ->where(function (Builder $q) use ($user, $term) {
                $q->where(function (Builder $mine) use ($user, $term) {
                    $mine->whereIn('id', $user->servers()->select('servers.id'));
                    $this->driver->matchName($mine, 'name', $term);
                })->orWhere('invite_code', $term);
            })
            ->withCount('members')
            ->orderBy('name');
    }

    /**
     * Restrict to one place, when the caller named one.
     *
     * Channel beats conversation beats server, narrowest first — and each is intersected
     * with what's already visible rather than replacing it, so naming a channel you can't
     * read returns nothing instead of returning its contents.
     *
     * @param  Builder<Message>  $query
     */
    private function applyScope(Builder $query, SearchFilters $filters, string $channelColumn): void
    {
        if ($filters->channelId !== null) {
            $query->where($channelColumn, $filters->channelId);

            return;
        }

        if ($filters->conversationId !== null) {
            $query->whereIn($channelColumn, Channel::where('conversation_id', $filters->conversationId)->select('id'));

            return;
        }

        if ($filters->serverId !== null) {
            $query->whereIn($channelColumn, Channel::where('server_id', $filters->serverId)->select('id'));
        }
    }
}
