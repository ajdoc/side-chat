<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Server;
use App\Models\User;
use App\Support\SideSpace\MapPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
 * Hard guard: never let the suite touch a non-testing database (RefreshDatabase
 * drops every table).
 */
beforeEach(function () {
    $db = config('database.connections.'.config('database.default').'.database');

    if (! str_contains((string) $db, 'testing')) {
        throw new RuntimeException("Refusing to run tests against database [{$db}] - expected the *_testing database.");
    }
});


/**
 * A user who owns (and is a member of) a fresh server.
 *
 * @return array{0: User, 1: Server}
 */
function ownerWithServer(): array
{
    $user = User::factory()->create();
    $server = Server::factory()->create(['owner_id' => $user->id]);
    $server->members()->attach($user->id, ['role' => 'owner']);

    return [$user, $server];
}

/**
 * A user, their server, and a text channel in it.
 *
 * @return array{0: User, 1: Server, 2: Channel}
 */
function ownerWithChannel(): array
{
    [$user, $server] = ownerWithServer();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    return [$user, $server, $channel];
}

/**
 * A user, their server, and a *voice* channel in it.
 *
 * @return array{0: User, 1: Server, 2: Channel}
 */
function ownerWithVoiceChannel(): array
{
    [$user, $server] = ownerWithServer();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);

    return [$user, $server, $channel];
}

/**
 * A user, their server, and a *Side Space* channel in it with a map already seeded.
 *
 * Seeded through the real preset so the map under test is the one people actually get, rather
 * than a hand-rolled grid that might satisfy rules the presets don't.
 *
 * @return array{0: User, 1: Server, 2: Channel}
 */
function ownerWithSpaceChannel(string $preset = 'office'): array
{
    [$user, $server] = ownerWithServer();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'type' => 'space']);
    $map = MapPresets::find($preset);

    $channel->spaceMap()->create([
        'name' => $map['name'],
        'width' => $map['width'],
        'height' => $map['height'],
        'tiles' => $map['tiles'],
        'zones' => $map['zones'],
        'objects' => $map['objects'],
        'spawn' => $map['spawn'],
    ]);

    return [$user, $server, $channel];
}

/**
 * Two people who share a server — the precondition for being allowed to chat at all.
 *
 * @return array{0: User, 1: User, 2: Server}
 */
function twoMembers(): array
{
    [$owner, $server] = ownerWithServer();
    $other = User::factory()->create();
    $server->members()->attach($other->id, ['role' => 'member']);

    return [$owner, $other, $server];
}

/**
 * An open DM between two people who share a server, with its channel.
 *
 * @return array{0: User, 1: User, 2: Conversation}
 */
function dmBetween(): array
{
    [$a, $b] = twoMembers();

    $conversation = Conversation::factory()->withMembers([$a, $b])->create();

    return [$a, $b, $conversation->load('members', 'channel')];
}

/**
 * A real, tiny PNG as an uploaded file.
 *
 * Not `UploadedFile::fake()->image()`, which needs GD — this image has no GD extension. And a
 * `fake()->create()` with an image MIME wouldn't do either: the `image` validation rule inspects
 * the file's actual *contents*, which is exactly the property worth keeping, since it is what
 * stops the upload endpoint becoming a way to store arbitrary bytes under a name ending in .png.
 *
 * So these are real PNG bytes — 1x1, transparent, sixty-odd of them.
 */
function fakeImageUpload(string $name = 'artwork.png'): \Illuminate\Http\UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $path = tempnam(sys_get_temp_dir(), 'exhibit').'.png';
    file_put_contents($path, $png);

    // `test: true` skips the is_uploaded_file() check, which only ever passes for a real request.
    return new \Illuminate\Http\UploadedFile($path, $name, 'image/png', null, true);
}
