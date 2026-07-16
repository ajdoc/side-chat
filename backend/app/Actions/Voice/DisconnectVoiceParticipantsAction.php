<?php

namespace App\Actions\Voice;

use App\Events\VoiceParticipantDisconnected;
use App\Models\Channel;
use App\Models\User;
use App\Models\VoiceParticipant;
use Illuminate\Support\Collection;

final class DisconnectVoiceParticipantsAction
{
    public function __construct(private readonly LeaveVoiceChannelAction $leave) {}

    /**
     * Turf people out of a call. With a target, that one person; without, everyone in the
     * room but the moderator doing it — who keeps their seat, because leaving is its own
     * separate button.
     *
     * Each removal reuses the ordinary leave path, so a chat's call still ends and writes
     * its "Call ended · 4m 12s" line the instant the room empties, exactly as if the person
     * had left of their own accord — there's no second way to end a call to keep in sync.
     *
     * The one thing a *forced* leave needs that a voluntary one doesn't is telling the
     * person: their browser is still holding a live mesh open, and the presence channel can
     * announce that a seat emptied but not that it was theirs to drop. So we say so, on
     * their personal stream, and their client hangs up.
     *
     * @return int how many people were disconnected
     */
    public function handle(Channel $channel, User $actor, ?int $targetUserId = null): int
    {
        $targets = $this->targets($channel, $actor, $targetUserId);

        foreach ($targets as $target) {
            $this->leave->handle($channel, $target);
            broadcast(new VoiceParticipantDisconnected($channel, $target));
        }

        return $targets->count();
    }

    /**
     * @return Collection<int, User>
     */
    private function targets(Channel $channel, User $actor, ?int $targetUserId): Collection
    {
        $query = VoiceParticipant::query()
            ->with('user')
            ->where('channel_id', $channel->id)
            ->alive();

        if ($targetUserId !== null) {
            $query->where('user_id', $targetUserId);
        } else {
            // "Disconnect everyone" clears the room but leaves you standing in it.
            $query->where('user_id', '!=', $actor->id);
        }

        return $query->get()
            ->map(fn (VoiceParticipant $participant) => $participant->user)
            ->filter()
            ->values();
    }
}
