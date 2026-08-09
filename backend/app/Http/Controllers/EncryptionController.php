<?php

namespace App\Http\Controllers;

use App\Actions\Channel\ToggleChannelEncryptionAction;
use App\Events\SenderKeysDistributed;
use App\Http\Requests\Channel\ToggleEncryptionRequest;
use App\Http\Requests\Encryption\ChannelDeviceRequest;
use App\Http\Requests\Encryption\DistributeSenderKeyRequest;
use App\Http\Requests\Encryption\RegisterDeviceRequest;
use App\Http\Requests\Encryption\StoreKeyBackupRequest;
use App\Http\Requests\Encryption\StorePrekeysRequest;
use App\Http\Requests\Encryption\ViewIdentitiesRequest;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\DeviceKey;
use App\Models\KeyBackup;
use App\Services\EncryptionKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The encryption switch, and the key directory behind it.
 *
 * Two jobs that belong together because they are one feature, and stay separable because
 * they answer different questions: the toggle decides whether a channel is encrypted, and
 * everything below it moves *public* key material between devices so that it can be.
 *
 * Nothing here can read a message. The bundles are public halves, the sender keys are sealed
 * blobs, and the server is a post box for both. Worth re-reading whenever this file grows.
 */
class EncryptionController extends Controller
{
    public function __construct(private readonly EncryptionKeyService $keys) {}

    public function toggle(ToggleEncryptionRequest $request, Channel $channel, ToggleChannelEncryptionAction $action): ChannelResource
    {
        return new ChannelResource(
            $action->handle($channel, $request->user(), $request->boolean('encrypted'))
        );
    }

    /**
     * Publish (or refresh) this device's public keys.
     *
     * Called on every launch, not just the first: it rotates the signed prekey and doubles as
     * the "this device still exists" ping. Answers with the current prekey stock so the
     * client knows whether to top up without a second round trip.
     */
    public function registerDevice(RegisterDeviceRequest $request): JsonResponse
    {
        $device = $this->keys->registerDevice($request->user(), $request->validated());

        return response()->json([
            'data' => [
                'device_key_id' => $device->id,
                'device_id' => $device->device_id,
                'one_time_prekeys' => $device->oneTimePrekeys()->count(),
                'prekey_target' => EncryptionKeyService::PREKEY_TARGET,
            ],
        ]);
    }

    /** Refill the single-use prekeys. Idempotent per prekey id, so a retry is safe. */
    public function storePrekeys(StorePrekeysRequest $request): JsonResponse
    {
        $device = $this->ownDevice($request->user()->id, $request->string('device_id'));

        $this->keys->storePrekeys($device, $request->validated('one_time_prekeys'));

        return response()->json([
            'data' => ['one_time_prekeys' => $device->oneTimePrekeys()->count()],
        ]);
    }

    /**
     * Every other device in this channel, as bundles ready to start sessions with.
     *
     * A POST despite reading like a GET, because it has side effects: each bundle it returns
     * consumes a one-time prekey from that device's stock. Naming that honestly in the method
     * is worth more than the tidiness of a GET, mostly so nobody later decides it is safe to
     * cache or to prefetch.
     */
    public function bundles(ChannelDeviceRequest $request, Channel $channel): JsonResponse
    {
        $device = $this->ownDevice($request->user()->id, $request->string('device_id'));

        return response()->json([
            // `device_key_ids` narrows it to the devices the caller knows it hasn't reached
            // yet. Omitted means everyone, which is what a brand-new chain wants.
            'data' => $this->keys->bundlesFor($channel, $device, $request->input('device_key_ids', [])),
        ]);
    }

    /**
     * The identity keys published by everyone in this channel.
     *
     * A GET, unlike {@see bundles()} directly above it, and the difference is the point:
     * this one consumes nothing. It exists so the verification screen can compute safety
     * numbers without spending a one-time prekey every time somebody opens it.
     */
    public function identities(ViewIdentitiesRequest $request, Channel $channel): JsonResponse
    {
        return response()->json(['data' => $this->keys->identitiesFor($channel)]);
    }

    /** Store one wrapped sender key per recipient device, for one era of one channel. */
    public function distribute(DistributeSenderKeyRequest $request, Channel $channel): JsonResponse
    {
        $device = $this->ownDevice($request->user()->id, $request->string('device_id'));

        $epoch = $request->integer('epoch');

        $stored = $this->keys->distribute($channel, $device, $epoch, $request->validated('keys'));

        // Tell the room to check its post box. Nothing readable rides on the event — see
        // SenderKeysDistributed — but without it a client with the channel already open would
        // sit there unable to read a conversation whose keys are waiting for it.
        if ($stored > 0) {
            broadcast(new SenderKeysDistributed($channel, $epoch));
        }

        return response()->json(['data' => ['stored' => $stored]]);
    }

    /** Everything addressed to this device in this channel, across every era. */
    public function inbox(ChannelDeviceRequest $request, Channel $channel): JsonResponse
    {
        $device = $this->ownDevice($request->user()->id, $request->string('device_id'));

        return response()->json(['data' => $this->keys->inboxFor($channel, $device)]);
    }

    /**
     * Store (or replace) this account's wrapped key backup.
     *
     * A snapshot, not a log: one row per account, overwritten each time. Keeping previous
     * blobs would mean keeping chains somebody has since had every reason to believe were
     * gone — and each old blob is another chance for a weaker passphrase to still be valid.
     */
    public function storeBackup(StoreKeyBackupRequest $request): JsonResponse
    {
        $backup = KeyBackup::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->safe()->only(['blob', 'kdf', 'iterations']),
        );

        return response()->json(['data' => ['updated_at' => $backup->updated_at]]);
    }

    /**
     * Fetch this account's backup, to restore on a new device.
     *
     * 404 when there isn't one, which is the ordinary answer for anybody who opted out of
     * escrow — not an error, and the client draws "no backup stored" rather than a failure.
     */
    public function showBackup(Request $request): JsonResponse
    {
        $backup = KeyBackup::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'data' => [
                'blob' => $backup->blob,
                'kdf' => $backup->kdf,
                'iterations' => $backup->iterations,
                'updated_at' => $backup->updated_at,
            ],
        ]);
    }

    /**
     * Delete the backup — opting out after having opted in.
     *
     * Genuinely destructive and deliberately not softened: once this row is gone, a device
     * that hasn't already got the chains cannot get them, and that history is unreadable on
     * any new machine forever. The client asks twice; this just does it.
     */
    public function destroyBackup(Request $request): Response
    {
        KeyBackup::where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    /**
     * The caller's own device, by the id they claim to be using.
     *
     * Scoped to the authenticated account, so naming somebody else's device is a 404 rather
     * than a way to act as them. This is the single check standing between "which device am
     * I" and "which device would I like to be", and every endpoint above goes through it.
     */
    private function ownDevice(int $userId, string $deviceId): DeviceKey
    {
        return DeviceKey::query()
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->firstOrFail();
    }
}
