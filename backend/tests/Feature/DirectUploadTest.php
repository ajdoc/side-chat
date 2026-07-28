<?php

use App\Models\Attachment;
use App\Models\ChunkedUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

/**
 * The direct-to-bucket upload path — what runs anywhere attachments are stored on an object
 * store rather than a local disk.
 *
 * The shape being protected here is that the application never sees the bytes: it signs a URL,
 * the browser PUTs to it, and the application is told after the fact. Everything it can still
 * be lied to about — whether anything arrived, and how big it was — has to be checked when the
 * client claims to be finished, which is most of what these cover.
 */

/** Point the app at a bucket. No network is involved: signing happens locally. */
function useBucketDisk(): void
{
    config()->set('uploads.disk', 's3');
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'auto',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://accountid.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => false,
    ]);
}

/** Open an upload, as the composer does before sending any bytes. */
function openUpload(int $size = 12, string $name = 'holiday.mp4'): TestResponse
{
    return test()->postJson('/api/uploads', [
        'name' => $name,
        'size' => $size,
        'mime_type' => 'video/mp4',
        'total_chunks' => 1,
    ]);
}

it('hands the browser a signed url instead of taking the bytes, when storing on a bucket', function () {
    [$user] = ownerWithChannel();
    Passport::actingAs($user);
    useBucketDisk();

    $res = openUpload()->assertCreated();

    expect($res->json('data.mode'))->toBe('direct')
        ->and($res->json('data.url'))->toContain('test-bucket')
        // A signature is the entire grant — without one the URL is not an upload permit.
        ->and($res->json('data.url'))->toContain('X-Amz-Signature');
});

it('still takes chunks when storing on a local disk', function () {
    [$user] = ownerWithChannel();
    Passport::actingAs($user);

    $res = openUpload()->assertCreated();

    expect($res->json('data.mode'))->toBe('chunked')
        ->and($res->json('data.url'))->toBeNull();
});

it('marks a direct upload complete once the object is really there', function () {
    [$user] = ownerWithChannel();
    Passport::actingAs($user);
    useBucketDisk();
    $disk = Storage::fake('s3');

    $id = openUpload(size: 12)->assertCreated()->json('data.id');
    $upload = ChunkedUpload::where('uuid', $id)->sole();

    // Stand in for the browser's PUT, which never touches this application.
    $disk->put($upload->path, 'twelve bytes');

    $this->postJson("/api/uploads/{$id}/complete")->assertOk()
        ->assertJsonPath('data.completed', true);

    expect($upload->fresh()->completed_at)->not->toBeNull();
});

it('refuses to complete an upload whose object never arrived', function () {
    [$user] = ownerWithChannel();
    Passport::actingAs($user);
    useBucketDisk();
    Storage::fake('s3');

    $id = openUpload()->assertCreated()->json('data.id');

    $this->postJson("/api/uploads/{$id}/complete")->assertStatus(422);

    // The row survives a failed claim, so the client may retry the PUT rather than start over.
    expect(ChunkedUpload::where('uuid', $id)->exists())->toBeTrue();
});

it('bins an upload that put more bytes than it declared', function () {
    [$user] = ownerWithChannel();
    Passport::actingAs($user);
    useBucketDisk();
    $disk = Storage::fake('s3');

    $id = openUpload(size: 5)->assertCreated()->json('data.id');
    $upload = ChunkedUpload::where('uuid', $id)->sole();
    $path = $upload->path;

    // A signed URL constrains the key, not the length — so this is what a client ignoring the
    // size it declared would actually manage to write.
    $disk->put($path, str_repeat('x', 5000));

    $this->postJson("/api/uploads/{$id}/complete")->assertStatus(422);

    expect(ChunkedUpload::where('uuid', $id)->exists())->toBeFalse();
    $disk->assertMissing($path);
});

it('turns a completed direct upload into an attachment on the message that claims it', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);
    useBucketDisk();
    $disk = Storage::fake('s3');

    $id = openUpload(size: 12)->assertCreated()->json('data.id');
    $upload = ChunkedUpload::where('uuid', $id)->sole();
    $staged = $upload->path;
    $disk->put($staged, 'twelve bytes');
    $this->postJson("/api/uploads/{$id}/complete")->assertOk();

    $this->postJson("/api/channels/{$channel->id}/messages", ['uploads' => [$id]])->assertCreated();

    $attachment = Attachment::sole();
    expect($attachment->disk)->toBe('s3')
        ->and($attachment->name)->toBe('holiday.mp4')
        // Claimed by moving, not copying — the staging key must not survive as a second copy.
        ->and($attachment->path)->toStartWith("attachments/{$channel->id}/");
    $disk->assertExists($attachment->path);
    $disk->assertMissing($staged);
    expect(ChunkedUpload::count())->toBe(0);
});

it('serves a bucket-stored attachment by redirecting to the store, not by proxying it', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);
    useBucketDisk();
    $disk = Storage::fake('s3');

    $id = openUpload(size: 12)->assertCreated()->json('data.id');
    $disk->put(ChunkedUpload::where('uuid', $id)->sole()->path, 'twelve bytes');
    $this->postJson("/api/uploads/{$id}/complete")->assertOk();
    $this->postJson("/api/channels/{$channel->id}/messages", ['uploads' => [$id]])->assertCreated();

    $res = $this->get(Attachment::sole()->url());

    // The bytes come from the bucket; this application only says where to get them.
    $res->assertRedirect();
});

it('refuses chunks for an upload that was given a signed url', function () {
    [$user] = ownerWithChannel();
    Passport::actingAs($user);
    useBucketDisk();
    Storage::fake('s3');

    $id = openUpload()->assertCreated()->json('data.id');

    $this->post("/api/uploads/{$id}/chunks", [
        'index' => 0,
        'chunk' => UploadedFile::fake()->createWithContent('chunk', 'bytes'),
    ])->assertStatus(409);
});

it('lets the uploader bin a staged upload, which carries no body at all', function () {
    [$user] = ownerWithChannel();
    Passport::actingAs($user);

    $id = openUpload()->assertCreated()->json('data.id');

    $this->deleteJson("/api/uploads/{$id}")->assertNoContent();

    expect(ChunkedUpload::where('uuid', $id)->exists())->toBeFalse();
});

it('will not let one user finish or bin another user\'s upload', function () {
    [$owner] = ownerWithChannel();
    [$stranger] = ownerWithChannel();

    Passport::actingAs($owner);
    useBucketDisk();
    Storage::fake('s3');
    $id = openUpload()->assertCreated()->json('data.id');

    Passport::actingAs($stranger);
    $this->postJson("/api/uploads/{$id}/complete")->assertForbidden();
    $this->deleteJson("/api/uploads/{$id}")->assertForbidden();
});
