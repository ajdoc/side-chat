<?php

namespace App\Http\Controllers;

use App\Events\SpaceNoteUpdated;
use App\Http\Requests\Space\UpdateSpaceNoteRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Resources\SpaceNoteResource;
use App\Models\SideChat;

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

    public function update(UpdateSpaceNoteRequest $request, SideChat $sideChat): SpaceNoteResource
    {
        $note = $sideChat->spaceNote()->firstOrCreate([], ['content' => '']);

        $note->update([
            'content' => $request->validated('content') ?? '',
            'updated_by' => $request->user()->id,
        ]);

        // Everyone else's Notes tab converges on the new body; the saver skips the echo.
        broadcast(new SpaceNoteUpdated($note))->toOthers();

        return new SpaceNoteResource($note->load('editor'));
    }
}
