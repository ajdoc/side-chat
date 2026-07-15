<?php

namespace App\Actions\Conversation;

use App\Actions\Message\PostSystemMessageAction;
use App\Actions\Voice\LeaveVoiceChannelAction;
use App\Events\ConversationRemoved;
use App\Events\ConversationUpdated;
use App\Models\ChannelRead;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeaveConversationAction
{
    public function __construct(
        private readonly PostSystemMessageAction $system,
        private readonly LeaveVoiceChannelAction $leaveCall,
    ) {}

    /**
     * Walk out of a group chat.
     *
     * You can't leave a DM. There is nothing to leave *to*: a one-person DM is not a thing
     * the dm_key can describe, and the other person would be left typing into a room that
     * silently stopped delivering. Deleting a DM is a different feature (hiding it from
     * your own list) and deliberately isn't this one.
     *
     * Same shape as leaving a server: the messages stay — leaving a conversation has never
     * meant unsaying what you said — but the two claims about the *present* go, because
     * they'd otherwise outlive the membership that justified them: your seat in the call,
     * and your read marker (which would keep drawing your avatar in a seen-by row you can
     * no longer see).
     */
    public function handle(Conversation $conversation, User $user): void
    {
        if ($conversation->isDm()) {
            throw ValidationException::withMessages([
                'conversation' => 'You can’t leave a direct message.',
            ]);
        }

        $conversation->loadMissing('channel');
        $channel = $conversation->channel;

        // Before the membership goes — the broadcast it triggers is gated on being a member.
        $this->leaveCall->handle($channel, $user);

        DB::transaction(function () use ($conversation, $channel, $user): void {
            ChannelRead::where('channel_id', $channel->id)->where('user_id', $user->id)->delete();
            $conversation->members()->detach($user->id);

            // A group whose owner walked out still belongs to the people in it. Hand it to
            // whoever has been there longest rather than leaving it un-renameable forever.
            if ($conversation->owner_id === $user->id) {
                $conversation->update([
                    'owner_id' => $conversation->members()->orderBy('conversation_user.created_at')->value('users.id'),
                ]);
            }
        });

        $this->system->handle($channel, $user, sprintf('%s left the group.', $user->name));

        $conversation->load('members');

        broadcast(new ConversationUpdated($conversation));
        // They're no longer a member, so the conversation stream can't reach them: the one
        // person who most needs to know their sidebar row is gone is told personally.
        broadcast(new ConversationRemoved($conversation->id, $user->id));
    }
}
