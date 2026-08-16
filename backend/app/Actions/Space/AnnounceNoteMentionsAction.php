<?php

namespace App\Actions\Space;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Events\SideChatActivity;
use App\Jobs\SendPushNotifications;
use App\Models\Message;
use App\Models\SpaceNote;
use App\Models\User;
use App\Services\NicknameService;
use App\Services\Notifications\FcmSender;
use App\Support\MentionParser;

/**
 * Telling people they were named in a shared note.
 *
 * ## Why a message rather than something new
 *
 * A mention has to reach somebody who is *not* looking at the note — that's the entire point of
 * one — and everything that reaches a person who isn't looking already hangs off a message:
 * the sidebar badge (`ChannelActivity`), the mention highlight on it, the push
 * (`SendPushNotifications`), the phone's notification copy. Inventing a notification inbox for
 * one app would be a second delivery path to keep in step with the first, and the first is the
 * one people's mute settings are written against.
 *
 * So this posts one short system message into the surface's own timeline — "Ada mentioned @Bob
 * in the notes" — and rides the machinery that already exists. It lands in the room the note
 * belongs to, which is also the room where the follow-up conversation was going to happen.
 *
 * ## Only what's new, only once
 *
 * A note saves every ~700ms while somebody types. So the trigger is not "the body contains
 * @Bob", it's **"@Bob is in the body now and wasn't in the one this save replaced"** — which
 * makes an announcement happen exactly once per name added, no matter how many saves the
 * paragraph around it takes. Deleting a name and typing it again announces again; that is a
 * person being named a second time, and pretending otherwise would need a memory of every name
 * the note has ever held.
 *
 * The editor is never announced to themselves, and `@all` is honoured the same way a message's
 * is — once, when it first appears.
 */
final class AnnounceNoteMentionsAction
{
    public function __construct(private readonly NicknameService $nicknames) {}

    /**
     * @param  string  $before  the body this save replaced
     */
    public function handle(SpaceNote $note, User $editor, string $before): void
    {
        $surface = $note->side_chat_id !== null ? $note->sideChat : $note->channel;

        // A side chat has no roster of its own — it borrows the channel it lives in, which is
        // also where its `@Name` has always resolved from.
        $container = $note->side_chat_id !== null
            ? $note->sideChat?->channel?->container()
            : $note->channel?->container();

        if ($surface === null || $container === null) {
            return;
        }

        // Every name each member answers to here — their own plus their public nickname. The
        // same roster the message parser uses, so a name that mentions somebody in chat
        // mentions them in the note.
        $names = $this->nicknames->mentionNamesFor($container);

        $now = MentionParser::parse($note->content, $names);
        $was = MentionParser::parse($before, $names);

        $added = array_values(array_diff($now['user_ids'], $was['user_ids'], [$editor->getKey()]));
        $addedAll = $now['all'] && ! $was['all'];

        if ($added === [] && ! $addedAll) {
            return;
        }

        $message = $this->post($note, $editor, $this->body($editor, $added, $addedAll, $names));

        broadcast(new MessageSent($message));

        if ($note->side_chat_id !== null) {
            // A side chat's unread badge is its own event; it carries no mention flag, so the
            // named person gets the badge and the message text rather than a highlighted one.
            // Worth having as it is — the alternative is silence — and worth not faking.
            broadcast(new SideChatActivity($note->sideChat));

            return;
        }

        broadcast(new ChannelActivity($message, $added, $addedAll));

        SendPushNotifications::dispatchIf(FcmSender::enabled(), $message->id, $added, $addedAll);
    }

    /**
     * The message itself.
     *
     * `type = 'system'` — nobody *said* this, and it must not read as the editor talking. It is
     * written by the editor's user id all the same, so the timeline can draw who did it.
     */
    private function post(SpaceNote $note, User $editor, string $body): Message
    {
        $message = $note->side_chat_id !== null
            ? $note->sideChat->messages()->create([
                // The channel id rides along, exactly as a side chat message's does, so pins
                // and membership resolution work without knowing where it came from.
                'channel_id' => $note->sideChat->channel_id,
                'user_id' => $editor->getKey(),
                'body' => $body,
                'type' => 'system',
            ])
            : $note->channel->messages()->create([
                'user_id' => $editor->getKey(),
                'body' => $body,
                'type' => 'system',
            ]);

        return $message->load('user');
    }

    /**
     * "Ada mentioned @Bob and @Cara in the notes."
     *
     * The names are written as `@Name` on purpose: that is what makes the client render them as
     * chips and highlight the one that is *you*, off the same roster it renders every other
     * mention with. Written with each person's first listed name — their account name — because
     * that one is stable, where a nickname can be changed by somebody else between the note
     * being written and the message being read.
     *
     * @param  array<int, int>  $added
     * @param  array<int, array<int, string>>  $names
     */
    private function body(User $editor, array $added, bool $all, array $names): string
    {
        $mentions = $all
            ? ['@all']
            : array_map(fn (int $id) => '@'.($names[$id][0] ?? 'someone'), $added);

        $list = count($mentions) > 1
            ? implode(', ', array_slice($mentions, 0, -1)).' and '.end($mentions)
            : ($mentions[0] ?? '');

        return "📝 {$editor->name} mentioned {$list} in the notes.";
    }
}
