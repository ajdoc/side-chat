<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\StoreProjectRequest;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\TrackerProjectResource;
use App\Models\Channel;
use App\Models\Concerns\HasAppActivity;
use App\Models\TrackerProject;
use App\Models\TrackerTask;
use App\Support\Tracker\TrackerFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The projects in a channel's Tracker.
 *
 * Scoped to a channel like every other surface app's storage — see {@see ChannelCalendarController}
 * for the same shape. Membership gates reading and authoring alike, because a channel has no
 * roster to distinguish them.
 */
class TrackerProjectController extends Controller
{
    /**
     * Every project in the channel, each with the two numbers its progress bar is made of.
     *
     * Unpaginated, on the same reasoning as the calendar: a channel's tracker holds a handful
     * of projects, the home screen draws all of them at once, and windowing would buy nothing.
     * The counts are aggregates rather than loaded tasks — the cards show "0 / 2", not the
     * tasks themselves.
     */
    public function index(TrackerRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return TrackerProjectResource::collection(
            $channel->trackerProjects()
                ->with('creator')
                ->withCount(['tasks', 'tasks as done_tasks_count' => fn ($q) => $q->where('status', TrackerFields::DONE)])
                ->orderBy('position')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(StoreProjectRequest $request, Channel $channel): TrackerProjectResource
    {
        $project = $channel->trackerProjects()->create([
            ...$request->safe()->only(['name', 'key', 'description']),
            'created_by' => $request->user()->id,
            'position' => ((int) $channel->trackerProjects()->max('position')) + 1,
        ]);

        // Counts the client would otherwise have to invent for a project it has just been
        // handed. They're both zero, but sending them keeps one shape for a project everywhere.
        $project->tasks_count = 0;
        $project->done_tasks_count = 0;

        $this->broadcast($channel, 'project', 'saved', $project->load('creator'));

        return new TrackerProjectResource($project);
    }

    public function update(StoreProjectRequest $request, Channel $channel, TrackerProject $project): TrackerProjectResource
    {
        abort_unless($project->channel_id === $channel->id, 404);

        $project->update($request->safe()->only(['name', 'key', 'description', 'archived']));

        $project->loadCount(['tasks', 'tasks as done_tasks_count' => fn ($q) => $q->where('status', TrackerFields::DONE)]);
        $this->broadcast($channel, 'project', 'saved', $project->load('creator'));

        return new TrackerProjectResource($project);
    }

    /**
     * Delete a project and everything in it.
     *
     * Its tasks go with it on the foreign key, but their comments, tags and history don't:
     * those tables are polymorphic, so there's no key for the database to cascade along. The
     * task ids are collected and purged here, before the delete that would otherwise orphan
     * them — see {@see HasAppActivity::purgeAppActivityFor}.
     *
     * The client confirms in as many words first ("this permanently deletes the project, its
     * tasks, and their comments and history"), the same rule an app channel itself follows.
     */
    public function destroy(TrackerRequest $request, Channel $channel, TrackerProject $project): Response
    {
        abort_unless($project->channel_id === $channel->id, 404);

        DB::transaction(function () use ($project) {
            TrackerTask::purgeAppActivityFor($project->tasks()->pluck('id')->all());
            $project->delete();
        });
        $this->broadcastRemoval($channel, 'project', $project->id);

        return response()->noContent();
    }

    /** The closed sets the client draws its status and priority pickers from. */
    public function fields(TrackerRequest $request, Channel $channel): JsonResponse
    {
        return response()->json([
            'statuses' => TrackerFields::STATUSES,
            'priorities' => TrackerFields::PRIORITIES,
            'tag_colors' => TrackerFields::TAG_COLORS,
        ]);
    }

    private function broadcast(Channel $channel, string $subject, string $action, TrackerProject $project): void
    {
        broadcast(new TrackerChanged(
            'channel.'.$channel->id,
            $subject,
            $action,
            (new TrackerProjectResource($project))->resolve(),
        ))->toOthers();
    }

    private function broadcastRemoval(Channel $channel, string $subject, int $id): void
    {
        broadcast(new TrackerChanged('channel.'.$channel->id, $subject, 'removed', ['id' => $id]))->toOthers();
    }
}
