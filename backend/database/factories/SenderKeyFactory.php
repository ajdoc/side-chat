<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\DeviceKey;
use App\Models\SenderKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SenderKey> */
class SenderKeyFactory extends Factory
{
    protected $model = SenderKey::class;

    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'epoch' => 1,
            'sender_device_id' => DeviceKey::factory(),
            'recipient_device_id' => DeviceKey::factory(),
            'wrapped_key' => base64_encode(random_bytes(48)),
            'wrap_iv' => base64_encode(random_bytes(12)),
            'ephemeral_public' => base64_encode(random_bytes(65)),
            'prekey_id' => null,
        ];
    }
}
