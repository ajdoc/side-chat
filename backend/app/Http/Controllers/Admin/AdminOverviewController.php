<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The panel's front page: how big the instance is, and what needs looking at.
 *
 * Counts only — deliberately cheap, and deliberately not a dashboard of graphs. The one
 * thing here that isn't a number is the list of currently blocked accounts, because that's
 * the only piece of state on this screen that somebody is waiting on an answer about.
 */
class AdminOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'counts' => [
                'users' => User::where('is_bot', false)->count(),
                'bots' => User::where('is_bot', true)->count(),
                'admins' => User::whereNotNull('role')->count(),
                'banned' => User::whereNotNull('banned_at')->count(),
                'servers' => Server::count(),
                'channels' => Channel::whereNotNull('server_id')->count(),
                'dms' => Conversation::where('type', 'dm')->count(),
                'groups' => Conversation::where('type', 'group')->count(),
                'messages' => Message::count(),
                // Signups in the last week — the number that tells you whether the spam
                // filter stopped working overnight.
                'new_users_this_week' => User::where('is_bot', false)
                    ->where('created_at', '>=', now()->subWeek())
                    ->count(),
            ],
            'banned_users' => AdminUserResource::collection(
                User::whereNotNull('banned_at')->with('bannedBy:id,name')->latest('banned_at')->limit(10)->get(),
            ),
        ]);
    }
}
