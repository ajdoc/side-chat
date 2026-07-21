<?php

namespace App\Http\Controllers;

use App\Events\SpaceNoteUpdated;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Requests\Space\UpdateChannelSpaceNoteRequest;
use App\Http\Resources\SpaceNoteResource;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;

/**
 * A channel's (or DM's) Side Space note — the same shared document a side chat has
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
    public function update(UpdateChannelSpaceNoteRequest $request, Channel $channel): JsonResponse
    {
        $note = $channel->spaceNote()->firstOrCreate([], ['content' => '']);

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

        return response()->json($payload);
    }
}
