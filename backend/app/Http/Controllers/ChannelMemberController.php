<?php

namespace App\Http\Controllers;

use App\Http\Requests\Channel\IndexChannelMemberRequest;
use App\Models\Channel;
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

        $members = $container->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'avatar'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ]);

        return response()->json(['data' => $members]);
    }
}
