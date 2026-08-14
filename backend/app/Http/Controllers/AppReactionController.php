<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tracker\TrackerRequest;
use App\Models\Channel;
use App\Support\Apps\AppSubjects;
use Illuminate\Http\JsonResponse;

/**
 * Emoji reactions on anything an app owns.
 *
 * One endpoint, one verb: **toggle**. Reacting and un-reacting are the same gesture on the same
 * chip, and splitting them into POST and DELETE would make the client track whether it had
 * already reacted — the state that goes stale the moment somebody reacts in another tab.
 *
 * Addressed like the comments and tags: `channels/{channel}/apps/{type}/{id}/reactions`, with
 * `{type}` resolved by {@see AppSubjects}.
 */
class AppReactionController extends Controller
{
    /** The chip row as it stands — what a freshly-opened item draws before anybody clicks. */
    public function index(TrackerRequest $request, Channel $channel, string $type, int $id): JsonResponse
    {
        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        return response()->json([
            'reactions' => $subject->load('reactions')->reactionSummary($request->user()),
        ]);
    }

    public function toggle(TrackerRequest $request, Channel $channel, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            // Length rather than a catalogue: an emoji is one to a few code points and there is
            // no closed set worth maintaining. Kept short so the column can't be used as storage.
            'emoji' => ['required', 'string', 'max:32'],
        ]);

        $subject = AppSubjects::resolve($channel, $type, $id);
        abort_if($subject === null, 404);

        $existing = $subject->reactions()
            ->where('user_id', $request->user()->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            $subject->reactions()->create([
                'channel_id' => $channel->getKey(),
                'user_id' => $request->user()->id,
                'emoji' => $data['emoji'],
            ]);
        }

        // The whole summary back, rather than "added" / "removed". It's what the chip row
        // renders, and returning it means a click never leaves the count to be guessed at.
        return response()->json([
            'reactions' => $subject->load('reactions')->reactionSummary($request->user()),
        ]);
    }
}
