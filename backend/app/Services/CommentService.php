<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

final class CommentService
{
    /**
     * Group a thing's comments into the "popular comments" summary the UI renders as
     * chips: `✓ Looks good (18)`.
     *
     * Takes any model with a `comments` relation: a side chat *post* carries them too (see
     * SideChatComment), the rows have the same shape, and the chips are the same component.
     * One summariser keeps the grouping, ordering and tie-breaking identical for both.
     *
     * Grouped by the normalized body (+emoji) so wording that only differs in case or
     * spacing counts as the same phrase. Like ReactionService, it's viewer-agnostic — it
     * ships the ids of who left each phrase rather than an "is this mine" flag, because
     * the same payload is broadcast to everyone. The client works out which chips are its
     * own to light them up and to make a click a toggle.
     *
     * @return array<int, array{key: string, body: string, emoji: ?string, count: int, users: array<int, array{id: int, name: string}>}>
     */
    public function summarize(Model $subject): array
    {
        $subject->loadMissing('comments.user');

        return $subject->comments
            // A phrase is (normalized body + emoji): "Looks good" 👍 and "Looks good" with
            // no emoji are two different chips, as they read differently.
            ->groupBy(fn (Model $c) => $c->body_key.'|'.($c->emoji ?? ''))
            ->map(function ($group) {
                /** @var Model $first */
                $first = $group->first();

                return [
                    'key' => $first->body_key.'|'.($first->emoji ?? ''),
                    // The earliest wording wins as the label — original casing, one canonical form.
                    'body' => $group->sortBy('id')->first()->body,
                    'emoji' => $first->emoji,
                    'count' => $group->count(),
                    'users' => $group
                        ->map(fn (Model $c) => ['id' => $c->user_id, 'name' => $c->user?->name ?? 'unknown'])
                        ->values()
                        ->all(),
                ];
            })
            // Most co-signed first, ties broken by whichever phrase landed first.
            ->sortByDesc(fn (array $summary) => $summary['count'])
            ->values()
            ->all();
    }
}
