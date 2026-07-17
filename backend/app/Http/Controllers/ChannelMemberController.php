<?php

namespace App\Http\Controllers;

use App\Http\Requests\Channel\IndexChannelMemberRequest;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;

class ChannelMemberController extends Controller
{
    /**
     * The people who can be @mentioned in this channel — its server's members, or the
     * chat's participants. Deliberately thin: id, name and avatar are all the composer's
     * autocomplete needs, and it keeps everyone's email out of a list any member can read.
     */
    public function index(IndexChannelMemberRequest $request, Channel $channel): JsonResponse
    {
        // Never null in practice — the request already proved the caller is a member of it,
        // which it could only be if the container existed. The guard is for the type-checker.
        $container = $channel->container();
        abort_if($container === null, 404);

        $members = $container->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'avatar'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
            ]);

        return response()->json(['data' => $members]);
    }
}
