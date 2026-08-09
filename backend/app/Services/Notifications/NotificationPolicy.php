<?php

namespace App\Services\Notifications;

use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\User;
use App\Support\Notifications\NotifyLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Should this person be told about this message?"
 *
 * One answer, asked by every delivery route there is — push, and anything that follows it.
 * Keeping it in one place is the point: a setting that silences the phone but not the
 * desktop is a bug report, and the only way to be sure they agree is for them to be the
 * same function.
 *
 * The resolution order, most specific first:
 *
 *   1. `muted_until` on this channel, still in the future — silence, whatever else says.
 *   2. `notify_level` on this channel.
 *   3. The parent channel's mute, then its level, if this is a discussion. A discussion
 *      inherits the channel it lives in, so muting a channel mutes the conversations
 *      inside it without having to walk them.
 *   4. The user's default for this *kind* of room — DM/group, or server channel.
 *
 * Resolution is deliberately bulk: a message goes to everyone in the room at once, so the
 * question is really "which of these two hundred people", and asking it one user at a time
 * would be two hundred queries per message sent.
 */
final class NotificationPolicy
{
    /**
     * Which of these users wants to hear about this message.
     *
     * @param  array<int, int>  $userIds  Candidate recipients — the author already removed.
     * @param  array<int, int>  $mentionedUserIds  Who the message named.
     * @return array<int, int> The subset to notify.
     */
    public function recipients(Channel $channel, array $userIds, array $mentionedUserIds = [], bool $mentionsAll = false): array
    {
        if ($userIds === []) {
            return [];
        }

        $levels = $this->levelsFor($channel, $userIds);
        $mentioned = array_flip($mentionedUserIds);

        return array_values(array_filter(
            $userIds,
            fn (int $id) => ($levels[$id] ?? NotifyLevel::None)
                ->admits($mentionsAll || isset($mentioned[$id])),
        ));
    }

    /**
     * The effective level for each user in one channel.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, NotifyLevel>
     */
    public function levelsFor(Channel $channel, array $userIds): array
    {
        $channel->loadMissing('parent');

        // Both rows in one read — the channel's own, and its parent's if it has one. A
        // discussion asks two questions of this table and there is no reason to pay twice.
        $ids = array_filter([$channel->id, $channel->parent_id]);

        /** @var Collection<int, ChannelRead> $reads */
        $reads = ChannelRead::query()
            ->whereIn('channel_id', $ids)
            ->whereIn('user_id', $userIds)
            ->get(['channel_id', 'user_id', 'notify_level', 'muted_until']);

        $own = $reads->where('channel_id', $channel->id)->keyBy('user_id');
        $parent = $channel->parent_id === null
            ? collect()
            : $reads->where('channel_id', $channel->parent_id)->keyBy('user_id');

        $defaults = $this->defaultsFor($channel, $userIds);
        $now = Carbon::now();

        $out = [];

        foreach ($userIds as $id) {
            $out[$id] = $this->resolve(
                $own->get($id),
                $parent->get($id),
                $defaults[$id] ?? NotifyLevel::None,
                $now,
            );
        }

        return $out;
    }

    /** The effective level for one user — the single-user shape of {@see levelsFor}. */
    public function levelFor(Channel $channel, User $user): NotifyLevel
    {
        return $this->levelsFor($channel, [$user->id])[$user->id] ?? NotifyLevel::None;
    }

    /**
     * Walk the override chain. Mute is checked before level at each step, so an hour of
     * quiet on a channel silences its discussions too without touching their settings.
     */
    private function resolve(?ChannelRead $own, ?ChannelRead $parent, NotifyLevel $default, Carbon $now): NotifyLevel
    {
        foreach ([$own, $parent] as $read) {
            if ($read === null) {
                continue;
            }

            if ($read->muted_until !== null && $read->muted_until->greaterThan($now)) {
                return NotifyLevel::None;
            }

            if (($level = NotifyLevel::parse($read->notify_level)) !== null) {
                return $level;
            }
        }

        return $default;
    }

    /**
     * Each user's default for this kind of room.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, NotifyLevel>
     */
    private function defaultsFor(Channel $channel, array $userIds): array
    {
        $column = $channel->conversation_id === null ? 'notify_channel_default' : 'notify_dm_default';

        return User::query()
            ->whereIn('id', $userIds)
            ->pluck($column, 'id')
            ->map(fn (?string $v) => NotifyLevel::parse($v) ?? NotifyLevel::Mentions)
            ->all();
    }
}
