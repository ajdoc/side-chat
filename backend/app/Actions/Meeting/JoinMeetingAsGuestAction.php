<?php

namespace App\Actions\Meeting;

use App\Actions\Message\PostSystemMessageAction;
use App\Events\ConversationUpdated;
use App\Models\Meeting;
use App\Models\MeetingJoin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Walking in with no account: a name, and you're in the meeting.
 *
 * ## What a guest actually is
 *
 * A `users` row with `is_guest`, no password, and a use-by date — the same trick a bot is. That
 * is not a shortcut around building "real" anonymous access; it *is* the implementation. Every
 * seat in this app is a user row, so the alternative would be making `user_id` nullable in the
 * roster, the timeline and the member list to accommodate the least trusted person in the room.
 *
 * ## The four things this refuses
 *
 * - **A meeting that isn't open to guests.** The level is a deliberate choice by whoever made it.
 * - **A server room**, always. A link is not a door into a server, and a throwaway account is the
 *   last thing that should hold one open.
 * - **An encrypted channel.** Device keys belong to accounts that persist; a guest would either
 *   be unable to read the room or would break the promise that only its people can.
 * - **An expired link.** The room outlives it; the door doesn't.
 *
 * ## What the guest gets
 *
 * Membership of exactly one group conversation, a token scoped by
 * {@see \App\Http\Middleware\ConfineGuests}, and a name with **Guest** attached to it wherever it
 * appears — nobody in the room should have to work out that the new arrival is a stranger.
 */
final class JoinMeetingAsGuestAction
{
    /**
     * How long a guest account is good for, when the meeting itself doesn't say.
     *
     * Public because following a *second* link extends the same account rather than minting
     * another — see {@see JoinMeetingAction::extendGuest()}, which needs the same number.
     */
    public const HOURS = 12;

    public function __construct(private readonly PostSystemMessageAction $system) {}

    /** @return array{user: User, token: string, meeting: Meeting} */
    public function handle(Meeting $meeting, string $name, ?Request $request = null): array
    {
        if (! $meeting->isOpen()) {
            throw ValidationException::withMessages(['meeting' => 'This meeting link has expired.']);
        }

        if (! $meeting->admitsGuests()) {
            throw ValidationException::withMessages([
                'meeting' => $meeting->channel?->server_id !== null
                    ? 'This meeting is in a server, so it needs an account you’ve been invited with.'
                    : 'This meeting needs an account to join.',
            ]);
        }

        $name = $this->cleanName($name);

        return DB::transaction(function () use ($meeting, $name, $request) {
            $guest = User::create([
                'name' => $name,
                // `users.email` is unique and not null, and a guest has no address worth having.
                // The same reserved, non-routable domain trick bots use, so a synthetic address
                // can never collide with — or be mistaken for — a person's.
                'email' => 'guest-'.Str::lower(Str::random(20)).'@guests.invalid',
                'password' => null,
                'is_guest' => true,
                // The meeting's own expiry when it has one, so a link with a deadline doesn't
                // leave accounts behind that outlive it.
                'guest_expires_at' => $meeting->expires_at ?? now()->addHours(self::HOURS),
            ]);

            $conversation = $meeting->channel->conversation;
            $conversation->members()->syncWithoutDetaching([$guest->getKey()]);

            // Said in the room. The people in a meeting are entitled to notice that somebody with
            // no account has walked in, and to see which name they chose.
            $this->system->handle($meeting->channel, $guest, "👋 **{$name}** joined as a guest.");

            MeetingJoin::create([
                'meeting_id' => $meeting->getKey(),
                'user_id' => $guest->getKey(),
                'via' => 'guest',
                'external' => true,
                'ip' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
            ]);

            broadcast(new ConversationUpdated($conversation->fresh()->load('members')));

            return [
                'user' => $guest,
                // A Passport token like anybody else's — what makes every existing endpoint work
                // for a guest without a second authentication path to keep in step.
                'token' => $guest->createToken('guest')->accessToken,
                'meeting' => $meeting->load('channel'),
            ];
        });
    }

    /**
     * The name they typed, made safe to put next to other people's.
     *
     * Whitespace collapsed and length capped, because a display name is rendered everywhere and a
     * guest is the one person here who never agreed to anything. Impersonation is handled by the
     * **Guest** marker the client draws rather than by policing the string: somebody typing a
     * colleague's name is obvious the moment it appears beside that label.
     */
    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) < 1) {
            throw ValidationException::withMessages(['name' => 'Pick a name people will see.']);
        }

        return mb_substr($name, 0, 40);
    }
}
