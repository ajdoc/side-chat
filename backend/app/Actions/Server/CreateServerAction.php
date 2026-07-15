<?php

namespace App\Actions\Server;

use App\DTOs\Server\CreateServerData;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateServerAction
{
    /** Creates the server and makes the creator its owner + first member. */
    public function handle(User $user, CreateServerData $data): Server
    {
        return DB::transaction(function () use ($user, $data): Server {
            $server = Server::create([
                'name' => $data->name,
                'owner_id' => $user->id,
                'invite_code' => Server::generateInviteCode(),
            ]);

            $server->members()->attach($user->id, ['role' => 'owner']);

            return $server;
        });
    }
}
