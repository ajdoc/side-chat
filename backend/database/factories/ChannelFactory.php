<?php

namespace Database\Factories;

use App\Actions\Channel\CreateChannelAction;
use App\Models\Channel;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => fake()->unique()->word(),
            'type' => 'text',
            'position' => 0,
        ];
    }

    public function voice(): static
    {
        return $this->state(fn () => ['type' => 'voice']);
    }

    /**
     * Every container gets its General discussion, the way {@see CreateChannelAction}
     * gives one to every channel made through the API. A factory channel without one is a
     * shape the application never produces, and tests built on it would be testing a channel
     * that cannot exist.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Channel $channel) {
            if ($channel->isDiscussion()) {
                return;
            }

            $channel->discussions()->create([
                'server_id' => $channel->server_id,
                'conversation_id' => $channel->conversation_id,
                'name' => 'General',
                'type' => $channel->type,
                'position' => 0,
            ]);
        });
    }

    /** A discussion of the given container, rather than a container of its own. */
    public function discussionOf(Channel $parent): static
    {
        return $this->state(fn () => [
            'server_id' => $parent->server_id,
            'conversation_id' => $parent->conversation_id,
            'parent_id' => $parent->id,
            'type' => $parent->type,
        ]);
    }
}
