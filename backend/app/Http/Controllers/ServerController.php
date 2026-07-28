<?php

namespace App\Http\Controllers;

use App\Actions\Server\CreateServerAction;
use App\Actions\Server\DeleteServerAction;
use App\Actions\Server\LeaveServerAction;
use App\Actions\Server\RenameServerAction;
use App\DTOs\Server\CreateServerData;
use App\DTOs\Server\UpdateServerData;
use App\Events\ServerRoleUpdated;
use App\Http\Requests\Server\DeleteServerRequest;
use App\Http\Requests\Server\LeaveServerRequest;
use App\Http\Requests\Server\StoreServerRequest;
use App\Http\Requests\Server\UpdateMemberRoleRequest;
use App\Http\Requests\Server\UpdateServerRequest;
use App\Http\Requests\Server\ViewServerRequest;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServerController extends Controller
{
    public function __construct(private readonly ServerService $servers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ServerResource::collection($this->servers->forUser($request->user()));
    }

    public function store(StoreServerRequest $request, CreateServerAction $action): ServerResource
    {
        return new ServerResource(
            $action->handle($request->user(), CreateServerData::fromArray($request->validated()))
        );
    }

    public function show(ViewServerRequest $request, Server $server): ServerResource
    {
        return new ServerResource($server->loadCount('joinRequests'));
    }

    /** Rename. Owner only — the name is what every member sees this place called. */
    public function update(UpdateServerRequest $request, Server $server, RenameServerAction $action): ServerResource
    {
        return new ServerResource(
            $action->handle($server, UpdateServerData::fromArray($request->validated()))
        );
    }

    /** Owner only. Takes every channel, message and uploaded file with it. */
    public function destroy(DeleteServerRequest $request, Server $server, DeleteServerAction $action): Response
    {
        $action->handle($server);

        return response()->noContent();
    }

    /**
     * Make a member an admin, or put them back to plain member. Owner only.
     *
     * The owner themselves is not addressable here: their standing comes from `owner_id`,
     * not from the pivot, so writing a role for them would be a no-op that looked like a
     * demotion. 404 says the same thing more honestly.
     */
    public function updateRole(UpdateMemberRoleRequest $request, Server $server, User $member): JsonResponse
    {
        abort_if($server->isOwner($member) || ! $server->hasMember($member), 404);

        $role = $request->validated()['role'];
        $server->members()->updateExistingPivot($member->id, ['role' => $role]);

        broadcast(new ServerRoleUpdated($server, $member->id, $role));

        return response()->json(['data' => ['user_id' => $member->id, 'role' => $role]]);
    }

    /** Any member may go. The owner may not — see LeaveServerAction. */
    public function leave(LeaveServerRequest $request, Server $server, LeaveServerAction $action): Response
    {
        $action->handle($server, $request->user());

        return response()->noContent();
    }
}
