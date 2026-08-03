<?php

namespace App\Http\Controllers;

use App\Actions\Bot\CreateBotAction;
use App\Actions\Bot\DeleteBotAction;
use App\Actions\Bot\RegenerateBotTokenAction;
use App\Actions\Bot\RegenerateWebhookSecretAction;
use App\Actions\Bot\UpdateBotAction;
use App\DTOs\Bot\CreateBotData;
use App\Http\Requests\Bot\ManageBotsRequest;
use App\Http\Requests\Bot\StoreBotRequest;
use App\Http\Requests\Bot\UpdateBotRequest;
use App\Http\Resources\BotResource;
use App\Models\Bot;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Bot registration, for the owner of a server.
 *
 * Every route here is nested under the server, and every one re-checks that the bot in the
 * path belongs to it: route model binding will happily hand over bot 7 to a request about
 * server 3, and without the check the owner of any server could rename or delete anyone's
 * bot by guessing an id.
 */
class BotController extends Controller
{
    public function index(ManageBotsRequest $request, Server $server): AnonymousResourceCollection
    {
        return BotResource::collection(
            $server->bots()->with('user', 'creator')->latest()->get()
        );
    }

    /**
     * Register a bot. This is the one response that carries the token, in full: it is not
     * stored in readable form, so if it isn't copied out of here it can only be replaced.
     */
    public function store(StoreBotRequest $request, Server $server, CreateBotAction $action): JsonResponse
    {
        $result = $action->handle($server, $request->user(), CreateBotData::fromArray($request->validated()));

        return (new BotResource($result['bot']))
            ->additional(array_filter([
                'token' => $result['token'],
                // Only when an endpoint was registered in the same breath — see CreateBotAction.
                'webhook_secret' => $result['webhook_secret'],
            ], fn ($value) => $value !== null))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rename, re-point, or re-enable. Registering a webhook URL for the first time mints
     * the signing secret, so this response can carry it — the only PATCH that returns one.
     */
    public function update(UpdateBotRequest $request, Server $server, Bot $bot, UpdateBotAction $action): JsonResponse
    {
        $this->assertBelongsTo($bot, $server);

        // `validated()` rather than a DTO: this is a PATCH, and what matters is which keys
        // were *sent* — a DTO would fill the absent ones in with nulls and clear them.
        $result = $action->handle($bot, $request->validated());

        return (new BotResource($result['bot']))
            ->additional($result['webhook_secret'] !== null ? ['webhook_secret' => $result['webhook_secret']] : [])
            ->response();
    }

    /** Rotate the token, invalidating the old one. Shown once, like the create. */
    public function regenerate(ManageBotsRequest $request, Server $server, Bot $bot, RegenerateBotTokenAction $action): JsonResponse
    {
        $this->assertBelongsTo($bot, $server);

        return response()->json(['data' => ['token' => $action->handle($bot)]]);
    }

    /**
     * Rotate the webhook signing secret. Shown once, and deliveries signed with the old one
     * stop verifying immediately — see RegenerateWebhookSecretAction.
     */
    public function regenerateWebhookSecret(ManageBotsRequest $request, Server $server, Bot $bot, RegenerateWebhookSecretAction $action): JsonResponse
    {
        $this->assertBelongsTo($bot, $server);

        abort_if($bot->webhook_url === null, 404, 'This bot has no webhook.');

        return response()->json(['data' => ['webhook_secret' => $action->handle($bot)]]);
    }

    /**
     * Make this the bot the server's automations speak as, taking it off whichever bot had
     * it before.
     *
     * Exactly one per server, enforced here rather than by a unique index — MySQL has no
     * filtered index, and a unique on (server_id, runs_automations) would also forbid a
     * server having two ordinary bots, which is what the platform is for. Both writes go in
     * one transaction so there is no instant with two, or with none.
     */
    public function setAutomationBot(ManageBotsRequest $request, Server $server, Bot $bot): BotResource
    {
        $this->assertBelongsTo($bot, $server);

        DB::transaction(function () use ($server, $bot): void {
            $server->bots()->where('runs_automations', true)->update(['runs_automations' => false]);
            $bot->update(['runs_automations' => true]);
        });

        return new BotResource($bot->load('user', 'creator'));
    }

    /** Retire the bot. Its past messages stay — see DeleteBotAction. */
    public function destroy(ManageBotsRequest $request, Server $server, Bot $bot, DeleteBotAction $action): Response
    {
        $this->assertBelongsTo($bot, $server);

        $action->handle($bot);

        return response()->noContent();
    }

    /** 404, not 403: whether a bot exists elsewhere is not this server's business. */
    private function assertBelongsTo(Bot $bot, Server $server): void
    {
        abort_if($bot->server_id !== $server->id, 404);
    }
}
