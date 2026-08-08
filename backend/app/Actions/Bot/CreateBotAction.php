<?php

namespace App\Actions\Bot;

use App\DTOs\Bot\CreateBotData;
use App\Models\Bot;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateBotAction
{
    /**
     * Registers a bot on a server: the account it posts as, the registration behind it, and
     * a place on the roster.
     *
     * The membership is the point of the whole transaction. Every read and write in the app
     * is gated on "is this user in this place" (MemberRequest, Channel::hasMember,
     * Channel::scopeVisibleTo), so a bot that isn't a member can't post, can't be
     *
     * @mentioned, and doesn't appear in the member list — it would be a token with nothing
     * behind it. Joining as a plain member also means it inherits the ceiling every member
     * has: no private channel it hasn't been let into, nothing an admin can do.
     *
     * The token is returned rather than stored, and this is the only moment it exists in
     * readable form.
     *
     * A signing secret is minted alongside it, but only if an endpoint was registered:
     * a secret for a webhook that doesn't exist is one more secret to leak for no benefit,
     * and registering the URL later mints one then (see UpdateBotAction).
     *
     * @return array{bot: Bot, token: string, webhook_secret: string|null}
     */
    public function handle(Server $server, User $creator, CreateBotData $data): array
    {
        $token = Bot::generateToken();
        $secret = $data->webhook_url !== null ? Bot::generateWebhookSecret() : null;

        $bot = DB::transaction(function () use ($server, $creator, $data, $token, $secret): Bot {
            $user = User::create([
                'name' => $data->name,
                // Bots don't receive mail, but `users.email` is unique and not null. A
                // reserved, non-routable domain keeps the synthetic address from ever
                // colliding with — or being mistaken for — a person's.
                'email' => 'bot-'.Str::lower(Str::random(20)).'@bots.invalid',
                'password' => null,
                'is_bot' => true,
                'avatar' => $data->avatar,
            ]);

            $server->members()->attach($user->id, ['role' => Server::ROLE_MEMBER]);

            return Bot::create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'created_by' => $creator->id,
                'description' => $data->description,
                'token_hash' => Bot::hashToken($token),
                'webhook_url' => $data->webhook_url,
                'webhook_secret' => $secret,
                'events' => $data->events,
            ]);
        });

        return ['bot' => $bot->load('user', 'creator'), 'token' => $token, 'webhook_secret' => $secret];
    }
}
