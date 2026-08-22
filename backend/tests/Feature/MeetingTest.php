<?php

use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\MeetingJoin;
use App\Models\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

beforeEach(function () {
    // Joining as a guest issues a real token, so Passport needs its personal access client in
    // the refreshed test database — the same setup AuthTest does for register/login.
    app(ClientRepository::class)->createPersonalAccessGrantClient('Testing');
});

/**
 * Meetings: the link, what it creates, and who it lets in.
 *
 * The cases that matter are the doors. A meeting link is the one address in this app a stranger
 * can follow, so the tests are mostly about what it refuses — and about the audit, which is the
 * answer to "who got in" once the call is over and the roster is empty.
 */
it('creates a group chat whose channel is the room, defaulting to voice', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Design sync'])
        ->assertCreated()->json('data');

    $channel = Channel::find($meeting['channel_id']);

    // No server named, so it's a group conversation — which is what lets somebody who shares no
    // server with you be in it.
    expect($channel->conversation_id)->not->toBeNull()
        ->and($channel->conversation->name)->toBe('Design sync')
        // Voice unless somebody deliberately chooses otherwise. A conversation's channel already
        // allows calls, so nothing had to be converted.
        ->and($channel->type)->toBe('text')
        ->and($channel->allowsCalls())->toBeTrue()
        ->and($meeting['token'])->not->toBeEmpty();
});

it('makes the room a Side Space when asked, with a map to stand on', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Standup', 'type' => 'space'])
        ->assertCreated()->json('data');

    $channel = Channel::find($meeting['channel_id']);

    expect($channel->type)->toBe('space')
        ->and($channel->spaceMap()->exists())->toBeTrue();
});

it('gives a server’s Side Space meeting a map on the discussion people actually open', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', [
        'title' => 'All hands', 'server_id' => $server->id, 'type' => 'space',
    ])->assertCreated()->json('data');

    $room = Channel::find($meeting['channel_id']);

    /*
     * **The room is the General discussion**, not the container.
     *
     * That's what the sidebar links to (`resolveDiscussion`) and where every other space
     * channel's map lives. Pointing the meeting at the container sent people to a channel with
     * no map — "could not load this space" — and seeding the container instead would have made
     * this the one Side Space in the app shaped differently from all the others.
     */
    expect($room->parent_id)->not->toBeNull()
        ->and($room->type)->toBe('space')
        ->and($room->spaceMap()->exists())->toBeTrue()
        // The room built for meetings, not an empty grid.
        ->and($room->spaceMap->name)->toBe('Meeting room')
        // The container stays mapless, exactly like every other space channel's.
        ->and($room->parent->spaceMap()->exists())->toBeFalse();
});

it('points a meeting at a room that already exists, creating nothing', function () {
    [$owner, $server, $room] = ownerWithVoiceChannel();
    Passport::actingAs($owner);

    // The rooms the dialog offers are the ones the create path accepts — one query, two uses.
    $offered = $this->getJson('/api/meetings/rooms')->assertOk()->json('data');
    expect(collect($offered)->pluck('id')->all())->toContain($room->id);

    $meeting = $this->postJson('/api/meetings', [
        'title' => 'Weekly', 'channel_id' => $room->id, 'starts_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated()->json('data');

    expect($meeting['channel_id'])->toBe($room->id)
        ->and(Channel::where('server_id', $server->id)->whereNull('parent_id')->count())->toBe(1)
        // Scheduling still goes through the calendar, in the room's own.
        ->and(CalendarEvent::sole()->room_channel_id)->toBe($room->id);
});

it('tells a member of the server that they can join a room they can see', function () {
    [$owner, $server, $room] = ownerWithVoiceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id);

    Passport::actingAs($owner);
    $meeting = $this->postJson('/api/meetings', ['title' => 'Standup', 'channel_id' => $room->id])
        ->assertCreated()->json('data');

    Passport::actingAs($member);

    /*
     * The preview route is public, so no `auth:api` middleware runs on it — and `$request->user()`
     * then asks the *default* guard (`web`) and answers null however good the token is. Every
     * signed-in person looked like a stranger, and their own meeting told them it was "in a server
     * you're not in".
     */
    $this->getJson("/api/meetings/{$meeting['token']}")
        ->assertOk()
        ->assertJson(['data' => ['member' => true, 'can_join' => true, 'needs' => null]]);
});

it('says which kind of refusal it is', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $inServer = $this->postJson('/api/meetings', ['title' => 'Board', 'server_id' => $server->id])
        ->assertCreated()->json('data');
    $inGroup = $this->postJson('/api/meetings', ['title' => 'Private', 'access' => 'members'])
        ->assertCreated()->json('data');

    Passport::actingAs(User::factory()->create());

    // Two different situations, and the page used to give the server sentence for both.
    $this->getJson("/api/meetings/{$inServer['token']}")->assertOk()
        ->assertJsonPath('data.needs', 'server-invite');
    $this->getJson("/api/meetings/{$inGroup['token']}")->assertOk()
        ->assertJsonPath('data.needs', 'invite');
});

it('creates a room in a server when one is named', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', [
        'title' => 'All hands', 'server_id' => $server->id, 'type' => 'voice',
    ])->assertCreated()->json('data');

    $channel = Channel::find($meeting['channel_id']);

    expect($channel->server_id)->toBe($server->id)
        ->and($channel->type)->toBe('voice')
        // Everyone in the server can walk in without being invited to anything.
        ->and(Conversation::count())->toBe(0);
});

it('schedules through the calendar rather than inventing its own idea of when', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', [
        'title' => 'Retro', 'starts_at' => now()->addHour()->toIso8601String(),
    ])->assertCreated()->json('data');

    $event = CalendarEvent::sole();

    // The entry is in the room's own calendar, points at the room, and is armed — so reminders,
    // rescheduling and the room's agenda are all the calendar's, unchanged.
    expect($event->title)->toBe('Retro')
        ->and($event->room_channel_id)->toBe($meeting['channel_id'])
        ->and($event->remind_minutes)->toBe(10)
        ->and(Meeting::sole()->scheduled_event_id)->toBe($event->id);
});

it('lets an outsider follow the link into a group meeting, and records it', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Client call', 'access' => 'account'])
        ->assertCreated()->json('data');

    // Somebody who shares no server and no chat with the creator.
    $outsider = User::factory()->create();
    Passport::actingAs($outsider);

    $this->getJson("/api/meetings/{$meeting['token']}")
        ->assertOk()->assertJson(['data' => ['can_join' => true, 'member' => false]]);

    $this->postJson("/api/meetings/{$meeting['token']}/join")->assertOk();

    $channel = Channel::find($meeting['channel_id']);

    // They're in the group chat now — it appears in their chat list like any other.
    expect($channel->conversation->members()->whereKey($outsider->id)->exists())->toBeTrue();

    $join = MeetingJoin::where('user_id', $outsider->id)->sole();

    // The audit is the answer to "who got in, and how" once the call is over.
    expect($join->via)->toBe('link')
        ->and($join->external)->toBeTrue();

    // And the room was told out loud, not just in the log.
    expect($channel->messages()->where('type', 'system')->latest('id')->first()->body)
        ->toContain('joined via the meeting link');
});

it('never lets a link admit somebody to a server room', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    // The combination is refused at creation rather than stored as a promise the link can't keep.
    $this->postJson('/api/meetings', [
        'title' => 'Board', 'server_id' => $server->id, 'access' => 'account',
    ])->assertStatus(422);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Board', 'server_id' => $server->id])
        ->assertCreated()->json('data');

    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/meetings/{$meeting['token']}")->assertOk()->assertJson(['data' => ['can_join' => false]]);
    // Told to ask for an invite, rather than 404'd into thinking the meeting doesn't exist.
    $this->postJson("/api/meetings/{$meeting['token']}/join")->assertStatus(422);
});

it('refuses an outsider when the link doesn’t admit them', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Private'])->assertCreated()->json('data');

    Passport::actingAs(User::factory()->create());
    $this->postJson("/api/meetings/{$meeting['token']}/join")->assertStatus(422);

    expect(MeetingJoin::count())->toBe(0);
});

it('records a member’s arrival without adding them to anything', function () {
    [$owner, $server] = ownerWithServer();
    $member = User::factory()->create();
    $server->members()->attach($member->id);

    Passport::actingAs($owner);
    $meeting = $this->postJson('/api/meetings', ['title' => 'Standup', 'server_id' => $server->id])
        ->assertCreated()->json('data');

    Passport::actingAs($member);
    $this->postJson("/api/meetings/{$meeting['token']}/join")->assertOk();

    // The link was how they got the address, not what let them in.
    expect(MeetingJoin::sole()->via)->toBe('member')
        ->and(MeetingJoin::sole()->external)->toBeFalse();
});

it('shows the audit to whoever answers for the room, and to nobody else', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);
    $meeting = $this->postJson('/api/meetings', ['title' => 'Client call', 'access' => 'account'])
        ->assertCreated()->json('data');

    $outsider = User::factory()->create();
    Passport::actingAs($outsider);
    $this->postJson("/api/meetings/{$meeting['token']}/join")->assertOk();

    // A guest list is a record *about* people — not something everybody in the call may read.
    $this->getJson("/api/meetings/{$meeting['token']}/joins")->assertForbidden();

    Passport::actingAs($owner);
    $audit = $this->getJson("/api/meetings/{$meeting['token']}/joins")->assertOk()->json('data');

    expect(collect($audit)->pluck('via')->all())->toContain('link')
        // The IP and user agent are stored for an operator and never shipped to a screen.
        ->and($audit[0])->not->toHaveKey('ip');
});

/*
 * Getting the link back afterwards — the gap that made the first version of this feature
 * unusable: a link existed only in the dialog that created it.
 */

it('lists a room’s links to anybody in the room, and makes one for a room that has none', function () {
    [$owner, $server, $room] = ownerWithVoiceChannel();
    Passport::actingAs($owner);

    // A room nobody has made a meeting for yet.
    expect($this->getJson("/api/channels/{$room->id}/meeting-links")->assertOk()->json('data'))->toBe([]);

    // "Get the link" for an existing room creates the meeting *pointing at it* — no second room.
    $meeting = $this->postJson('/api/meetings', ['title' => 'Standup', 'channel_id' => $room->id])
        ->assertCreated()->json('data');

    // No second room was made — the meeting points at the one that was already there. Counted
    // over containers only, since every channel carries a General discussion of its own.
    expect($meeting['channel_id'])->toBe($room->id)
        ->and(Channel::where('server_id', $server->id)->whereNull('parent_id')->count())->toBe(1);

    // Any member may read it: a link is exactly what they're entitled to pass on.
    $member = User::factory()->create();
    $server->members()->attach($member->id);
    Passport::actingAs($member);

    $links = $this->getJson("/api/channels/{$room->id}/meeting-links")->assertOk()->json('data');

    expect($links)->toHaveCount(1)
        ->and($links[0]['token'])->toBe($meeting['token']);
});

it('keeps a room’s links away from people who aren’t in the room', function () {
    [$owner, , $room] = ownerWithVoiceChannel();
    Passport::actingAs($owner);
    $this->postJson('/api/meetings', ['title' => 'Private', 'channel_id' => $room->id])->assertCreated();

    Passport::actingAs(User::factory()->create());

    // The token is the whole of a link's secrecy, so handing the list to outsiders would make
    // the unguessable part pointless.
    $this->getJson("/api/channels/{$room->id}/meeting-links")->assertForbidden();
});

it('leaves an expired link out of the room’s list', function () {
    [$owner, , $room] = ownerWithVoiceChannel();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Old', 'channel_id' => $room->id])
        ->assertCreated()->json('data');

    Meeting::whereKey($meeting['id'])->update(['expires_at' => now()->subDay()]);

    // A link that no longer admits anybody is not an address worth copying.
    expect($this->getJson("/api/channels/{$room->id}/meeting-links")->json('data'))->toBe([]);
});

/*
 * Guests: a link anybody can walk through.
 *
 * The tests that matter are the doors and the fence — what a guest may not touch is the whole
 * safety story, and it's enforced in one middleware rather than by hiding buttons.
 */

it('lets somebody with no account walk in, and marks them a guest', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Open house', 'access' => 'guest'])
        ->assertCreated()->json('data');

    // Signed out entirely — the link page has to work for somebody who cannot sign in.
    app('auth')->forgetGuards();

    $this->getJson("/api/meetings/{$meeting['token']}")
        ->assertOk()->assertJson(['data' => ['guests' => true, 'can_join' => true]]);

    $res = $this->postJson("/api/meetings/{$meeting['token']}/guest", ['name' => 'Sam from Acme'])
        ->assertOk()->json();

    $guest = User::find($res['user']['id']);

    expect($res['token'])->not->toBeEmpty()
        ->and($guest->is_guest)->toBeTrue()
        ->and($guest->guest_expires_at)->not->toBeNull()
        // In the meeting's group chat, and nothing else.
        ->and($guest->conversations()->count())->toBe(1);

    // The room is told out loud that somebody with no account walked in.
    expect(Channel::find($meeting['channel_id'])->messages()->where('type', 'system')->latest('id')->first()->body)
        ->toContain('joined as a guest');

    // And the audit says how they got in.
    expect(MeetingJoin::where('user_id', $guest->id)->sole()->via)->toBe('guest');
});

it('confines a guest to the meeting they joined', function () {
    [$owner, $server, $elsewhere] = ownerWithChannel();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Open house', 'access' => 'guest'])
        ->assertCreated()->json('data');

    app('auth')->forgetGuards();
    $joined = $this->postJson("/api/meetings/{$meeting['token']}/guest", ['name' => 'Sam'])->json();

    $guest = User::find($joined['user']['id']);
    Passport::actingAs($guest);

    // Their own room: yes. This is the meeting they were let into.
    $this->getJson("/api/channels/{$meeting['channel_id']}/messages")->assertOk();
    $this->postJson("/api/channels/{$meeting['channel_id']}/messages", ['body' => 'hello'])->assertCreated();

    /*
     * ...but being *in* the meeting is not being able to work in the room. Membership alone would
     * have handed a stranger the whole Side Desk of a chat they were let into for half an hour.
     */
    $this->postJson("/api/channels/{$meeting['channel_id']}/calendar", [
        'title' => 'Guest party', 'starts_at' => now()->addDay()->toIso8601String(),
    ])->assertForbidden();
    $this->postJson("/api/channels/{$meeting['channel_id']}/kanban/cards", ['text' => 'mine'])->assertForbidden();
    $this->putJson("/api/channels/{$meeting['channel_id']}/notes", ['content' => 'rewritten'])->assertForbidden();
    $this->getJson("/api/channels/{$meeting['channel_id']}/meeting-links")->assertForbidden();

    /*
     * And not through chat either. Commands are the other door into a room's apps — a guest who
     * could file a card by typing `k!add` would make the gate above decorative — so what they
     * typed is posted as what they typed.
     */
    $said = $this->postJson("/api/channels/{$meeting['channel_id']}/messages", ['body' => 'k!add sneaky'])
        ->assertCreated()->json('data');

    expect($said['body'])->toBe('k!add sneaky')
        ->and($said['type'])->toBeNull()
        ->and(App\Models\KanbanCard::where('channel_id', $meeting['channel_id'])->count())->toBe(0);

    /*
     * Everything else: no. Membership alone would already refuse most of these — what the
     * middleware adds is refusing the ones an ordinary account *would* be allowed, which is the
     * half that matters for somebody admitted to a single call.
     */
    $this->getJson("/api/channels/{$elsewhere->id}/messages")->assertForbidden();
    $this->getJson('/api/servers')->assertForbidden();
    $this->postJson('/api/meetings', ['title' => 'Mine now'])->assertForbidden();
    $this->getJson('/api/search?q=anything')->assertForbidden();
    $this->postJson('/api/conversations/dm', ['user_id' => $owner->id])->assertForbidden();
    $this->patchJson('/api/profile', ['name' => 'Someone Else'])->assertForbidden();

    // The two things an account needs to function while it exists.
    $this->getJson('/api/auth/me')->assertOk();
    $this->getJson('/api/conversations')->assertOk();
});

/*
 * One guest, two links.
 *
 * Signing out and back in as a fresh stranger to follow a second link loses the chat they were
 * already in and makes one visitor look like two in the audit — so the same account follows it.
 * What must *not* travel with them is any extra reach: an `account` link stays shut.
 */
it('lets a guest follow a second link without signing out', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $first = $this->postJson('/api/meetings', ['title' => 'Open house', 'access' => 'guest'])
        ->assertCreated()->json('data');
    $second = $this->postJson('/api/meetings', ['title' => 'The other one', 'access' => 'guest'])
        ->assertCreated()->json('data');
    $accountOnly = $this->postJson('/api/meetings', ['title' => 'Members and accounts', 'access' => 'account'])
        ->assertCreated()->json('data');

    app('auth')->forgetGuards();
    $joined = $this->postJson("/api/meetings/{$first['token']}/guest", ['name' => 'Sam'])->json();

    $guest = User::find($joined['user']['id']);
    Passport::actingAs($guest);

    // The second link: previewed, then followed, on the account they already have.
    $this->getJson("/api/meetings/{$second['token']}")->assertOk()->assertJsonPath('data.can_join', true);
    $this->postJson("/api/meetings/{$second['token']}/join")->assertOk();

    // Both rooms, and nothing invented: the same user id is in both.
    $this->getJson("/api/channels/{$first['channel_id']}/messages")->assertOk();
    $this->getJson("/api/channels/{$second['channel_id']}/messages")->assertOk();
    expect($this->getJson('/api/conversations')->json('data'))->toHaveCount(2);

    // A door a stranger with no account could not have walked through stays shut, and the page
    // is told why rather than being offered a button that fails.
    $this->getJson("/api/meetings/{$accountOnly['token']}")
        ->assertOk()
        ->assertJsonPath('data.can_join', false)
        ->assertJsonPath('data.needs', 'account');
    $this->postJson("/api/meetings/{$accountOnly['token']}/join")->assertStatus(422);
    $this->getJson("/api/channels/{$accountOnly['channel_id']}/messages")->assertForbidden();

    // And the link list is still not a thing a guest may read, token or no token.
    $this->getJson('/api/meetings/rooms')->assertForbidden();
});

it('stops working the moment the guest session lapses', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);
    $meeting = $this->postJson('/api/meetings', ['title' => 'Open house', 'access' => 'guest'])
        ->assertCreated()->json('data');

    app('auth')->forgetGuards();
    $joined = $this->postJson("/api/meetings/{$meeting['token']}/guest", ['name' => 'Sam'])->json();

    $guest = User::find($joined['user']['id']);
    $guest->update(['guest_expires_at' => now()->subMinute()]);

    Passport::actingAs($guest);

    // Refused on sight rather than whenever the sweeper next runs.
    $this->getJson("/api/channels/{$meeting['channel_id']}/messages")->assertUnauthorized();
});

it('refuses a guest at a server meeting and at an encrypted room, whatever the setting', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    // A server meeting can't even be created with an open door.
    $this->postJson('/api/meetings', ['title' => 'Board', 'server_id' => $server->id, 'access' => 'guest'])
        ->assertStatus(422);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Secrets', 'access' => 'guest'])
        ->assertCreated()->json('data');

    // Encryption turned on afterwards closes the door, because a throwaway account can't hold a
    // device key and the room's promise is that only its people can read it.
    Channel::whereKey($meeting['channel_id'])->update(['encrypted' => true]);

    app('auth')->forgetGuards();
    $this->getJson("/api/meetings/{$meeting['token']}")->assertOk()->assertJson(['data' => ['guests' => false]]);
    $this->postJson("/api/meetings/{$meeting['token']}/guest", ['name' => 'Sam'])->assertStatus(422);
});

it('turns strangers away from a meeting that only admits accounts', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $meeting = $this->postJson('/api/meetings', ['title' => 'Members only', 'access' => 'account'])
        ->assertCreated()->json('data');

    app('auth')->forgetGuards();

    // The middle level: an account is required, and the link page says so rather than offering
    // a name field that would fail.
    $this->getJson("/api/meetings/{$meeting['token']}")->assertOk()->assertJson(['data' => ['guests' => false]]);
    $this->postJson("/api/meetings/{$meeting['token']}/guest", ['name' => 'Sam'])->assertStatus(422);
});

it('retires a guest once their meeting is long over, without erasing them', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);
    $meeting = $this->postJson('/api/meetings', ['title' => 'Open house', 'access' => 'guest'])
        ->assertCreated()->json('data');

    app('auth')->forgetGuards();
    $joined = $this->postJson("/api/meetings/{$meeting['token']}/guest", ['name' => 'Sam'])->json();
    $guest = User::find($joined['user']['id']);

    Passport::actingAs($guest);
    $this->postJson("/api/channels/{$meeting['channel_id']}/messages", ['body' => 'hello all'])->assertCreated();

    $said = Channel::find($meeting['channel_id'])->messages()->count();

    // Not yet — a call that ran over must not have its guests cut off mid-sentence.
    $guest->update(['guest_expires_at' => now()->subHour()]);
    $this->artisan('guests:prune')->assertSuccessful();
    expect($guest->fresh()->tokens()->count())->toBeGreaterThan(0);

    $guest->update(['guest_expires_at' => now()->subDay()]);
    $this->artisan('guests:prune')->assertSuccessful();

    /*
     * The credential stops existing; the record does not. Deleting the row would cascade into
     * both `messages` (cutting their side out of everybody else's transcript) and
     * `meeting_joins` (erasing the audit of who was admitted — the thing it exists for).
     */
    expect(User::find($guest->id))->not->toBeNull()
        ->and($guest->fresh()->tokens()->count())->toBe(0)
        ->and($guest->fresh()->conversations()->count())->toBe(0)
        ->and(Channel::find($meeting['channel_id'])->messages()->count())->toBe($said)
        ->and(MeetingJoin::where('user_id', $guest->id)->exists())->toBeTrue();
});
