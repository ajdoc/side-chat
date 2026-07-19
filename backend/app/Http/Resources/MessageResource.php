<?php

namespace App\Http\Resources;

use App\Services\CommentService;
use App\Services\ReactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'thread_id' => $this->thread_id,
            'side_chat_id' => $this->side_chat_id,
            'body' => $this->body,
            'type' => $this->type,
            'edited' => $this->edited_at !== null,
            'pinned' => $this->pinned_at !== null,
            'pinned_at' => $this->pinned_at,
            // Marked as a recorded decision (side-chat messages only).
            'decided' => $this->decided_at !== null,
            // Only loaded where it's shown (the Pinned tab); absent on the timeline, which
            // renders a pin icon and nothing else.
            'pinned_by' => $this->whenLoaded('pinner', fn () => $this->pinner?->name),
            'user' => new UserResource($this->whenLoaded('user')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            // Grouped per emoji, carrying who reacted — the client works out which are its own.
            'reactions' => $this->whenLoaded('reactions', fn () => app(ReactionService::class)->summarize($this->resource)),
            // Aggregated "popular comments" chips, grouped by phrase. Same viewer-agnostic
            // shape as reactions: it ships who left each phrase, not an "is this mine" flag.
            'comments' => $this->whenLoaded('comments', fn () => app(CommentService::class)->summarize($this->resource)),
            // Only successful unfurls are worth rendering; pending/failed ones show nothing.
            'link_previews' => $this->whenLoaded(
                'linkPreviews',
                fn () => LinkPreviewResource::collection($this->linkPreviews->filter->isRenderable()->values())
            ),
            // The message this one replies to (compact reference).
            'reply_to' => $this->whenLoaded('replyTo', fn () => $this->replyTo
                ? [
                    'id' => $this->replyTo->id,
                    'body' => $this->replyTo->body,
                    'user_name' => $this->replyTo->user?->name,
                ]
                : null),
            // The original this message was forwarded from — just the author, enough for the
            // "Forwarded from X" line. Null once the original is deleted (nulled by the FK).
            'forwarded_from' => $this->whenLoaded('forwardedFrom', fn () => $this->forwardedFrom
                ? ['user_name' => $this->forwardedFrom->user?->name]
                : null),
            // Summary of a thread started from this message (channel timeline only).
            'started_thread' => $this->whenLoaded('startedThread', fn () => $this->startedThread
                ? [
                    'id' => $this->startedThread->id,
                    'name' => $this->startedThread->name,
                    'replies_count' => $this->startedThread->messages_count ?? 0,
                ]
                : null),
            // The living-object card for a side chat spun off this message (channel timeline
            // only). Full card resource so the timeline renders it without a follow-up fetch.
            'started_side_chat' => $this->whenLoaded('startedSideChat', fn () => $this->startedSideChat
                ? (new SideChatResource($this->startedSideChat))->resolve()
                : null),
            // The interactive widget this message renders (only on `type: widget` cards) —
            // the whole live state, so the card draws itself with no follow-up fetch.
            'widget' => $this->whenLoaded('widget', fn () => $this->widget
                ? (new WidgetResource($this->widget))->resolve()
                : null),
            'created_at' => $this->created_at,
        ];
    }
}
