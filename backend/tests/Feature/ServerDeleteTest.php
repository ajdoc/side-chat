<?php

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerJoinRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

beforeEach(function () {
    Storage::fake('local');
});

it('deletes a server with every channel, message and file in it', function () {
    [$owner, $server, $first] = ownerWithChannel();
    $second = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);

    // Files in *both* channels: the recursive part is that neither directory survives.
    foreach ([$first, $second] as $channel) {
        $this->post("/api/channels/{$channel->id}/messages", [
            'body' => 'a file',
            'attachments' => [UploadedFile::fake()->create('doc.png', 20)],
        ])->assertCreated();
    }

    $paths = Attachment::pluck('path');
    expect($paths)->toHaveCount(2);

    $this->deleteJson("/api/servers/{$server->id}")->assertNoContent();

    expect(Server::find($server->id))->toBeNull()
        ->and(Channel::where('server_id', $server->id)->count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(Attachment::count())->toBe(0);

    $paths->each(fn ($path) => Storage::disk('local')->assertMissing($path));
    foreach ([$first, $second] as $channel) {
        expect(Storage::disk('local')->exists("attachments/{$channel->id}"))->toBeFalse();
    }
});

it('deletes memberships and pending join requests with the server', function () {
    [$owner, $server] = ownerWithServer();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    ServerJoinRequest::factory()->create([
        'server_id' => $server->id,
        'user_id' => User::factory()->create()->id,
    ]);

    Passport::actingAs($owner);
    $this->deleteJson("/api/servers/{$server->id}")->assertNoContent();

    expect(DB::table('server_user')->where('server_id', $server->id)->count())->toBe(0)
        ->and(ServerJoinRequest::where('server_id', $server->id)->count())->toBe(0)
        // The people themselves obviously survive their server.
        ->and(User::whereIn('id', [$owner->id, $member->id])->count())->toBe(2);
});

it('drops the server from the rail of everyone who was in it', function () {
    [$owner, $server] = ownerWithServer();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($owner);
    $this->deleteJson("/api/servers/{$server->id}")->assertNoContent();

    Passport::actingAs($member);
    $this->getJson('/api/servers')->assertOk()->assertJsonCount(0, 'data');
});

it('lets only the owner delete a server', function () {
    [, $server] = ownerWithServer();

    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Passport::actingAs($member);
    $this->deleteJson("/api/servers/{$server->id}")->assertForbidden();

    Passport::actingAs(User::factory()->create());
    $this->deleteJson("/api/servers/{$server->id}")->assertForbidden();

    expect(Server::find($server->id))->not->toBeNull();
});
