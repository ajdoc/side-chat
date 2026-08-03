<?php

namespace App\Http\Controllers;

use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Http\Requests\Automation\StoreCustomCommandRequest;
use App\Models\CustomCommand;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The Commands page: the canned answers a server declares for itself.
 *
 * Staff, like the rest of the dashboard. The worst a custom command can do is print a
 * message somebody wrote, which is a thing an admin can already do by typing.
 */
class CustomCommandController extends Controller
{
    public function index(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        return response()->json([
            'data' => $server->customCommands()->orderBy('name')->get()->map($this->present(...)),
        ]);
    }

    public function store(StoreCustomCommandRequest $request, Server $server): JsonResponse
    {
        $command = $server->customCommands()->create($request->validated());

        return response()->json(['data' => $this->present($command)], 201);
    }

    public function update(StoreCustomCommandRequest $request, Server $server, CustomCommand $command): JsonResponse
    {
        $this->belongsTo($server, $command);

        $command->update($request->validated());

        return response()->json(['data' => $this->present($command)]);
    }

    public function destroy(ManageAutomationsRequest $request, Server $server, CustomCommand $command): Response
    {
        $this->belongsTo($server, $command);

        $command->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(CustomCommand $command): array
    {
        return [
            'id' => $command->id,
            'server_id' => $command->server_id,
            'name' => $command->name,
            'kind' => $command->kind,
            'description' => $command->description,
            'response' => $command->response,
            'required_badge_id' => $command->required_badge_id,
            'cooldown_seconds' => (int) $command->cooldown_seconds,
            'enabled' => (bool) $command->enabled,
            'use_count' => (int) $command->use_count,
        ];
    }

    /** 404, not 403: a command in another server is not one this server has. */
    private function belongsTo(Server $server, CustomCommand $command): void
    {
        abort_if($command->server_id !== $server->getKey(), 404);
    }
}
