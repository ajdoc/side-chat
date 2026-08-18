<?php

namespace App\Http\Resources;

use App\Services\CommentService;
use App\Support\Apps\AppSubjects;
use App\Services\ReactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A side chat as a living object — the card in the main timeline and the panel header.
 *
 * `participant_ids` ships instead of a per-viewer `joined` flag for the same reason
 * reactions ship their reactors: this resource is broadcast to everyone (SideChatCreated),
 * and one baked-in flag can't be right for all of them. The client compares the ids to the
 * logged-in user to decide whether to show [Join] or [Open].
 */
class SideChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'message_id' => $this->message_id,
            'name' => $this->name,
            // The forum layer. Tags are plain lowercase strings (see UpdateSideChatAction);
            // null in the column means "never tagged", which the list reads as none.
            'tags' => $this->tags ?? [],
            // Which group heading this post files under. Null is "Uncategorised" — the
            // bucket the list synthesises rather than a row that could go missing.
            'side_chat_forum_id' => $this->side_chat_forum_id,
            'reactions' => $this->whenLoaded('reactions', fn () => app(ReactionService::class)->summarize($this->resource)),
            // "Popular comments" on the post — the same chips a message carries, and the
            // same summariser, so a phrase groups identically wherever it was left.
            'comments' => $this->whenLoaded('comments', fn () => app(CommentService::class)->summarize($this->resource)),
            // May the asker retitle/retag/delete? Mirrors ManageSideChatRequest.
            'can_manage' => $this->when($request->user() !== null, fn () => $this->resource->canManage($request->user())),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'parent_message' => new MessageResource($this->whenLoaded('parentMessage')),
            /*
             * What this room is about, when it was opened from an app item.
             *
             * The live item rather than a snapshot: a card gets renamed, and a header still
             * showing what it used to be called is exactly the confusion this is here to
             * remove. `parent_message` already carries the anchor; this says which *thing*.
             */
            'about' => $this->whenLoaded('appDiscussion', function () {
                $discussion = $this->appDiscussion;
                $subject = $discussion?->subject;

                return $subject === null ? null : [
                    'type' => $subject->getMorphClass(),
                    'label' => AppSubjects::kindLabel($subject->getMorphClass()),
                    'app' => AppSubjects::appFor($subject->getMorphClass()),
                    'title' => AppSubjects::label($subject)['title'],
                    'channel_id' => $discussion->channel_id,
                ];
            }),
            // Frozen snapshot of the origin message, so "Started from" outlives its deletion.
            'origin_author' => $this->origin_author,
            'origin_excerpt' => $this->origin_excerpt,
            // The roster, when loaded — for the card's avatar stack and the panel's member list.
            'participants' => UserResource::collection($this->whenLoaded('participants')),
            'participant_ids' => $this->whenLoaded('participants', fn () => $this->participants->pluck('id')),
            // The living-object counters: 👥 💬 📌 ✅
            'participants_count' => $this->whenCounted('participants'),
            'messages_count' => $this->whenCounted('messages'),
            'threads_count' => $this->whenCounted('threads'),
            'pinned_count' => $this->when(isset($this->pinned_count), fn () => (int) $this->pinned_count),
            'decisions_count' => $this->when(isset($this->decisions_count), fn () => (int) $this->decisions_count),
            // "Last active 5m ago" — the newest message's timestamp, or the side chat's own
            // creation time when nobody's said anything yet.
            'last_active_at' => $this->messages_max_created_at ?? $this->created_at,
            'created_at' => $this->created_at,
        ];
    }
}
