<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Channel\DeleteChannelAction;
use App\Actions\Server\DeleteServerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateServerRequest;
use App\Http\Resources\Admin\AdminChannelResource;
use App\Http\Resources\Admin\AdminServerResource;
use App\Models\Channel;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Every server on the instance, and the channels inside them.
 *
 * Deletes go through the same actions the owner's own buttons use — DeleteServerAction and
 * DeleteChannelAction — rather than through a raw `delete()`. That isn't tidiness: those
 * actions purge uploaded files and broadcast the removal, and a server that vanished from
 * the database while still sitting in everyone's sidebar is the bug you get for skipping
 * them.
 *
 * Channels here are listed flat, discussions included, with `parent_id` left for the client
 * to indent by. A moderator looking for the room where something happened does not care
 * whether it was a channel or a discussion inside one.
 */
class AdminServerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $servers = Server::query()
            ->with('owner:id,name,email,avatar')
            ->withCount(['members', 'channels'])
            ->when($request->string('q')->trim()->value(), function ($query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where('name', 'ilike', $like);
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25) ?: 25, 100))
            ->withQueryString();

        return AdminServerResource::collection($servers);
    }

    /** One server with its channels — the detail pane opens straight onto them. */
    public function show(Server $server): AdminServerResource
    {
        $server->load([
            'owner:id,name,email,avatar',
            'channels' => fn ($q) => $q->withCount('messages')->orderBy('position')->orderBy('id'),
        ])->loadCount(['members', 'channels']);

        return new AdminServerResource($server);
    }

    public function update(UpdateServerRequest $request, Server $server): AdminServerResource
    {
        $server->fill($request->validated())->save();

        return new AdminServerResource(
            $server->fresh()->load('owner:id,name,email,avatar')->loadCount(['members', 'channels']),
        );
    }

    public function destroy(Server $server, DeleteServerAction $action): Response
    {
        $action->handle($server);

        return response()->noContent();
    }

    /** Rename a channel, or flip its private flag. */
    public function updateChannel(Request $request, Channel $channel): AdminChannelResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'is_private' => ['sometimes', 'boolean'],
        ]);

        $channel->fill($data)->save();

        return new AdminChannelResource($channel->fresh()->loadCount('messages'));
    }

    /**
     * Delete a channel — including a conversation's.
     *
     * A DM's single channel is reachable here, and deleting it would leave a chat with
     * nowhere to put messages. Deleting the *conversation* is the operation that means
     * something, so this refuses and points at it. See AdminConversationController.
     */
    public function destroyChannel(Channel $channel, DeleteChannelAction $action): Response
    {
        abort_if(
            $channel->conversation_id !== null,
            422,
            'This channel belongs to a chat. Delete the chat itself instead.',
        );

        $action->handle($channel);

        return response()->noContent();
    }
}
