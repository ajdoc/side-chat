<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\SideChat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class SideChatService
{
    /**
     * The counts every card carries — kept in one place so the list query, the model
     * reload, and the broadcast events all measure them identically.
     *
     * The pinned/decision counts reuse the Message scopes, so there's a single definition
     * of what "pinned" and "a decision" mean.
     *
     * @return array<int|string, mixed>
     */
    private function countDefinitions(): array
    {
        return [
            'participants',
            'messages',
            'messages as pinned_count' => fn (Builder $q) => $q->pinned(),
            'messages as decisions_count' => fn (Builder $q) => $q->decided(),
        ];
    }

    /**
     * Attach the living-object card data to a side-chat query:
     *
     *   👥 participants   💬 messages   📌 pinned   ✅ decisions   · last active
     *
     * `messages_max_created_at` (from withMax) is the "last active". Shared so the list
     * query and the `startedSideChat` eager-load on a message render identical cards.
     *
     * Untyped param on purpose: it's handed either an Eloquent Builder (the list query) or
     * a Relation (an eager-load closure) — both understand with/withCount/withMax.
     *
     * @param  Builder<SideChat>|\Illuminate\Database\Eloquent\Relations\Relation<SideChat>  $query
     * @return mixed
     */
    public function applyCardData($query)
    {
        return $query
            ->with(['creator', 'parentMessage.user', 'participants:id,name,avatar'])
            ->withCount($this->countDefinitions())
            ->withMax('messages', 'created_at');
    }

    /**
     * The channel's side chats, newest first, each carrying its living-object card data.
     *
     * @return Collection<int, SideChat>
     */
    public function forChannel(Channel $channel): Collection
    {
        return $this->applyCardData($channel->sideChats()->getQuery())->get();
    }

    /**
     * The side chat's standing highlights: its recorded decisions and its pinned messages,
     * newest first. These can be older than the loaded message window, so the panel fetches
     * them on their own rather than filtering what it happens to have on screen.
     *
     * @return array{decisions: Collection<int, \App\Models\Message>, pinned: Collection<int, \App\Models\Message>}
     */
    public function highlights(SideChat $sideChat): array
    {
        $with = ['user', 'replyTo.user', 'attachments', 'reactions.user', 'comments.user', 'linkPreviews'];

        return [
            'decisions' => $sideChat->messages()->decided()->with($with)->orderByDesc('decided_at')->get(),
            'pinned' => $sideChat->messages()->pinned()->with($with)->orderByDesc('pinned_at')->get(),
        ];
    }

    /** Reload one side chat with everything the card and the panel header need. */
    public function loadForDisplay(SideChat $sideChat): SideChat
    {
        return $sideChat
            ->load(['creator', 'parentMessage.user', 'participants:id,name,avatar'])
            ->loadCount($this->countDefinitions())
            ->loadMax('messages', 'created_at');
    }
}
