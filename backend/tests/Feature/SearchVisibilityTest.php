<?php

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\SideChat;
use App\Models\User;
use Laravel\Passport\Passport;

/*
 * What search must never return.
 *
 * Every other endpoint in this app is addressed at one place and refuses you at the door
 * (see MemberRequest). Search is addressed at *everything* and has no door: it answers with
 * whatever the query matched, so the visibility rules aren't a check that runs before the
 * feature — they are the feature. A bug here doesn't 500, it quietly hands somebody the
 * contents of a channel they were shut out of, and no test of "does search find my
 * message" would notice.
 *
 * Hence a whole file of nothing but exclusions, one per way in: a server you left, a
 * private channel, a side chat you never joined, a DM that isn't yours. Each one seeds the
 * message with a word nobody else's fixture uses, so a leak shows up as a hit rather than
 * as an ordering change.
 */

/** Search as this person, for this word. Returns the message rows. */
function searchMessages(User $user, string $term, array $params = []): array
{
    Passport::actingAs($user);

    return test()->getJson('/api/search?'.http_build_query(['q' => $term, 'type' => 'messages'] + $params))
        ->assertOk()
        ->json('data');
}

it('finds your own message by a word in it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'the pangolin has landed']);

    $rows = searchMessages($owner, 'pangolin');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['body'])->toBe('the pangolin has landed')
        // A result is useless without knowing where it came from.
        ->and($rows[0]['context']['channel_name'])->toBe($channel->name)
        ->and($rows[0]['context']['server_id'])->not->toBeNull();
});

it('never returns a message from a server you are not in', function () {
    [$owner, , $channel] = ownerWithChannel();
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'secret pangolin plans']);

    $stranger = User::factory()->create();

    expect(searchMessages($stranger, 'pangolin'))->toBeEmpty();
});

it('never returns a message from a private channel you are shut out of', function () {
    [, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    Message::factory()->create(['channel_id' => $channel->id, 'body' => 'private pangolin business']);

    // A plain member of the server, but not on the channel's allow-list.
    expect(searchMessages($member, 'pangolin'))->toBeEmpty();

    // …and once they are on it, the same search finds it. Same rule as Channel::hasMember,
    // which is the whole point: search must not be a second, laxer copy of that rule.
    $channel->allowedMembers()->attach($member->id);
    expect(searchMessages($member, 'pangolin'))->toHaveCount(1);
});

it('lets a server admin search a private channel they are not listed on', function () {
    [$owner, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => Server::ROLE_ADMIN]);
    $channel = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    Message::factory()->create(['channel_id' => $channel->id, 'body' => 'locked pangolin room']);

    // The people who set the lock keep the key — for the owner and for admins alike.
    expect(searchMessages($admin, 'pangolin'))->toHaveCount(1)
        ->and(searchMessages($owner, 'pangolin'))->toHaveCount(1);
});

it('never returns a side chat message to somebody who has not joined it', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $sideChat->participants()->attach($owner->id, ['role' => 'owner']);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'side_chat_id' => $sideChat->id,
        'user_id' => $owner->id,
        'body' => 'pangolin, in the side chat',
    ]);

    // The channel is open to the whole server, and that is precisely the trap: the message
    // carries its channel_id, so the channel gate alone would let this through.
    expect(searchMessages($member, 'pangolin'))->toBeEmpty()
        ->and(searchMessages($owner, 'pangolin'))->toHaveCount(1);
});

it('finds a thread reply, and labels it as one', function () {
    [$owner, , $channel] = ownerWithChannel();
    $thread = $channel->threads()->create(['name' => 'deploy plan', 'user_id' => $owner->id]);
    Message::factory()->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $owner->id,
        'body' => 'the pangolin is ready',
    ]);

    // In scope on purpose: a thing said in this channel is a thing said in this channel,
    // and the searcher knows they said it in a thread.
    $rows = searchMessages($owner, 'pangolin');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['context']['thread_name'])->toBe('deploy plan');
});

it('never returns a message from a DM you are not in', function () {
    [$a, $b] = dmBetween();
    Message::factory()->create(['channel_id' => $a->conversations()->first()->channel->id, 'user_id' => $a->id, 'body' => 'pangolin gossip']);

    $outsider = User::factory()->create();

    expect(searchMessages($outsider, 'pangolin'))->toBeEmpty()
        ->and(searchMessages($b, 'pangolin'))->toHaveCount(1);
});

it('returns nothing rather than refusing when you scope to a channel you cannot read', function () {
    [$owner, , $channel] = ownerWithChannel();
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'pangolin']);

    $stranger = User::factory()->create();

    // Not a 403: the scope intersects with what you can see rather than replacing it, so
    // naming somebody else's channel is indistinguishable from naming an empty one — which
    // is the answer that leaks the least.
    expect(searchMessages($stranger, 'pangolin', ['channel_id' => $channel->id]))->toBeEmpty();
});

it('leaves system messages out of the results', function () {
    [$owner, , $channel] = ownerWithChannel();
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'type' => 'system', 'body' => 'pangolin joined the server']);

    expect(searchMessages($owner, 'pangolin'))->toBeEmpty();
});

it('hides a private channel from channel search but shows it to its members', function () {
    [, $member, $server] = twoMembers();
    Channel::factory()->create(['server_id' => $server->id, 'name' => 'pangolins', 'is_private' => true]);

    Passport::actingAs($member);
    $names = fn () => collect(test()->getJson('/api/search?q=pangolin&type=channels')->assertOk()->json('data'))->pluck('name')->all();

    expect($names())->toBeEmpty();

    Channel::where('name', 'pangolins')->first()->allowedMembers()->attach($member->id);
    expect($names())->toBe(['pangolins']);
});

it('finds a DM by the other person\'s name, and never by your own', function () {
    [$a, $b] = dmBetween();
    $b->update(['name' => 'Pangolin Pat']);

    Passport::actingAs($a);
    $rows = $this->getJson('/api/search?q=pangolin&type=conversations')->assertOk()->json('data');

    // A DM has no name of its own — matching only the `name` column would find nothing here.
    expect($rows)->toHaveCount(1);

    // Your own name is in every chat you're in, so matching it would return all of them.
    $a->update(['name' => 'Pangolin Alex']);
    Passport::actingAs($a);
    expect($this->getJson('/api/search?q=Alex&type=conversations')->json('data'))->toBeEmpty();
});

it('finds a group chat by its name, and only for its members', function () {
    [$a, $b] = twoMembers();
    Conversation::factory()->group('pangolin appreciation')->withMembers([$a, $b])->create();
    $outsider = User::factory()->create();

    Passport::actingAs($a);
    expect($this->getJson('/api/search?q=pangolin&type=conversations')->json('data'))->toHaveCount(1);

    Passport::actingAs($outsider);
    expect($this->getJson('/api/search?q=pangolin&type=conversations')->json('data'))->toBeEmpty();
});

it('finds your servers by name but somebody else\'s only by its exact invite code', function () {
    $stranger = User::factory()->create();
    [, $server] = ownerWithServer();
    $server->update(['name' => 'Pangolin HQ']);

    Passport::actingAs($stranger);

    // Substring-matching every server would turn this box into a directory of every
    // private group in the app, findable by guessing at their names.
    expect($this->getJson('/api/search?q=pangolin&type=servers')->json('data'))->toBeEmpty();

    // The code is the thing you were given precisely so you could find it.
    expect($this->getJson('/api/search?q='.$server->invite_code.'&type=servers')->json('data'))->toHaveCount(1);
});

it('groups a bit of everything for the command palette', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $server->update(['name' => 'Pangolin HQ']);
    $channel->update(['name' => 'pangolin-talk']);
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'pangolin sighted']);

    Passport::actingAs($owner);
    $data = $this->getJson('/api/search?q=pangolin')->assertOk()->json('data');

    expect($data['servers'])->toHaveCount(1)
        ->and($data['channels'])->toHaveCount(1)
        ->and($data['messages'])->toHaveCount(1)
        ->and($data['conversations'])->toBeEmpty();
});

it('filters messages by author, date and attachment', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    $mine = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'pangolin one']);
    $theirs = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $member->id, 'body' => 'pangolin two']);
    Attachment::factory()->create(['message_id' => $theirs->id, 'mime_type' => 'image/png']);

    expect(searchMessages($owner, 'pangolin'))->toHaveCount(2)
        ->and(collect(searchMessages($owner, 'pangolin', ['from' => $member->id]))->pluck('id')->all())->toBe([$theirs->id])
        ->and(collect(searchMessages($owner, 'pangolin', ['has' => 'image']))->pluck('id')->all())->toBe([$theirs->id]);

    // Dates bracket the whole fixture, so both fall inside and neither falls outside.
    expect(searchMessages($owner, 'pangolin', ['after' => now()->subDay()->toDateString()]))->toHaveCount(2)
        ->and(searchMessages($owner, 'pangolin', ['before' => now()->subDay()->toDateString()]))->toBeEmpty();

    $mine->delete();
});

it('rejects a search with no term', function () {
    [$owner] = ownerWithServer();
    Passport::actingAs($owner);

    $this->getJson('/api/search?q=')->assertStatus(422);
});
