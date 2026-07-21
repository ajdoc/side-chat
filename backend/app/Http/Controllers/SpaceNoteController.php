<?php

namespace App\Http\Controllers;

use App\Events\SpaceNoteUpdated;
use App\Http\Requests\Space\UpdateSpaceNoteRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Resources\SpaceNoteResource;
use App\Models\SideChat;
use Illuminate\Http\JsonResponse;

/**
 * A side chat's Side Space note — the shared, collaboratively-edited document beside the
 * board. One note per side chat, created lazily the first time it's opened.
 *
 * Reading the note is a channel-membership power (ViewSideChatRequest); saving it is a roster
 * power (UpdateSpaceNoteRequest), exactly the line join draws for posting messages and drawing
 * on the board. The channel's own note is the near-identical {@see ChannelSpaceNoteController}.
 */
class SpaceNoteController extends Controller
{
    public function show(ViewSideChatRequest $request, SideChat $sideChat): SpaceNoteResource
    {
        $note = $sideChat->spaceNote()->firstOrCreate([], ['content' => '']);

        return new SpaceNoteResource($note->load('editor'));
    }

    /**
     * Save the body. A stale `base_version` — someone else saved while this editor was typing
     * — comes back 409 with the note as it now stands, which the client merges its own text
     * into and re-sends, so neither person's paragraph vanishes.
     */
    public function update(UpdateSpaceNoteRequest $request, SideChat $sideChat): JsonResponse
    {
        $note = $sideChat->spaceNote()->firstOrCreate([], ['content' => '']);

        $saved = $note->applyEdit(
            $request->validated('content') ?? '',
            $request->user()->id,
            $request->validated('base_version'),
        );

        $payload = ['data' => (new SpaceNoteResource($note->load('editor')))->resolve()];

        if (! $saved) {
            return response()->json($payload, 409);
        }

        // Everyone else's Notes tab converges on the new body; the saver skips the echo.
        broadcast(new SpaceNoteUpdated($note))->toOthers();

        return response()->json($payload);
    }
}
