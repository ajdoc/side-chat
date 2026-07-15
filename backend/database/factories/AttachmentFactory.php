<?php

namespace Database\Factories;

use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Attachment> */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'disk' => 'local',
            'path' => 'attachments/1/'.fake()->uuid().'.png',
            'name' => 'photo.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 2048,
        ];
    }
}
