<?php

namespace App\Http\Resources;

use App\Models\AppPoll;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One poll, as both the wall card and the detail view draw it.
 *
 * Results are counted here rather than sent as raw votes. A poll's votes are the one thing a
 * client must never be handed wholesale: on an anonymous poll that would be the answer to the
 * question the anonymity was for. So the wire carries per-option *counts*, plus the viewer's
 * own picks — which is everything either view needs and nothing more.
 *
 * @mixin AppPoll
 */
class AppPollResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $votes = $this->whenLoaded('votes');
        $counted = $this->resource->relationLoaded('votes');

        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'type' => $this->type,
            'question' => $this->question,
            'description' => $this->description,
            'anonymous' => (bool) $this->anonymous,
            'closed' => ! $this->isOpen(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'creator' => new UserResource($this->whenLoaded('creator')),

            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'id' => $o->id,
                'label' => $o->label,
                'votes' => $counted ? $votes->where('option_id', $o->id)->count() : 0,
            ])->all()),

            // People, not rows: a multiple-choice poll gets several rows from one person, and
            // "27 votes" from 12 people is a number nobody can interpret. See AppPoll.
            'voter_count' => $this->when($counted, fn () => $votes->unique('user_id')->count()),
            'vote_count' => $this->when($counted, fn () => $votes->count()),

            // Which options *you* picked, so the client can render your answer without being
            // handed everybody else's.
            'my_option_ids' => $this->when(
                $counted && $viewer !== null,
                fn () => $votes->where('user_id', $viewer->id)->pluck('option_id')->values(),
            ),

            'reactions' => $this->when(
                $this->resource->relationLoaded('reactions'),
                fn () => $this->resource->reactionSummary($viewer),
            ),
            'tags' => AppTagResource::collection($this->whenLoaded('tags')),
            // Absent on the wall listing (which only counts them) and present on the detail.
            'comments' => AppCommentResource::collection($this->whenLoaded('comments')),
            'comment_count' => $this->whenNotNull($this->comments_count),
            'created_at' => $this->created_at,
        ];
    }
}
