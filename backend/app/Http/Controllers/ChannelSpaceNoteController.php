<?php

namespace App\Http\Controllers;

use App\Actions\Space\AnnounceNoteMentionsAction;
use App\Events\SpaceNoteUpdated;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Requests\Space\UpdateChannelSpaceNoteRequest;
use App\Http\Resources\SpaceNoteResource;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;

/**
 * A channel's (or DM's) Side Desk note — the same shared document a side chat has
 * ({@see SpaceNoteController}), hanging off a plain channel. The only difference is the gate:
 * a channel has no roster, so membership is the whole rule for both reading and saving.
 */
class ChannelSpaceNoteController extends Controller
{
    public function show(ChannelSpaceRequest $request, Channel $channel): SpaceNoteResource
    {
        $note = $channel->spaceNote()->firstOrCreate([], ['content' => '']);

        return new SpaceNoteResource($note->load('editor'));
    }

    /** Same optimistic-concurrency save as {@see SpaceNoteController::update()}: 409 on a stale base. */
    public function update(UpdateChannelSpaceNoteRequest $request, Channel $channel, AnnounceNoteMentionsAction $mentions): JsonResponse
    {
        $note = $channel->spaceNote()->firstOrCreate([], ['content' => '']);

        // Captured before the write: the announcement is about what this save *added*.
        // See AnnounceNoteMentionsAction.
        $before = (string) $note->content;

        $saved = $note->applyEdit(
            $request->validated('content') ?? '',
            $request->user()->id,
            $request->validated('base_version'),
        );

        $payload = ['data' => (new SpaceNoteResource($note->load('editor')))->resolve()];

        if (! $saved) {
            return response()->json($payload, 409);
        }

        broadcast(new SpaceNoteUpdated($note))->toOthers();

        // Anybody newly named in the body is told, once. A no-op for a save that added
        // none, which is almost every save - a note is written a keystroke at a time.
        $mentions->handle($note, $request->user(), $before);

        return response()->json($payload);
    }
}
