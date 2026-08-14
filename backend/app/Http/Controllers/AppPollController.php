<?php

namespace App\Http\Controllers;

use App\Events\TrackerChanged;
use App\Http\Requests\Tracker\TrackerRequest;
use App\Http\Resources\AppPollResource;
use App\Models\AppPoll;
use App\Models\Channel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A channel's Polls — the wall, and voting on what's on it.
 *
 * Scoped to a channel like every other surface app's storage. Membership gates reading and
 * authoring alike, because a channel has no roster to distinguish them.
 *
 * Rides the same {@see TrackerChanged} broadcast the tracker uses, under its own subjects. That
 * event was written as "the app's list changed: upsert or drop by id", which is as true of a
 * poll as of a task — a second event class would be the same six lines under another name.
 */
class AppPollController extends Controller
{
    /** Every poll on the wall: open ones first, newest first within each. */
    public function index(TrackerRequest $request, Channel $channel): AnonymousResourceCollection
    {
        $polls = $channel->polls()
            ->with(['creator', 'options', 'votes', 'reactions'])
            ->withCount('comments')
            // Open before closed. `closed_at` is null while open, and null sorts last ascending
            // in Postgres — so this is the raw ordering rather than a column name.
            ->orderByRaw('closed_at IS NOT NULL')
            ->latest()
            ->get();

        return AppPollResource::collection($polls);
    }

    /** One poll, with its comment thread — the detail view's extra load. */
    public function show(TrackerRequest $request, Channel $channel, AppPoll $poll): AppPollResource
    {
        $this->authorizePoll($channel, $poll);

        return new AppPollResource($poll->load([
            'creator', 'options', 'votes', 'reactions', 'comments.user', 'tags',
        ]));
    }

    public function store(TrackerRequest $request, Channel $channel): AppPollResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(AppPoll::TYPES)],
            'question' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
            'anonymous' => ['sometimes', 'boolean'],
            // A yes/no poll brings its own options, so the client may send none.
            'options' => ['sometimes', 'array', 'max:20'],
            'options.*' => ['string', 'max:200'],
        ]);

        $poll = DB::transaction(function () use ($channel, $request, $data) {
            $poll = $channel->polls()->create([
                ...collect($data)->only(['type', 'question', 'description', 'anonymous'])->all(),
                'created_by' => $request->user()->id,
            ]);

            // Yes/No is a single-choice poll whose options are always the same two words, so
            // the server writes them rather than making every client agree on the spelling.
            $labels = $data['type'] === 'yes_no'
                ? ['Yes', 'No']
                : array_values(array_filter(array_map('trim', $data['options'] ?? [])));

            if (count($labels) < 2) {
                throw ValidationException::withMessages([
                    'options' => 'A poll needs at least two options.',
                ]);
            }

            foreach ($labels as $i => $label) {
                $poll->options()->create(['label' => $label, 'position' => $i]);
            }

            $poll->recordActivity('created', $request->user());

            return $poll;
        });

        $poll->load(['creator', 'options', 'votes', 'reactions']);
        $this->broadcast($channel, 'saved', $poll);

        return new AppPollResource($poll);
    }

    /** Edit the wording, or open/close it. The options themselves are fixed once votes exist. */
    public function update(TrackerRequest $request, Channel $channel, AppPoll $poll): AppPollResource
    {
        $this->authorizePoll($channel, $poll);

        $data = $request->validate([
            'question' => ['sometimes', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
            'closed' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('closed', $data)) {
            $poll->closed_at = $data['closed'] ? now() : null;
        }

        $poll->fill(collect($data)->only(['question', 'description'])->all())->save();

        $poll->load(['creator', 'options', 'votes', 'reactions']);
        $this->broadcast($channel, 'saved', $poll);

        return new AppPollResource($poll);
    }

    /**
     * Cast or change a vote.
     *
     * The whole body is the set of options you now stand behind, not a delta — so changing your
     * mind on a single-choice poll is one request that replaces what was there, and un-voting is
     * an empty array. A delta would need the client to know its own previous answer, which is
     * exactly the thing that goes stale when somebody votes from two tabs.
     */
    public function vote(TrackerRequest $request, Channel $channel, AppPoll $poll): AppPollResource
    {
        $this->authorizePoll($channel, $poll);

        abort_unless($poll->isOpen(), 422, 'This poll is closed.');

        $data = $request->validate([
            'option_ids' => ['present', 'array'],
            'option_ids.*' => ['integer'],
        ]);

        $valid = $poll->options()->whereIn('id', $data['option_ids'])->pluck('id');

        if (! $poll->allowsMultiple() && $valid->count() > 1) {
            throw ValidationException::withMessages([
                'option_ids' => 'This poll takes a single answer.',
            ]);
        }

        DB::transaction(function () use ($poll, $request, $valid) {
            // Replace wholesale — see the method note.
            $poll->votes()->where('user_id', $request->user()->id)->delete();

            foreach ($valid as $optionId) {
                $poll->votes()->create([
                    'option_id' => $optionId,
                    'user_id' => $request->user()->id,
                ]);
            }
        });

        $poll->load(['creator', 'options', 'votes', 'reactions']);
        $this->broadcast($channel, 'saved', $poll);

        return new AppPollResource($poll);
    }

    /** Takes its options, votes, comments, reactions and history with it. */
    public function destroy(TrackerRequest $request, Channel $channel, AppPoll $poll): Response
    {
        $this->authorizePoll($channel, $poll);

        $poll->delete();

        broadcast(new TrackerChanged('channel.'.$channel->id, 'poll', 'removed', ['id' => $poll->id]))->toOthers();

        return response()->noContent();
    }

    /** 404 rather than 403 — a poll in another channel may as well not exist. */
    private function authorizePoll(Channel $channel, AppPoll $poll): void
    {
        abort_unless($poll->channel_id === $channel->id, 404);
    }

    private function broadcast(Channel $channel, string $action, AppPoll $poll): void
    {
        broadcast(new TrackerChanged(
            'channel.'.$channel->id,
            'poll',
            $action,
            (new AppPollResource($poll))->resolve(),
        ))->toOthers();
    }
}
