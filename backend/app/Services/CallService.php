<?php

namespace App\Services;

use App\Actions\Message\PostSystemMessageAction;
use App\Events\CallDeclined;
use App\Events\CallEnded;
use App\Events\CallStarted;
use App\Events\ConversationUpdated;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A call in a DM or group chat: the part a server's voice channel doesn't have.
 *
 * A voice channel is a *place*. It sits in the sidebar whether or not anyone is in it,
 * you walk in when you feel like it, and nobody is interrupted when you do. All the
 * machinery for that already exists (VoiceService, VoiceParticipant, the WebRTC mesh) and
 * a chat's call reuses every bit of it — the channel a conversation owns is joined exactly
 * like a voice channel is.
 *
 * What a chat adds is a *lifecycle*, and it hangs entirely off one question the voice
 * channel never has to ask: is the room empty?
 *
 *   first person in  → the call begins, and everyone else's phone rings
 *   second person in → somebody picked up; it's a conversation now, not a ring
 *   last person out  → the call is over, and it goes in the transcript as either
 *                      "Call ended · 4m 12s" or "Missed call"
 *
 * That's why these hang off join/leave rather than living behind their own start/end
 * endpoints: the truth about whether a call is happening is *who is in it*, and inventing
 * a second source for it would just be something to drift out of sync.
 */
final class CallService
{
    public function __construct(
        private readonly VoiceService $voice,
        private readonly PostSystemMessageAction $system,
    ) {}

    /**
     * Somebody took a seat. Decide whether that started a call, answered one, or neither.
     *
     * `$othersBefore` is the size of the room *before* they arrived, counted by the caller
     * — after the insert there is no way left to ask.
     */
    public function afterJoin(Channel $channel, User $user, int $othersBefore): void
    {
        if (! $channel->isDirect()) {
            return; // a server's voice channel has no lifecycle: the room is always there
        }

        $conversation = $channel->conversation;

        if (! $conversation->hasActiveCall()) {
            $conversation->update([
                'call_started_at' => now(),
                'call_answered_at' => null,
                'call_started_by' => $user->id,
            ]);

            broadcast(new CallStarted($conversation, $user));
            broadcast(new ConversationUpdated($conversation));

            return;
        }

        // Joining a call already in progress. The first person to do so is the one who
        // turns a ring into a conversation — after that there's nothing left to answer.
        if ($othersBefore > 0 && $conversation->call_answered_at === null) {
            $conversation->update(['call_answered_at' => now()]);

            broadcast(new ConversationUpdated($conversation));
        }
    }

    /**
     * Somebody gave up their seat. If they were the last one, the call is over.
     *
     * `$remaining` is what's left in the room after they've gone.
     */
    public function afterLeave(Channel $channel, User $leaver, int $remaining): void
    {
        if (! $channel->isDirect() || $remaining > 0) {
            return;
        }

        $conversation = $channel->conversation;

        if (! $conversation->hasActiveCall()) {
            return; // already ended — a leaving browser fires this more than once
        }

        $answered = $conversation->call_answered_at !== null;

        // Attributed to whoever rang: "Missed call" is a fact about *them* not getting
        // through, and a duration is a fact about the call they started. Falling back to
        // the last person out only matters if the caller's account is gone by now.
        $author = ($conversation->call_started_by
            ? User::find($conversation->call_started_by)
            : null) ?? $leaver;

        $this->system->handle(
            $channel,
            $author,
            $answered
                ? 'Call ended · '.$this->duration($conversation)
                : 'Missed call',
        );

        $conversation->update([
            'call_started_at' => null,
            'call_answered_at' => null,
            'call_started_by' => null,
        ]);

        broadcast(new CallEnded($conversation, $answered));
        broadcast(new ConversationUpdated($conversation));
    }

    /** "Not now." Stops the ringing, here and on the decliner's other tabs. */
    public function decline(Conversation $conversation, User $user): void
    {
        broadcast(new CallDeclined($conversation, $user));
    }

    /**
     * Who is in this chat's call right now. Empty when nobody is.
     *
     * @return Collection<int, \App\Models\VoiceParticipant>
     */
    public function participants(Conversation $conversation): Collection
    {
        return $this->voice->participants($conversation->loadMissing('channel')->channel);
    }

    /**
     * How long the call lasted, from the moment somebody picked up — not from the moment
     * it started ringing, which would quietly add the ring to every duration.
     */
    private function duration(Conversation $conversation): string
    {
        $seconds = max(0, (int) $conversation->call_answered_at->diffInSeconds(now(), absolute: true));

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes > 0
            ? sprintf('%dm %ds', $minutes, $rest)
            : sprintf('%ds', $rest);
    }
}
