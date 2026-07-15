<?php

namespace App\Actions\Server;

use App\Events\MemberLeft;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\Server;
use App\Models\User;
use App\Models\VoiceParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeaveServerAction
{
    /**
     * Removes a member from a server.
     *
     * The owner may not leave. A server whose owner has walked out has nobody who can
     * delete it or admit anyone to it, so rather than repair that state later we refuse to
     * enter it: the owner's exit is "delete the server", which is a different button with
     * a different confirmation.
     *
     * Their messages stay. Leaving a conversation has never meant unsaying what you said,
     * and a thread full of holes is worse for the people still in it than one with a name
     * they no longer recognise.
     *
     * Two things do have to go, because neither is a fact about the past — both are claims
     * about the *present*, and they'd otherwise outlive the membership that justified them:
     *
     *  - their voice seat, or the sidebar shows a ghost sitting in a call they've left;
     *  - their read markers, or ReadReceiptService::forChannel() keeps drawing their avatar
     *    in the seen-by row of a channel they can no longer read.
     */
    public function handle(Server $server, User $user): void
    {
        if ($server->owner_id === $user->id) {
            throw ValidationException::withMessages([
                'server' => 'You own this server — delete it instead of leaving.',
            ]);
        }

        $channelIds = $server->channels()->pluck('id')->all();

        // The voice channels they were actually sitting in, captured before the delete so
        // we know whose roster changed and therefore who needs telling.
        $vacated = VoiceParticipant::query()
            ->where('user_id', $user->id)
            ->whereIn('channel_id', $channelIds)
            ->pluck('channel_id')
            ->all();

        DB::transaction(function () use ($server, $user, $channelIds): void {
            VoiceParticipant::where('user_id', $user->id)->whereIn('channel_id', $channelIds)->delete();
            ChannelRead::where('user_id', $user->id)->whereIn('channel_id', $channelIds)->delete();

            $server->members()->detach($user->id);
            // Belt and braces: a member has no pending request, but leaving must not
            // resurrect a stale one if they ever ask to come back.
            $server->joinRequests()->where('user_id', $user->id)->delete();
        });

        foreach (Channel::whereIn('id', $vacated)->get() as $channel) {
            broadcast(new VoiceStateUpdated($channel));
        }

        broadcast(new MemberLeft($server->id, $user->id));
    }
}
