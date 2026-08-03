<?php

namespace App\Http\Controllers;

use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Http\Requests\Automation\StoreBadgeRequest;
use App\Http\Resources\BadgeResource;
use App\Models\Badge;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Badges: the labels a server hands out, by hand or by rule.
 *
 * Staff, not owner-only. A badge grants nothing (see the badges migration) — that's the
 * whole point of keeping it separate from `server_user.role`, and it's what makes handing
 * one out a thing an admin can safely do.
 */
class BadgeController extends Controller
{
    public function index(ManageAutomationsRequest $request, Server $server): AnonymousResourceCollection
    {
        return BadgeResource::collection($server->badges()->withCount('holders')->get());
    }

    public function store(StoreBadgeRequest $request, Server $server): JsonResponse
    {
        $badge = $server->badges()->create($request->validated());

        return (new BadgeResource($badge))->response()->setStatusCode(201);
    }

    public function update(StoreBadgeRequest $request, Server $server, Badge $badge): BadgeResource
    {
        $this->belongsTo($server, $badge);

        $badge->update($request->validated());

        return new BadgeResource($badge->loadCount('holders'));
    }

    /**
     * Deleting a badge takes it off everyone who had it — the pivot cascades.
     *
     * It also leaves any rule that named it pointing at nothing. That's recorded rather
     * than prevented: the rule records a failure next time it runs, which the owner sees on
     * the dashboard, and refusing the delete until every rule had been edited would be a
     * worse trade than one clear red line.
     */
    public function destroy(ManageAutomationsRequest $request, Server $server, Badge $badge): Response
    {
        $this->belongsTo($server, $badge);

        $badge->delete();

        return response()->noContent();
    }

    /** Hand one out, or take it back, without waiting for a rule to do it. */
    public function grant(ManageAutomationsRequest $request, Server $server, Badge $badge, User $member): JsonResponse
    {
        $this->belongsTo($server, $badge);
        abort_unless($server->hasMember($member), 404);

        $badge->grantTo($member, $request->user());

        return response()->json(['data' => ['granted' => true]]);
    }

    public function revoke(ManageAutomationsRequest $request, Server $server, Badge $badge, User $member): Response
    {
        $this->belongsTo($server, $badge);

        $badge->revokeFrom($member);

        return response()->noContent();
    }

    /** 404, not 403: a badge in another server is not a badge this server has. */
    private function belongsTo(Server $server, Badge $badge): void
    {
        abort_if($badge->server_id !== $server->getKey(), 404);
    }
}
