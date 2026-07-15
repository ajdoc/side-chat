<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\VoiceParticipant>
 */
class VoiceParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory()->state(['type' => 'voice']),
            'user_id' => User::factory(),
            'muted' => false,
            'deafened' => false,
            'screen_sharing' => false,
            'last_seen_at' => now(),
        ];
    }

    /** A row left behind by a tab that died without saying goodbye. */
    public function stale(): static
    {
        return $this->state(fn () => [
            'last_seen_at' => now()->subSeconds((int) config('webrtc.stale_after_seconds') + 30),
        ]);
    }
}
