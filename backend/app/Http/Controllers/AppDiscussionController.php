<?php

namespace App\Http\Controllers;

use App\Actions\Message\PostSystemMessageAction;
use App\Actions\SideChat\CreateSideChatAction;
use App\DTOs\SideChat\CreateSideChatData;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Models\AppDiscussion;
use App\Models\Channel;
use App\Support\Apps\AppSubjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * "Talk about this in chat" — the return trip for "Add this message to an app".
 *
 * ## What it makes
 *
 * A **side chat**, anchored to a message in the channel's timeline that says what it's about.
 * Both halves matter:
 *
 * - a side chat is the room this product already has for working something out (participants,
 *   decisions, its own desk), where a thread is a tangent off one message;
 * - the anchor message is how anyone scrolling the channel finds out the conversation exists.
 *   A side chat with no origin message is invisible to everyone who wasn't told about it, which
 *   would leave this feature reachable only from the item it started at — half a connection.
 *
 * ## Once
 *
 * The second person to press the button joins the first one's room. That's the unique index on
 * `app_discussions`, and it's the whole point: two rooms about one task is the split this exists
 * to prevent. So `store` is idempotent, and the client can treat "start" and "open" as one
 * button whose label depends on whether a row is already there.
 *
 * Authorisation is the shared app-item rule: `TrackerRequest` establishes the caller is in this
 * channel, and {@see AppSubjects} establishes the item is too.
 */
class AppDiscussionController extends Controller
{
    /** The discussion for an item, or null. Fetched with the item's comments and chips. */
    public function show(TrackerRequest $request, Channel $channel, string $type, int $id): JsonResponse
    {
        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        $discussion = AppDiscussion::with('sideChat')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        return response()->json(['data' => $discussion === null ? null : $this->payload($discussion, $channel)]);
    }

    public function store(
        TrackerRequest $request,
        Channel $channel,
        string $type,
        int $id,
        CreateSideChatAction $sideChats,
        PostSystemMessageAction $system,
    ): JsonResponse {
        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        $existing = AppDiscussion::with('sideChat')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        // Already open: hand back the room rather than making a second one.
        if ($existing !== null) {
            return response()->json(['data' => $this->payload($existing, $channel)]);
        }

        $label = AppSubjects::label($subject);
        $kind = mb_strtolower(AppSubjects::kindLabel($subject->getMorphClass()));
        $user = $request->user();

        $discussion = DB::transaction(function () use ($subject, $channel, $user, $label, $kind, $sideChats, $system) {
            // The anchor. A `system` message because nobody said it — but authored by whoever
            // pressed the button, so the timeline can show who started this.
            // Names the *kind* as well as the words. "Started a discussion about “ship it”"
            // tells a passer-by nothing about where "ship it" lives; "about the kanban card"
            // does, and this line is the only thing most of the channel will ever see of it.
            $anchor = $system->handle($channel, $user, "💬 Started a side chat about the {$kind} “{$label['title']}”.");

            $sideChat = $sideChats->handle($channel, $user, new CreateSideChatData([
                'name' => $label['title'],
                'message_id' => $anchor->id,
            ]));

            // The item's own words as the side chat's origin excerpt, so the room's header says
            // what it's about without anybody having to retype it.
            if ($label['excerpt'] !== null) {
                $sideChat->update(['origin_excerpt' => $label['excerpt']]);
            }

            return AppDiscussion::create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'channel_id' => $channel->getKey(),
                'side_chat_id' => $sideChat->id,
                'created_by' => $user->getKey(),
            ]);
        });

        // History on the item, so its activity feed says a conversation was opened about it.
        if (method_exists($subject, 'recordActivity')) {
            $subject->recordActivity('discussion_started', $user, ['side_chat_id' => $discussion->side_chat_id]);
        }

        return response()->json(['data' => $this->payload($discussion->load('sideChat'), $channel)], 201);
    }

    /**
     * Enough for the client to draw a link and follow it.
     *
     * The routing *ids* rather than a URL: a path is the client's to build (it already does, in
     * SearchPanel), and a server that emitted `/servers/3/channels/12?sidechat=4` would be a
     * second place the frontend's routes are written down.
     *
     * @return array<string, mixed>
     */
    private function payload(AppDiscussion $discussion, Channel $channel): array
    {
        return [
            'side_chat_id' => $discussion->side_chat_id,
            'name' => $discussion->sideChat?->name,
            'channel_id' => $channel->id,
            'server_id' => $channel->server_id,
            'conversation_id' => $channel->conversation_id,
        ];
    }
}
