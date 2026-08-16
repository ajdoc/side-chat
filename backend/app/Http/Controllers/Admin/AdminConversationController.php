<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminConversationResource;
use App\Models\Conversation;
use App\Services\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * DMs and group chats.
 *
 * The thinnest screen in the panel, on purpose. A private conversation between two people is
 * not a room an operator administers — there is no renaming somebody's DM, no adding
 * yourself to it, and no membership editing. What's left is what an operator genuinely needs:
 * see that a chat exists and who's in it, filter to the ones a reported account is part of,
 * and delete one outright when it has to go.
 *
 * Reading what was said is deliberately not here either. That's the audit endpoint, one step
 * further along, so that "list the chats" and "read this chat" are two different requests
 * and only the second one is worth logging.
 */
class AdminConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = Conversation::query()
            ->with(['owner:id,name,avatar', 'members:id,name,email,avatar', 'channel'])
            ->withCount('members')
            ->when(
                in_array($request->string('type')->value(), Conversation::TYPES, true),
                fn ($q) => $q->where('type', $request->string('type')->value()),
            )
            // "Which chats is this account in?" — the question a report actually arrives as.
            ->when(
                $request->integer('user_id'),
                fn ($q, int $id) => $q->whereHas('members', fn ($m) => $m->whereKey($id)),
            )
            ->when($request->string('q')->trim()->value(), function ($query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                // A group by its name, a DM by whoever is in it — a DM has no name of its own.
                $query->where(fn ($q) => $q->where('name', 'ilike', $like)
                    ->orWhereHas('members', fn ($m) => $m->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)));
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25) ?: 25, 100))
            ->withQueryString();

        // The message count hangs off the channel, not the conversation, so it can't ride in
        // the withCount() above. One query for the page rather than one per row.
        $this->attachMessageCounts($conversations->getCollection()->all());

        return AdminConversationResource::collection($conversations);
    }

    public function show(Conversation $conversation): AdminConversationResource
    {
        $conversation->load(['owner:id,name,avatar', 'members:id,name,email,avatar', 'channel'])
            ->loadCount('members');

        $this->attachMessageCounts([$conversation]);

        return new AdminConversationResource($conversation);
    }

    /**
     * Delete the chat and its history.
     *
     * The channel row cascades from the conversation, and everything else cascades from the
     * channel — but files don't cascade anywhere, so they're purged first while the rows
     * that point at them still exist.
     */
    public function destroy(Conversation $conversation, AttachmentService $attachments): Response
    {
        $channelId = $conversation->channel?->id;

        if ($channelId) {
            $attachments->purgeForChannels([$channelId]);
        }

        $conversation->delete();

        return response()->noContent();
    }

    /**
     * Fill in `channel.messages_count` for a page of conversations.
     *
     * @param  list<Conversation>  $conversations
     */
    private function attachMessageCounts(array $conversations): void
    {
        $channels = collect($conversations)->pluck('channel')->filter();

        if ($channels->isEmpty()) {
            return;
        }

        $counts = DB::table('messages')
            ->selectRaw('channel_id, count(*) as total')
            ->whereIn('channel_id', $channels->pluck('id'))
            ->groupBy('channel_id')
            ->pluck('total', 'channel_id');

        foreach ($channels as $channel) {
            $channel->messages_count = (int) ($counts[$channel->id] ?? 0);
        }
    }
}
