<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\DeviceKey;
use App\Models\SenderKey;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The key directory: registering devices, handing out bundles, moving wrapped sender keys.
 *
 * Everything this service touches is either public or sealed. That is worth stating plainly
 * because the class name suggests otherwise — there is no key material here the server could
 * use to read a message, and the day somebody adds a method that decrypts something is the
 * day the feature stops being end-to-end.
 *
 * The rule that shapes most of it: **you may only ask about people you already share a
 * channel with**. Prekeys are a limited resource that anybody can drain by asking, and an
 * open directory would also answer "how many devices does this person have, and when were
 * they last online" for any account on the server. Both are answered here only within a
 * channel the caller is in, which they could learn most of by watching the timeline anyway.
 */
final class EncryptionKeyService
{
    /** How many single-use prekeys a client should keep in stock. */
    public const PREKEY_TARGET = 100;

    /**
     * Register a device, or update the one already there.
     *
     * Idempotent on `device_id`, because a client calls this on every launch: it is how a
     * device rotates its signed prekey and says it is still alive. Re-registering with new
     * keys is a legitimate, ordinary thing.
     *
     * What it deliberately does not do is treat a changed identity key as routine. It is
     * allowed — a reinstalled app has genuinely new keys — but it invalidates every session
     * anybody had with this device, and their clients will see the safety number change. That
     * is exactly the signal a person needs to notice an impostor, so it must be visible
     * rather than smoothed over.
     */
    public function registerDevice(User $user, array $attributes): DeviceKey
    {
        return DB::transaction(function () use ($user, $attributes) {
            $device = DeviceKey::updateOrCreate(
                ['user_id' => $user->id, 'device_id' => $attributes['device_id']],
                [
                    'identity_public' => $attributes['identity_public'],
                    'signing_public' => $attributes['signing_public'],
                    'signed_prekey' => $attributes['signed_prekey'],
                    'prekey_signature' => $attributes['prekey_signature'],
                    'last_seen_at' => now(),
                ],
            );

            if ($attributes['one_time_prekeys'] ?? []) {
                $this->storePrekeys($device, $attributes['one_time_prekeys']);
            }

            return $device;
        });
    }

    /**
     * Add single-use prekeys to a device's stock.
     *
     * `upsert` rather than insert so a client retrying a failed top-up doesn't collide with
     * the half that got through. Re-sending the same prekey id is a no-op, which is what a
     * retry should be.
     */
    public function storePrekeys(DeviceKey $device, array $prekeys): void
    {
        $rows = collect($prekeys)->map(fn (array $prekey) => [
            'device_key_id' => $device->id,
            'prekey_id' => $prekey['prekey_id'],
            'public_key' => $prekey['public_key'],
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('one_time_prekeys')->upsert($rows, ['device_key_id', 'prekey_id'], ['public_key']);
        }
    }

    /**
     * Every device in a channel, as bundles ready to start sessions with.
     *
     * Claims a one-time prekey per device as it goes, which is why this is a POST-shaped
     * operation wearing a GET's clothes: asking consumes something. That is inherent to
     * X3DH — the whole point of a one-time prekey is that fetching it removes it — and it is
     * why the endpoint is gated on channel membership rather than being a public directory.
     *
     * The caller's own devices are included, minus the one asking. A person's laptop must
     * distribute its sender key to their own phone exactly as it would to anybody else's,
     * because from the protocol's point of view it *is* anybody else.
     */
    public function bundlesFor(Channel $channel, DeviceKey $asking, array $only = []): Collection
    {
        $memberIds = $this->memberIds($channel);

        return DeviceKey::query()
            ->whereIn('user_id', $memberIds)
            ->where('id', '!=', $asking->id)
            // Narrowed to specific devices when the caller already knows which ones it is
            // missing — a sender catching up with a device that joined after its chain was
            // created. Without the filter it would re-fetch every bundle and burn a one-time
            // prekey on every device in the channel to reach the one new one.
            ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
            ->get()
            ->map(function (DeviceKey $device) {
                $prekey = $device->claimPrekey();

                return [
                    // The row id, which is how a distribution addresses this device. The
                    // client-facing `device_id` is only unique per account, so it can't be.
                    'device_key_id' => $device->id,
                    'user_id' => $device->user_id,
                    'device_id' => $device->device_id,
                    'identity_public' => $device->identity_public,
                    'signing_public' => $device->signing_public,
                    'signed_prekey' => $device->signed_prekey,
                    'prekey_signature' => $device->prekey_signature,
                    // Absent when the stock ran dry. The client starts a session without one
                    // rather than failing — see the one_time_prekeys migration.
                    'one_time_prekey' => $prekey?->public_key,
                    'one_time_prekey_id' => $prekey?->prekey_id,
                ];
            })
            ->values();
    }

    /**
     * The identity keys of everyone in a channel — read-only, and consuming nothing.
     *
     * Deliberately separate from {@see bundlesFor()}, which looks similar and is not: fetching
     * a bundle *spends* a one-time prekey, so pointing the verification screen at it would
     * drain everybody's stock every time somebody glanced at a safety number. This answers the
     * only question that screen asks — "which identity keys does this person's account
     * publish" — and touches nothing.
     *
     * The caller's own devices are included. Comparing your laptop against your phone is a
     * reasonable thing to want to do, and excluding them would make the list quietly wrong.
     */
    public function identitiesFor(Channel $channel): Collection
    {
        return DeviceKey::query()
            ->whereIn('user_id', $this->memberIds($channel))
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'device_id', 'identity_public', 'last_seen_at'])
            ->map(fn (DeviceKey $device) => [
                // The row id, which is how a distribution is addressed. Carried here so a
                // sender can diff "every device in the channel" against "the ones I've already
                // given my chain to" without a second request.
                'device_key_id' => $device->id,
                'user_id' => $device->user_id,
                'device_id' => $device->device_id,
                'identity_public' => $device->identity_public,
                'last_seen_at' => $device->last_seen_at,
            ])
            ->values();
    }

    /**
     * Store wrapped sender keys, one per recipient device.
     *
     * Every recipient is checked against the channel's membership before its row is written.
     * Without that, a member could address a row to a device outside the channel — harmless
     * in itself, since the blob is sealed to a session that device can't reproduce, but it
     * would let anybody write arbitrary rows into other people's inboxes, and an inbox
     * anybody can write to is a place to hide things.
     *
     * `upsert` on the unique key: redistributing an era to a device that already has it (a
     * client that lost its store, a retry) replaces the row rather than duplicating it.
     */
    public function distribute(Channel $channel, DeviceKey $sender, int $epoch, array $entries): int
    {
        $allowed = DeviceKey::query()
            ->whereIn('user_id', $this->memberIds($channel))
            ->pluck('id', 'id');

        $rows = collect($entries)
            ->filter(fn (array $entry) => $allowed->has((int) $entry['recipient_device_key_id']))
            ->map(fn (array $entry) => [
                'channel_id' => $channel->id,
                'epoch' => $epoch,
                'sender_device_id' => $sender->id,
                'recipient_device_id' => (int) $entry['recipient_device_key_id'],
                'wrapped_key' => $entry['wrapped_key'],
                'wrap_iv' => $entry['wrap_iv'],
                'ephemeral_public' => $entry['ephemeral_public'],
                'prekey_id' => $entry['prekey_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        if ($rows === []) {
            return 0;
        }

        DB::table('sender_keys')->upsert(
            $rows,
            ['channel_id', 'epoch', 'sender_device_id', 'recipient_device_id'],
            ['wrapped_key', 'wrap_iv', 'ephemeral_public', 'prekey_id', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * Which devices still have no sender key from this sender, for this era.
     *
     * The server can answer this and the client cannot, which is the whole point. A sender
     * tracking "who I have already sent to" in its own storage is right until it isn't: a
     * recipient whose copy was destroyed — an interrupted write, a cleared profile, a key it
     * could not open — is still on the sender's list forever, and the sender never sends
     * again. The rows are the truth about what has actually been delivered, and they live
     * here.
     *
     * @return Collection<int, int>
     */
    public function pendingRecipients(Channel $channel, DeviceKey $sender, int $epoch): Collection
    {
        $delivered = SenderKey::query()
            ->where('channel_id', $channel->id)
            ->where('epoch', $epoch)
            ->where('sender_device_id', $sender->id)
            ->pluck('recipient_device_id');

        return DeviceKey::query()
            ->whereIn('user_id', $this->memberIds($channel))
            ->where('id', '!=', $sender->id)
            ->whereNotIn('id', $delivered)
            ->pluck('id');
    }

    /**
     * Throw away a sender key this device cannot open, so it gets sent again.
     *
     * The recipient is the only party that can know a wrapped key is useless to it, and until
     * it says so the row looks delivered to everybody. Deleting it puts that device back on
     * {@see pendingRecipients()}, and the next distribution wraps a fresh copy against a
     * prekey that hasn't been spent.
     *
     * Scoped to rows addressed to the caller's own device: this deletes somebody else's
     * outbound message, and being able to do that for an arbitrary recipient would be a way
     * to cut a third party out of a conversation.
     */
    public function rejectSenderKey(Channel $channel, DeviceKey $recipient, int $epoch, string $senderDeviceId): int
    {
        return SenderKey::query()
            ->where('channel_id', $channel->id)
            ->where('epoch', $epoch)
            ->where('recipient_device_id', $recipient->id)
            ->whereHas('senderDevice', fn ($query) => $query->where('device_id', $senderDeviceId))
            ->delete();
    }

    /**
     * Every sender key addressed to this device in this channel, across all eras.
     *
     * All eras, not just the current one, because a timeline is striped and a client opening
     * a channel for the first time has to read every encrypted era it was a member for. The
     * sender's `device_id` rides along because that is what envelopes are stamped with — the
     * client matches on it, and would otherwise have to look each one up.
     */
    public function inboxFor(Channel $channel, DeviceKey $device): Collection
    {
        return SenderKey::query()
            ->where('channel_id', $channel->id)
            ->where('recipient_device_id', $device->id)
            ->with('senderDevice:id,user_id,device_id,identity_public')
            ->orderBy('epoch')
            ->get()
            ->map(fn (SenderKey $key) => [
                'epoch' => $key->epoch,
                'sender_user_id' => $key->senderDevice?->user_id,
                'sender_device_id' => $key->senderDevice?->device_id,
                'sender_identity_public' => $key->senderDevice?->identity_public,
                'wrapped_key' => $key->wrapped_key,
                'wrap_iv' => $key->wrap_iv,
                'ephemeral_public' => $key->ephemeral_public,
                'prekey_id' => $key->prekey_id,
            ])
            ->values();
    }

    /**
     * Who is in this channel, as user ids.
     *
     * Starts from the container's roster and then filters it through the channel's own gate,
     * rather than stopping at the container the way the member *listing* does. The difference
     * matters here in a way it doesn't there: handing out the keys of a private channel to
     * everybody in the server would give the history to people who cannot even see that the
     * channel exists. {@see Channel::hasMember} is the same rule the rest of the app enforces,
     * so a private channel, a discussion under a private parent and a DM all answer correctly
     * without this method knowing which it is looking at.
     *
     * A per-member call, and knowingly so: rosters are small, this runs once per distribution
     * rather than per message, and reimplementing the access rule inline to save the queries
     * would be a second copy of the one piece of logic that must never drift.
     */
    private function memberIds(Channel $channel): Collection
    {
        $container = $channel->container();

        if ($container === null) {
            return collect();
        }

        return $container->members()
            ->get(['users.id'])
            ->filter(fn (User $user) => $channel->hasMember($user))
            ->pluck('id')
            ->values();
    }
}
