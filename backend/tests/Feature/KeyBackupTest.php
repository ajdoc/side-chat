<?php

use App\Http\Requests\Encryption\StoreKeyBackupRequest;
use App\Models\KeyBackup;
use App\Models\User;
use Laravel\Passport\Passport;

/*
 * Escrowed key backups.
 *
 * The server's role here is to hold a blob it cannot read and give it back to exactly one
 * account, so the tests are almost entirely about *who* — plus the one substantive rule it
 * does enforce, which is refusing a backup wrapped with a KDF cheap enough to brute-force.
 *
 * The cryptography is proven in the frontend's backup.spec.ts against real WebCrypto. Nothing
 * here decrypts anything, because nothing here can.
 */

/** The payload a client sends. The blob is opaque, so random bytes are as good as real ones. */
function backupPayload(array $overrides = []): array
{
    return [
        'blob' => base64_encode(random_bytes(256)),
        'kdf' => 'PBKDF2-SHA256',
        'iterations' => 600000,
        ...$overrides,
    ];
}

it('stores a backup and hands it back to its owner', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $payload = backupPayload();

    $this->putJson('/api/encryption/backup', $payload)->assertOk();

    $this->getJson('/api/encryption/backup')
        ->assertOk()
        ->assertJsonPath('data.blob', $payload['blob'])
        // Echoed back untouched: the client wrote itself a note about how to derive the key,
        // and a backup from years ago has to open after the parameters have moved on.
        ->assertJsonPath('data.kdf', 'PBKDF2-SHA256')
        ->assertJsonPath('data.iterations', 600000);
});

it('replaces the backup rather than keeping the old one', function () {
    // A snapshot, not a log. Every retained blob is another chance for a passphrase somebody
    // has since changed to still unlock their history.
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->putJson('/api/encryption/backup', backupPayload())->assertOk();
    $second = backupPayload();
    $this->putJson('/api/encryption/backup', $second)->assertOk();

    expect(KeyBackup::where('user_id', $user->id)->count())->toBe(1);
    $this->getJson('/api/encryption/backup')->assertJsonPath('data.blob', $second['blob']);
});

it('never hands one account’s backup to another', function () {
    // The whole of escrow rests on this. The blob is useless without the passphrase, but a
    // backup anybody can fetch is a backup anybody can attack offline at their leisure.
    $owner = User::factory()->create();
    Passport::actingAs($owner);
    $this->putJson('/api/encryption/backup', backupPayload())->assertOk();

    Passport::actingAs(User::factory()->create());
    $this->getJson('/api/encryption/backup')->assertNotFound();
});

it('answers "no backup" rather than an error for somebody who opted out', function () {
    // Opting out means simply never storing one, so this is the ordinary state of an account
    // that chose a recovery file — the client draws "no backup stored", not a failure.
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/encryption/backup')->assertNotFound();
});

it('refuses a backup wrapped with a weak KDF', function () {
    // Not protecting the server — protecting the person from a client that puts their whole
    // history behind work an attacker can redo in an afternoon. Once stored, that is fixed.
    Passport::actingAs(User::factory()->create());

    $this->putJson('/api/encryption/backup', backupPayload(['iterations' => 1000]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('iterations');

    expect(KeyBackup::count())->toBe(0);
});

it('accepts exactly the documented minimum', function () {
    Passport::actingAs(User::factory()->create());

    $this->putJson('/api/encryption/backup', backupPayload([
        'iterations' => StoreKeyBackupRequest::MINIMUM_ITERATIONS,
    ]))->assertOk();
});

it('refuses a KDF it has never heard of', function () {
    // An unknown algorithm name is a client this server can't reason about at all — including
    // about whether its iteration count means anything.
    Passport::actingAs(User::factory()->create());

    $this->putJson('/api/encryption/backup', backupPayload(['kdf' => 'MD5-ONCE']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('kdf');
});

it('deletes the backup on request, and only the caller’s', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    Passport::actingAs($other);
    $this->putJson('/api/encryption/backup', backupPayload())->assertOk();

    Passport::actingAs($owner);
    $this->putJson('/api/encryption/backup', backupPayload())->assertOk();
    $this->deleteJson('/api/encryption/backup')->assertNoContent();

    expect(KeyBackup::where('user_id', $owner->id)->exists())->toBeFalse()
        ->and(KeyBackup::where('user_id', $other->id)->exists())->toBeTrue();
});

it('takes the backup with the account', function () {
    // A deleted account leaves no wrapped history behind to be attacked later.
    $user = User::factory()->create();
    Passport::actingAs($user);
    $this->putJson('/api/encryption/backup', backupPayload())->assertOk();

    $user->delete();

    expect(KeyBackup::where('user_id', $user->id)->exists())->toBeFalse();
});

it('turns away an anonymous caller', function () {
    $this->getJson('/api/encryption/backup')->assertUnauthorized();
    $this->putJson('/api/encryption/backup', backupPayload())->assertUnauthorized();
});
