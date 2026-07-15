<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

final class ReadReceiptService
{
    /**
     * Move a user's read marker in a channel.
     *
     * Only ever forwards: clients mark as they scroll, and those calls arrive out of
     * order often enough that honouring a lower id would make the marker jitter
     * backwards — and take everyone's seen-by avatars with it.
     */
    public function markRead(Channel $channel, User $user, ?int $messageId = null): ?ChannelRead
    {
        $messageId ??= $channel->messages()->whereNull('thread_id')->max('id');

        if ($messageId === null) {
            return null; // nothing in the channel to have read
        }

        // A message from another channel would let someone drag their marker to an
        // arbitrary id and mark everything read.
        $belongs = Message::where('id', $messageId)
            ->where('channel_id', $channel->id)
            ->exists();

        if (! $belongs) {
            return null;
        }

        $read = ChannelRead::firstOrNew([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
        ]);

        if ($read->exists && (int) $read->last_read_message_id >= $messageId) {
            return $read;
        }

        $read->last_read_message_id = $messageId;
        $read->read_at = now();
        $read->save();

        return $read;
    }

    /**
     * Everyone's read marker in a channel — this is what draws the seen-by avatars.
     *
     * @return Collection<int, ChannelRead>
     */
    public function forChannel(Channel $channel): Collection
    {
        return $channel->reads()
            ->whereNotNull('last_read_message_id')
            ->with('user')
            ->get();
    }

    /**
     * Who has read *past* a specific message — the "Seen by" list on the info panel.
     *
     * Note the `>=`: the avatars in the timeline sit on the message a marker rests on,
     * but "did Bob see message 40" is true for any marker at 40 or beyond.
     *
     * @return Collection<int, ChannelRead>  keyed by user id
     */
    public function seenBy(Message $message): Collection
    {
        return ChannelRead::query()
            ->where('channel_id', $message->channel_id)
            ->where('last_read_message_id', '>=', $message->id)
            ->with('user')
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Unread count per channel for one user: main-timeline messages, by somebody else,
     * newer than where they left off. Channels with nothing unread are simply absent.
     *
     * @param  array<int, int>  $channelIds
     * @return Collection<int, int>  channel_id => count
     */
    public function unreadCounts(User $user, array $channelIds): Collection
    {
        if ($channelIds === []) {
            return collect();
        }

        return Message::query()
            ->leftJoin('channel_reads', function ($join) use ($user) {
                $join->on('channel_reads.channel_id', '=', 'messages.channel_id')
                    ->where('channel_reads.user_id', '=', $user->id);
            })
            ->whereIn('messages.channel_id', $channelIds)
            ->whereNull('messages.thread_id') // thread replies don't light up the channel
            ->where('messages.user_id', '!=', $user->id)
            // Never read this channel at all → coalesce to 0 → everything counts.
            ->whereRaw('messages.id > coalesce(channel_reads.last_read_message_id, 0)')
            ->groupBy('messages.channel_id')
            ->selectRaw('messages.channel_id, count(*) as unread')
            ->pluck('unread', 'channel_id');
    }
}
