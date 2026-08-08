<?php

namespace App\Http\Controllers;

use App\Events\DeskAppsSaved;
use App\Http\Requests\DeskApps\ChannelDeskAppsRequest;
use App\Http\Requests\DeskApps\SideChatDeskAppsRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Models\Channel;
use App\Models\SideChat;
use Illuminate\Http\JsonResponse;

/**
 * Which apps a Side Desk shows — read and rewrite, for both kinds of surface.
 *
 * One controller for both here, unlike the canvas and calendar which have a class each. Those
 * two have real per-surface behaviour to express (widget scoping, roster gates on individual
 * rows); this is a single JSON column with the same four lines of handling either side, and
 * splitting it would be two files of duplication to no end. The differing authorisation still
 * lives where it belongs — in the request classes.
 *
 * A null column means "never customised" and is returned as null, not as the defaults: the
 * client owns the default set, so a later release can add an app to it and have that reach
 * surfaces that already exist. See the migration for why.
 */
class DeskAppsController extends Controller
{
    public function showChannel(ChannelSpaceRequest $request, Channel $channel): JsonResponse
    {
        return response()->json(['apps' => $channel->desk_apps]);
    }

    public function updateChannel(ChannelDeskAppsRequest $request, Channel $channel): JsonResponse
    {
        $apps = array_values($request->validated('apps'));

        $channel->update(['desk_apps' => $apps]);
        broadcast(new DeskAppsSaved('channel.'.$channel->id, $apps))->toOthers();

        return response()->json(['apps' => $apps]);
    }

    public function showSideChat(ViewSideChatRequest $request, SideChat $sideChat): JsonResponse
    {
        return response()->json(['apps' => $sideChat->desk_apps]);
    }

    public function updateSideChat(SideChatDeskAppsRequest $request, SideChat $sideChat): JsonResponse
    {
        $apps = array_values($request->validated('apps'));

        $sideChat->update(['desk_apps' => $apps]);
        broadcast(new DeskAppsSaved('sidechat.'.$sideChat->id, $apps))->toOthers();

        return response()->json(['apps' => $apps]);
    }
}
