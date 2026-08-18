<?php

namespace App\Actions\Meeting;

use App\Actions\Message\PostSystemMessageAction;
use App\Events\ConversationUpdated;
use App\Models\Meeting;
use App\Models\MeetingJoin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Following a meeting link.
 *
 * ## Three outcomes, and only one of them adds anybody to anything
 *
 *  - **Already in the room** — nothing to do but note the arrival. A server's members and a
 *    group's members reach their own rooms without a link; the link is how they got the address,
 *    not what let them in.
 *  - **Outside, and the meeting admits outsiders** — they are added to the meeting's **group
 *    conversation**, which is the only container a link may write to. They appear in it like any
 *    other member: the conversation shows up in their chat list, and leaving it is the same
 *    gesture as leaving any group.
 *  - **Outside a server room** — refused, always. A link cannot admit anybody to a server,
 *    because being in a server is a thing that server's people decide, and a meeting link is not
 *    a back door into one. Said plainly rather than 404'd: the person following the link should
 *    be told to ask for an invite, not left thinking the meeting doesn't exist.
 *
 * ## The audit
 *
 * Every admission is recorded — who, when, by link or as a member, and whether they were an
 * outsider — because a link a stranger can follow makes "who is in here and how did they get in"
 * a question that has to have an answer after everyone has gone home. The call's roster can't
 * give it: an hour later it is empty, and on it a stranger and a colleague look identical.
 */
final class JoinMeetingAction
{
    public function __construct(private readonly PostSystemMessageAction $system) {}

    public function handle(Meeting $meeting, User $user, ?Request $request = null): Meeting
    {
        $channel = $meeting->channel;

        if ($channel === null) {
            throw ValidationException::withMessages(['meeting' => 'This meeting’s room no longer exists.']);
        }

        $inside = $channel->hasMember($user);

        if (! $inside) {
            if (! $meeting->isOpen()) {
                throw ValidationException::withMessages(['meeting' => 'This meeting link has expired.']);
            }

            if (! $meeting->admitsOutsiders()) {
                throw ValidationException::withMessages([
                    'meeting' => $channel->server_id !== null
                        ? 'This meeting is in a server you’re not in — ask for an invite to the server.'
                        : 'This meeting link doesn’t admit people from outside.',
                ]);
            }

            $this->admit($meeting, $user);
        }

        $this->record($meeting, $user, $inside ? 'member' : 'link', ! $inside, $request);

        return $meeting->load('channel.conversation', 'creator', 'scheduledEvent');
    }

    /** Add an outsider to the meeting's group conversation — the one container a link may write to. */
    private function admit(Meeting $meeting, User $user): void
    {
        $conversation = $meeting->channel->conversation;

        DB::transaction(function () use ($conversation, $user, $meeting) {
            $conversation->members()->syncWithoutDetaching([$user->getKey()]);

            // Said in the room, not just written to the audit: the people already in a meeting
            // are entitled to notice that somebody from outside has walked in.
            $this->system->handle($meeting->channel, $user, "👋 **{$user->name}** joined via the meeting link.");
        });

        broadcast(new ConversationUpdated($conversation->fresh()->load('members')));
    }

    /**
     * One row per person per meeting, stamped on first arrival.
     *
     * A record of admission rather than attendance — rejoining after a dropped connection is not
     * a second event, and `firstOrCreate` is what keeps a flaky line from writing a log nobody
     * can read.
     */
    private function record(Meeting $meeting, User $user, string $via, bool $external, ?Request $request): void
    {
        MeetingJoin::firstOrCreate(
            ['meeting_id' => $meeting->getKey(), 'user_id' => $user->getKey()],
            [
                'via' => $via,
                'external' => $external,
                // Coarse forensics and no more: enough to tell two strangers apart in a log.
                'ip' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
            ],
        );
    }
}
