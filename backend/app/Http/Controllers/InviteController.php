<?php

namespace App\Http\Controllers;

use App\Actions\Invite\RequestToJoinServerAction;
use App\Http\Requests\Invite\JoinInviteRequest;
use App\Http\Requests\Invite\ShowInviteRequest;
use App\Services\InviteService;
use Illuminate\Http\JsonResponse;

class InviteController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /** Preview an invite: which server it points at, and where the caller stands. */
    public function show(ShowInviteRequest $request, string $code): JsonResponse
    {
        $server = $this->invites->resolve($code);

        abort_if($server === null, 404, 'This invite link is not valid.');

        return response()->json([
            'data' => [
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'members_count' => $server->members()->count(),
                ],
                'status' => $this->invites->statusFor($server, $request->user()),
            ],
        ]);
    }

    /** Ask to join. A member must approve before the user actually joins. */
    public function join(JoinInviteRequest $request, string $code, RequestToJoinServerAction $action): JsonResponse
    {
        $server = $this->invites->resolve($code);

        abort_if($server === null, 404, 'This invite link is not valid.');

        $action->handle($server, $request->user());

        return response()->json([
            'data' => [
                'server' => ['id' => $server->id, 'name' => $server->name],
                'status' => $this->invites->statusFor($server, $request->user()),
            ],
        ]);
    }
}
