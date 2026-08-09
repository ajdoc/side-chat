<?php

namespace Database\Factories;

use App\Models\DeviceKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceKey>
 *
 * The key material is random base64, not real P-256 points. That is not laziness: the server
 * never parses these, and a test that generated genuine keys would be testing WebCrypto in
 * PHP rather than testing the directory. The properties worth asserting here are all about
 * *who may fetch what*, and those hold whatever the bytes are.
 */
class DeviceKeyFactory extends Factory
{
    protected $model = DeviceKey::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => Str::random(16),
            'identity_public' => base64_encode(random_bytes(65)),
            'signing_public' => base64_encode(random_bytes(65)),
            'signed_prekey' => base64_encode(random_bytes(65)),
            'prekey_signature' => base64_encode(random_bytes(64)),
            'last_seen_at' => now(),
        ];
    }

    /** With a stock of single-use prekeys, the way a freshly registered device arrives. */
    public function withPrekeys(int $count = 3): static
    {
        return $this->afterCreating(function (DeviceKey $device) use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $device->oneTimePrekeys()->create([
                    'prekey_id' => "otp-{$i}",
                    'public_key' => base64_encode(random_bytes(65)),
                ]);
            }
        });
    }
}
