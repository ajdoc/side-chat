<?php

namespace App\Http\Controllers;

use App\Actions\Invite\ApproveJoinRequestsAction;
use App\Actions\Invite\DeclineJoinRequestsAction;
use App\DTOs\Server\BulkJoinRequestData;
use App\Http\Requests\Server\BulkJoinRequestsRequest;
use App\Http\Requests\Server\IndexJoinRequestsRequest;
use App\Http\Resources\ServerJoinRequestResource;
use App\Models\Server;
use App\Services\JoinRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JoinRequestController extends Controller
{
    public function __construct(private readonly JoinRequestService $joinRequests) {}

    public function index(IndexJoinRequestsRequest $request, Server $server): AnonymousResourceCollection
    {
        return ServerJoinRequestResource::collection($this->joinRequests->pendingFor($server));
    }

    /** Bulk (or single) approve: users join and get announced in the first text channel. */
    public function approve(BulkJoinRequestsRequest $request, Server $server, ApproveJoinRequestsAction $action): JsonResponse
    {
        $data = BulkJoinRequestData::fromArray($request->validated());

        return response()->json(['approved' => $action->handle($server, $data->request_ids)]);
    }

    /** Bulk (or single) decline: the request is simply removed. */
    public function decline(BulkJoinRequestsRequest $request, Server $server, DeclineJoinRequestsAction $action): JsonResponse
    {
        $data = BulkJoinRequestData::fromArray($request->validated());

        return response()->json(['declined' => $action->handle($server, $data->request_ids)]);
    }
}
