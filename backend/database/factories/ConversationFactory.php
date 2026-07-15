<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'dm',
            'name' => null,
            'owner_id' => null,
        ];
    }

    public function group(?string $name = null): static
    {
        return $this->state(fn () => [
            'type' => 'group',
            'name' => $name ?? fake()->words(2, true),
        ]);
    }

    /**
     * A DM between two people, wired up the way the app would have made it: both members
     * attached, the dm_key set, and — crucially — the channel that everything else in the
     * app actually talks to.
     *
     * @param  array<int, User>  $members
     */
    public function withMembers(array $members): static
    {
        return $this->afterCreating(function (Conversation $conversation) use ($members): void {
            $conversation->members()->attach(collect($members)->pluck('id')->unique()->all());

            if ($conversation->isDm() && count($members) === 2) {
                $conversation->update([
                    'dm_key' => Conversation::dmKey($members[0]->id, $members[1]->id),
                ]);
            }

            $conversation->channel()->create([
                'name' => $conversation->isGroup() ? 'group' : 'direct',
                'type' => 'text',
            ]);
        });
    }
}
