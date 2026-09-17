<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Models\Channel;
use App\Models\SignupGroup;
use App\Support\Signups\SignupAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * A channel's sign-up groups. Staff only. Deleting a group ungroups its sheets and schedules;
 * it never deletes them.
 */
class SignupGroupController extends Controller
{
    public function store(TrackerRequest $request, Channel $channel): JsonResponse
    {
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $group = SignupGroup::create([
            'channel_id' => $channel->id,
            'name' => trim($data['name']),
            'color' => $data['color'] ?? 'slate',
            'position' => (SignupGroup::where('channel_id', $channel->id)->max('position') ?? -1) + 1,
        ]);

        $this->changed($channel);

        return response()->json(['data' => $group->only(['id', 'name', 'color', 'position'])], 201);
    }

    public function update(TrackerRequest $request, Channel $channel, SignupGroup $group): JsonResponse
    {
        abort_unless($group->channel_id === $channel->id, 404);
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'color' => ['sometimes', 'string', 'max:20'],
        ]);

        $group->update($data);
        $this->changed($channel);

        return response()->json(['data' => $group->only(['id', 'name', 'color', 'position'])]);
    }

    /** The whole order at once — the body is every group id, first to last. */
    public function reorder(TrackerRequest $request, Channel $channel): Response
    {
        SignupAccess::authorize($channel, $request->user());

        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        DB::transaction(function () use ($channel, $data) {
            foreach (array_values($data['ids']) as $i => $id) {
                SignupGroup::where('channel_id', $channel->id)->whereKey($id)->update(['position' => $i]);
            }
        });

        $this->changed($channel);

        return response()->noContent();
    }

    public function destroy(TrackerRequest $request, Channel $channel, SignupGroup $group): Response
    {
        abort_unless($group->channel_id === $channel->id, 404);
        SignupAccess::authorize($channel, $request->user());

        $group->delete();
        $this->changed($channel);

        return response()->noContent();
    }

    private function changed(Channel $channel): void
    {
        broadcast(new TrackerChanged('channel.'.$channel->id, 'signup', 'saved', ['id' => null]))->toOthers();
    }
}
