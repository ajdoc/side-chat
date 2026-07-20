<?php

namespace App\Http\Controllers;

use App\Events\SpaceNoteUpdated;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Requests\Space\UpdateChannelSpaceNoteRequest;
use App\Http\Resources\SpaceNoteResource;
use App\Models\Channel;

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

    public function update(UpdateChannelSpaceNoteRequest $request, Channel $channel): SpaceNoteResource
    {
        $note = $channel->spaceNote()->firstOrCreate([], ['content' => '']);

        $note->update([
            'content' => $request->validated('content') ?? '',
            'updated_by' => $request->user()->id,
        ]);

        broadcast(new SpaceNoteUpdated($note))->toOthers();

        return new SpaceNoteResource($note->load('editor'));
    }
}
