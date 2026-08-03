<?php

namespace App\Http\Controllers;

use App\Http\Requests\Channel\IndexChannelMemberRequest;
use App\Models\Channel;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

class ChannelMemberController extends Controller
{
    /**
     * The people in this channel — its server's members, or the chat's participants.
     * Serves two readers: the composer's @-mention autocomplete (which only touches
     * id/name/avatar) and the Info panel's participant list, which also shows email.
     * Every reader here is already a proven member of the container, so the roster it
     * belongs to is one they're a part of.
     */
    public function index(IndexChannelMemberRequest $request, Channel $channel): JsonResponse
    {
        // Never null in practice — the request already proved the caller is a member of it,
        // which it could only be if the container existed. The guard is for the type-checker.
        $container = $channel->container();
        abort_if($container === null, 404);

        // A third reader now: the server's Roles settings, which needs to know what each
        // member currently is. `owner` isn't a pivot value — it's the server's owner_id —
        // so it's folded in here rather than read off the roster.
        $ownerId = $container->owner_id ?? null;

        /*
         * Badges, for the servers that hand them out.
         *
         * Eager-loaded and scoped to *this* server rather than fetched per member: a badge
         * belongs to one server (see the badges migration), so a member's badges elsewhere
         * are none of this roster's business — and a relation loaded per row would be an
         * N+1 on the one list that renders every member at once.
         *
         * A chat has no server and therefore no badges; the constraint below resolves to
         * nothing rather than needing a branch.
         */
        $serverId = $container instanceof Server ? $container->getKey() : null;

        $members = $container->members()
            ->with(['badges' => fn ($query) => $query->where('badges.server_id', $serverId)])
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'avatar'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'role' => $user->id === $ownerId ? 'owner' : ($user->pivot->role ?? 'member'),
                'badges' => $user->badges->map(fn ($badge) => [
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'emoji' => $badge->emoji,
                    'color' => $badge->color,
                ])->values(),
            ]);

        return response()->json(['data' => $members]);
    }
}
