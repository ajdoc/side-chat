<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

final class ReactionService
{
    /**
     * Group a thing's reactions into the per-emoji summary the UI renders.
     *
     * Takes any model with a `reactions` relation rather than a Message specifically: a
     * side chat *post* is reacted to as well (see SideChatReaction), the rows have the
     * same shape, and the chips that render them are literally the same component. One
     * summariser keeps ordering and tie-breaking identical for both.
     *
     * Deliberately viewer-agnostic: it ships the reacting users rather than an
     * "isMine" flag, because the same payload is broadcast to every subscriber —
     * one flag can't be right for all of them. The client compares the ids to the
     * logged-in user, and uses the names for the tooltip.
     *
     * @return array<int, array{emoji: string, count: int, users: array<int, array{id: int, name: string}>}>
     */
    public function summarize(Model $subject): array
    {
        $subject->loadMissing('reactions.user');

        return $subject->reactions
            ->groupBy('emoji')
            ->map(fn ($group, string $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'users' => $group
                    ->map(fn (Model $r) => ['id' => $r->user_id, 'name' => $r->user?->name ?? 'unknown'])
                    ->values()
                    ->all(),
            ])
            // Most-reacted first, ties broken by whichever emoji landed first.
            ->sortByDesc(fn (array $summary) => $summary['count'])
            ->values()
            ->all();
    }
}
