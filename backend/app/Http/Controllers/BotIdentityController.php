<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChannelResource;
use App\Http\Resources\UserResource;
use App\Models\Bot;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who am I, and where can I post?
 *
 * The first call any bot makes. Without it a bot author would have to be handed channel
 * ids out of band — read them off a URL, or guess — and re-handed them whenever a channel
 * was added. The channel list is the bot's *visible* set, so a private channel it hasn't
 * been let into is absent here and refused at the send: one answer, consistent both times.
 */
class BotIdentityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Bot $bot */
        $bot = $request->attributes->get('bot');
        $bot->loadMissing('server');

        return response()->json(['data' => [
            'id' => $bot->id,
            'user' => new UserResource($request->user()),
            'server' => ['id' => $bot->server_id, 'name' => $bot->server?->name],
            'channels' => ChannelResource::collection(
                Channel::visibleTo($request->user())->where('server_id', $bot->server_id)->get()
            ),
        ]]);
    }
}
