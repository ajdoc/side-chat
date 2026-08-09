<?php

namespace Database\Factories;

use App\Models\DeviceKey;
use App\Models\OneTimePrekey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OneTimePrekey> */
class OneTimePrekeyFactory extends Factory
{
    protected $model = OneTimePrekey::class;

    public function definition(): array
    {
        return [
            'device_key_id' => DeviceKey::factory(),
            'prekey_id' => Str::random(12),
            'public_key' => base64_encode(random_bytes(65)),
        ];
    }
}
