<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\MemberRequest;
use App\Http\Requests\Tracker\StoreTaskRequest;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\TrackerTaskResource;
use App\Models\Channel;
use App\Models\TrackerProject;
use App\Models\TrackerTask;
use App\Support\Apps\AppAutomations;
use App\Support\Apps\AppSubjects;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The tasks in a channel's Tracker.
 *
 * Everything here is addressed to the channel, so {@see MemberRequest} has
 * already settled who may be here; what's left is checking that the row named in the URL really
 * belongs to *this* channel, which every method does before touching it.
 */
class TrackerTaskController extends Controller
{
    /**
     * The channel's tasks, optionally narrowed.
     *
     * One endpoint serves both screens the client has. Without `project` it answers the
     * tracker's home — "your tasks", across every project here — and with one it answers the
     * board. The filters are the board's search box and its filter menu.
     */
    public function index(TrackerRequest $request, Channel $channel): AnonymousResourceCollection
    {
        $query = TrackerTask::query()
            // Always: the resource composes a task's key from its project, and lazy loading
            // throws outside production. See AppServiceProvider.
            ->with(['project', 'assignee', 'tags'])
            ->whereHas('project', fn ($p) => $p->where('channel_id', $channel->getKey()));

        if ($projectId = $request->integer('project')) {
            $query->where('project_id', $projectId);
        }

        if ($request->filled('assignee')) {
            // 'me' rather than an id, so the client doesn't have to know its own user id to ask
            // the question every client asks.
            $query->where('assignee_id', $request->input('assignee') === 'me'
                ? $request->user()->id
                : $request->integer('assignee'));
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($term = trim((string) $request->query('q'))) {
            // Title only, and deliberately not the description: this is the board's filter box,
            // which is for finding a task you know exists. Searching what people wrote *in* a
            // task is what the search palette is for.
            $query->where('title', 'like', '%'.$term.'%');
        }

        return TrackerTaskResource::collection(
            $query->orderBy('position')->orderBy('id')->get()
        );
    }

    /** One task with everything the detail pane draws — its comments and its history included. */
    public function show(TrackerRequest $request, Channel $channel, TrackerTask $task): TrackerTaskResource
    {
        $this->authorizeTask($channel, $task);

        return new TrackerTaskResource($task->load([
            'project', 'assignee', 'creator', 'tags',
            'comments.user', 'activity.user',
        ]));
    }

    public function store(StoreTaskRequest $request, Channel $channel): TrackerTaskResource
    {
        $project = TrackerProject::where('channel_id', $channel->getKey())
            ->find($request->integer('project_id'));

        if ($project === null) {
            throw ValidationException::withMessages([
                'project_id' => 'That project is not in this channel.',
            ]);
        }

        $task = DB::transaction(function () use ($request, $channel, $project) {
            // Inside the transaction, and under a row lock — see TrackerProject::takeNextNumber.
            $number = $project->takeNextNumber();

            $task = $project->tasks()->create([
                ...$request->safe()->only(['title', 'description', 'status', 'priority', 'assignee_id', 'due_date']),
                'number' => $number,
                'created_by' => $request->user()->id,
                'position' => ((int) $project->tasks()->max('position')) + 1,
            ]);

            // Stamped here rather than left to the update path, because a task can be created
            // straight into Done — dragging one in from a checklist, say — and the progress bar
            // counts the stamp.
            if ($task->isDone()) {
                $task->forceFill(['completed_at' => now()])->save();
            }

            if ($request->has('tag_ids')) {
                $this->syncTags($channel, $task, $request->input('tag_ids', []));
            }

            $task->recordActivity('created', $request->user());
            AppAutomations::taskCreated($task, $request->user());

            return $task;
        });

        $task->load(['project', 'assignee', 'creator', 'tags']);
        $this->broadcast($channel, 'saved', $task);

        return new TrackerTaskResource($task);
    }

    /**
     * Edit a task. Only the fields present change — the detail pane saves one at a time.
     *
     * Every change that is worth a line in the history writes one, in the same transaction as
     * the change itself, so the feed can't outlive a write that rolled back.
     */
    public function update(StoreTaskRequest $request, Channel $channel, TrackerTask $task): TrackerTaskResource
    {
        $this->authorizeTask($channel, $task);

        DB::transaction(function () use ($request, $channel, $task) {
            $actor = $request->user();
            $before = $task->only(['status', 'priority', 'assignee_id', 'due_date', 'title']);

            $task->update($request->safe()->only([
                'title', 'description', 'status', 'priority', 'assignee_id', 'due_date', 'position',
            ]));

            // Done is the one status with a side effect. Set on the way in and *cleared* on the
            // way out: a task reopened after being finished is not finished, and a stale stamp
            // would leave the progress bar counting it forever.
            if ($task->wasChanged('status')) {
                $task->forceFill(['completed_at' => $task->isDone() ? now() : null])->save();

                $task->recordActivity('status', $actor, [
                    'from' => $before['status'],
                    'to' => $task->status,
                ]);

                // "When a task reaches Done, tell the channel" — the rule this trigger exists
                // for. Fired here rather than from a model event so a bulk import stays silent.
                AppAutomations::taskStatusChanged($task, (string) $before['status'], $actor);
            }

            foreach (['priority', 'assignee_id', 'due_date', 'title'] as $field) {
                if ($field !== 'status' && $task->wasChanged($field)) {
                    $task->recordActivity($field === 'assignee_id' ? 'assignee' : $field, $actor, [
                        'from' => $before[$field] instanceof \DateTimeInterface
                            ? $before[$field]->format('Y-m-d')
                            : $before[$field],
                        'to' => $task->{$field} instanceof \DateTimeInterface
                            ? $task->{$field}->format('Y-m-d')
                            : $task->{$field},
                    ]);
                }
            }

            if ($request->has('tag_ids')) {
                $this->syncTags($channel, $task, $request->input('tag_ids', []));
            }
        });

        $task->load(['project', 'assignee', 'creator', 'tags']);
        $this->broadcast($channel, 'saved', $task);

        return new TrackerTaskResource($task);
    }

    /**
     * Takes the task's comments, tags and history with it.
     *
     * Not on a foreign key — those tables are polymorphic, so there's nothing for the database
     * to cascade along. It happens in the model's deleting event; see HasAppActivity.
     */
    public function destroy(TrackerRequest $request, Channel $channel, TrackerTask $task): Response
    {
        $this->authorizeTask($channel, $task);

        $projectId = $task->project_id;
        $task->delete();

        broadcast(new TrackerChanged('channel.'.$channel->id, 'task', 'removed', [
            'id' => $task->id,
            // The client drops it from the right column without having to hold a global index
            // of which project every task id belonged to.
            'project_id' => $projectId,
        ]))->toOthers();

        return response()->noContent();
    }

    /**
     * A task belongs to this channel, or it may as well not exist.
     *
     * 404 rather than 403, so an id from another channel is indistinguishable from one that was
     * never there — the same answer {@see AppSubjects} gives.
     */
    private function authorizeTask(Channel $channel, TrackerTask $task): void
    {
        abort_unless($task->loadMissing('project')->project?->channel_id === $channel->id, 404);
    }

    /**
     * Attach exactly the named tags, ignoring any that belong to another channel.
     *
     * Filtered rather than rejected: tag ids arrive alongside a title and a status in one save,
     * and failing the whole edit because one stale id came along would lose the rest of it.
     *
     * @param  array<int, mixed>  $tagIds
     */
    private function syncTags(Channel $channel, TrackerTask $task, array $tagIds): void
    {
        $valid = $channel->appTags()
            ->whereIn('id', array_map('intval', $tagIds))
            ->pluck('id');

        $task->tags()->sync($valid);
    }

    private function broadcast(Channel $channel, string $action, TrackerTask $task): void
    {
        broadcast(new TrackerChanged(
            'channel.'.$channel->id,
            'task',
            $action,
            (new TrackerTaskResource($task))->resolve(),
        ))->toOthers();
    }
}
