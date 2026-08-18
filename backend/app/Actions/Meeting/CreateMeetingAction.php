<?php

namespace App\Actions\Meeting;

use App\Actions\Channel\ChangeChannelTypeAction;
use App\Actions\Conversation\CreateGroupAction;
use App\DTOs\Conversation\CreateGroupData;
use App\Events\ChannelCreated;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\Meeting;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Make me a meeting."
 *
 * One call, three shapes, and the caller says which by *where* the meeting is to be:
 *
 *  - **In a server** — a room is made in it (a voice channel by default, a Side Space if asked
 *    for). The meeting belongs where the team already looks, and everyone in the server can walk
 *    in without being invited to anything.
 *  - **Outside a server** — a **group conversation** is made and its channel is the room. That
 *    is what lets somebody who shares no server with you be in the meeting: a group chat is
 *    already this app's answer to "these particular people", and it arrives in their chat list
 *    like any other.
 *  - **An existing room** — nothing is created; the meeting is a link (and perhaps a schedule)
 *    pointing at a room that is already there.
 *
 * The type defaults to **voice**, because that is what "a meeting" means to nearly everybody, and
 * a Side Space is a deliberate choice about how it should feel.
 *
 * ## Scheduling stays the calendar's
 *
 * Given a time, a {@see CalendarEvent} is written in the room's own calendar with the room set
 * and a reminder armed, and the meeting points at it. Nothing here learns about time: reminders,
 * rescheduling and the room's agenda are all the calendar's, unchanged.
 */
final class CreateMeetingAction
{
    public function __construct(private readonly CreateGroupAction $groups) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $creator, array $data): Meeting
    {
        $title = trim((string) ($data['title'] ?? '')) ?: 'Meeting';
        $type = ($data['type'] ?? 'voice') === 'space' ? 'space' : 'voice';

        return DB::transaction(function () use ($creator, $data, $title, $type) {
            $channel = $this->room($creator, $data, $title, $type);

            $meeting = Meeting::create([
                'channel_id' => $channel->getKey(),
                'created_by' => $creator->getKey(),
                'title' => $title,
                /*
                 * How far open the door is — members / account / guest, see Meeting::ACCESS.
                 *
                 * Only a group conversation's meeting can honour anything but `members`; the
                 * request refuses the combination outright rather than storing a promise the
                 * link can't keep.
                 */
                'access' => in_array($data['access'] ?? null, Meeting::ACCESS, true) ? $data['access'] : 'members',
            ]);

            if (! empty($data['starts_at'])) {
                $meeting->update(['scheduled_event_id' => $this->schedule($channel, $creator, $title, $data)->getKey()]);
            }

            return $meeting->load('channel', 'creator', 'scheduledEvent');
        });
    }

    /** The room this meeting happens in — found, or made. */
    private function room(User $creator, array $data, string $title, string $type): Channel
    {
        // An existing room, named outright. Nothing is created; the meeting is a link to it.
        if (! empty($data['channel_id'])) {
            $channel = Channel::query()->visibleTo($creator)->whereKey($data['channel_id'])->first();

            if ($channel === null) {
                throw ValidationException::withMessages(['channel_id' => 'That room isn’t one you can see.']);
            }

            if (! $channel->allowsCalls()) {
                throw ValidationException::withMessages(['channel_id' => 'That channel isn’t a room a meeting can happen in.']);
            }

            return $channel;
        }

        if (! empty($data['server_id'])) {
            return $this->serverRoom($creator, (int) $data['server_id'], $title, $type, $data['map_preset'] ?? null);
        }

        return $this->groupRoom($creator, $data, $title, $type);
    }

    /** A new voice channel or Side Space in a server the creator may add channels to. */
    private function serverRoom(User $creator, int $serverId, string $title, string $type, ?string $preset): Channel
    {
        $server = Server::query()->whereKey($serverId)->first();

        if ($server === null || ! $server->isStaff($creator)) {
            // Staff, because this makes a channel — the same bar creating one by hand clears.
            throw ValidationException::withMessages(['server_id' => 'You can’t create a room in that server.']);
        }

        $channel = $server->channels()->create([
            'name' => $title,
            'type' => $type,
            'position' => (int) $server->channels()->max('position') + 1,
        ]);

        $channel->discussions()->create([
            'server_id' => $server->getKey(),
            'name' => 'General',
            'type' => $type,
            'position' => 0,
        ]);

        if ($type === 'space') {
            app(ChangeChannelTypeAction::class)->handle($channel, 'space', $preset);
        }

        broadcast(new ChannelCreated($channel));

        return $channel;
    }

    /**
     * A group conversation whose channel is the room.
     *
     * Made as an ordinary group chat — an owner, a member list, a timeline, a place in everyone's
     * chat list — and its channel then converted to the room type. Converting rather than
     * creating it that way keeps one definition of what a group chat *is*; the only thing a
     * meeting changes about one is its lid. A voice meeting needs no conversion at all, since a
     * conversation's channel already allows calls.
     */
    private function groupRoom(User $creator, array $data, string $title, string $type): Channel
    {
        $invited = array_values(array_unique(array_map('intval', $data['invite_user_ids'] ?? [])));

        $conversation = $this->groups->handle($creator, CreateGroupData::fromArray([
            'name' => $title,
            /*
             * The creator's own id when nobody was invited.
             *
             * A group chat made by hand requires somebody to make it *with* — that's the right
             * rule for a chat. A meeting is the case that rule doesn't fit: "make me a link,
             * I'll send it round" is a room with one person in it and an address to share, and
             * the alternative would be forcing a guest list before you have one. The action
             * de-duplicates the creator in, so this is a group of one.
             */
            'user_ids' => $invited ?: [$creator->getKey()],
        ]));

        $channel = $conversation->channel;

        if ($type === 'space') {
            app(ChangeChannelTypeAction::class)->handle($channel, 'space', $data['map_preset'] ?? null);
        }

        return $channel->refresh();
    }

    /** The calendar entry, in the room's own calendar, with the room set and a reminder armed. */
    private function schedule(Channel $channel, User $creator, string $title, array $data): CalendarEvent
    {
        return $channel->calendarEvents()->create([
            'user_id' => $creator->getKey(),
            'title' => $title,
            'starts_at' => $data['starts_at'],
            // Ten minutes by default: a scheduled meeting nobody is reminded about is a diary
            // entry, and the reminder is most of why somebody scheduled it here.
            'remind_minutes' => $data['remind_minutes'] ?? 10,
            'room_channel_id' => $channel->getKey(),
        ]);
    }
}
