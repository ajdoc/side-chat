<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Reaction;

final class ReactionService
{
    /**
     * Group a message's reactions into the per-emoji summary the UI renders.
     *
     * Deliberately viewer-agnostic: it ships the reacting users rather than an
     * "isMine" flag, because the same payload is broadcast to every subscriber —
     * one flag can't be right for all of them. The client compares the ids to the
     * logged-in user, and uses the names for the tooltip.
     *
     * @return array<int, array{emoji: string, count: int, users: array<int, array{id: int, name: string}>}>
     */
    public function summarize(Message $message): array
    {
        $message->loadMissing('reactions.user');

        return $message->reactions
            ->groupBy('emoji')
            ->map(fn ($group, string $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'users' => $group
                    ->map(fn (Reaction $r) => ['id' => $r->user_id, 'name' => $r->user?->name ?? 'unknown'])
                    ->values()
                    ->all(),
            ])
            // Most-reacted first, ties broken by whichever emoji landed first.
            ->sortByDesc(fn (array $summary) => $summary['count'])
            ->values()
            ->all();
    }
}
