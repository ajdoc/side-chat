<?php

namespace App\Services\Notifications;

use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\User;
use App\Support\Notifications\NotifyLevel;

/**
 * Reads and writes one person's "how loud is this place" preference.
 *
 * Kept apart from {@see NotificationPolicy}, which only ever *resolves* — the two halves
 * answer different questions ("what did they choose here" vs "what does that add up to")
 * and the second is asked in bulk on a hot path.
 */
final class NotificationSettingService
{
    /**
     * Set, change or clear the override for one channel.
     *
     * A null level clears the override rather than meaning "all": the difference is the
     * whole point of the column, since inheriting has to keep tracking the user's default
     * as it changes. Same for `muteMinutes` — null lifts a mute, 0 is not a thing.
     */
    public function set(Channel $channel, User $user, ?NotifyLevel $level, ?int $muteMinutes, bool $touchLevel = true, bool $touchMute = true): ChannelRead
    {
        $read = ChannelRead::firstOrNew([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
        ]);

        if ($touchLevel) {
            $read->notify_level = $level?->value;
        }

        if ($touchMute) {
            $read->muted_until = $muteMinutes === null ? null : now()->addMinutes($muteMinutes);
        }

        $read->save();

        return $read;
    }

    /**
     * This person's settings for one channel, as the client needs to draw the picker.
     *
     * Both the raw choice and what it resolves to: the menu has to show "Use default" as
     * selected *and* say what the default currently is, which is two different facts.
     *
     * @return array<string, mixed>
     */
    public function show(Channel $channel, User $user): array
    {
        $read = ChannelRead::where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->first();

        return [
            'channel_id' => $channel->id,
            'notify_level' => $read?->notify_level,
            'muted_until' => $read?->muted_until,
            'effective_level' => app(NotificationPolicy::class)->levelFor($channel, $user)->value,
        ];
    }
}
