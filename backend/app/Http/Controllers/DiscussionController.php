<?php

namespace App\Http\Controllers;

use App\Actions\Channel\CreateDiscussionAction;
use App\Actions\Channel\DeleteChannelAction;
use App\Http\Requests\Channel\DeleteDiscussionRequest;
use App\Http\Requests\Channel\IndexDiscussionRequest;
use App\Http\Requests\Channel\SetDefaultDiscussionRequest;
use App\Http\Requests\Channel\StoreDiscussionRequest;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Services\ReadReceiptService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The conversations inside a channel.
 *
 * A discussion is a Channel with `parent_id` set, so there is no model, resource or broadcast
 * of its own here — only the things that are true of a discussion and not of a channel: it is
 * created inside something, it may not be the last one left, you can name one as where you'd
 * rather land, and a channel's worth of them reads as a directory rather than a sidebar.
 */
class DiscussionController extends Controller
{
    public function __construct(private readonly ReadReceiptService $reads) {}

    /** How the directory may be ordered. `active` is the default: a forum reads newest-first. */
    private const SORTS = ['active', 'created', 'name', 'busiest'];

    /**
     * Every discussion in a channel, as a directory rather than a menu.
     *
     * The menu in the header answers "where else can I go"; this answers "what is going on in
     * here", which needs two numbers the sidebar has never carried — how much has been said, and
     * when it was last said. Both are aggregates over the timeline, so they're counted here in
     * the query rather than fetched per row.
     */
    public function index(IndexDiscussionRequest $request, Channel $channel): AnonymousResourceCollection
    {
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'active';

        $query = $channel->discussions()
            // The same gate the sidebar uses, so a private discussion is absent from the
            // directory for the same reason it's absent from the tree.
            ->visibleTo($request->user())
            // Thread replies excluded, exactly as they are from the unread badge: a busy thread
            // is activity *inside* one conversation, not evidence of more of them. System notices
            // go too — "X joined the call" is not something anybody said.
            ->withCount(['messages' => $countable = fn ($q) => $q
                ->whereNull('thread_id')
                // Grouped, because the OR would otherwise escape the AND above it and count
                // every untyped thread reply in the channel.
                //
                // Asked this way round rather than as "type is null": a plain message written
                // through the API leaves the column null, but plenty of rows say 'user', and
                // `whereNotIn` alone would drop the nulls — so both halves are needed to mean
                // "anything somebody actually said".
                ->where(fn ($m) => $m->whereNotIn('type', ['system', 'widget'])->orWhereNull('type'))])
            ->withMax(['messages' => $countable], 'created_at');

        if ($term = trim((string) $request->query('q'))) {
            // Name only. Searching what people *said* is what the search palette is for, and it
            // already spans every channel the caller can see.
            $query->where('name', 'like', '%'.$term.'%');
        }

        match ($sort) {
            'name' => $query->reorder('name'),
            'created' => $query->reorder('id', 'desc'),
            'busiest' => $query->reorder('messages_count', 'desc')->orderBy('id', 'desc'),
            default => null,
        };

        $discussions = $query->get();

        // "Last activity" is sorted here rather than in SQL. Postgres will order by an aggregate
        // alias but not by an expression wrapping one, and the fallback is the whole point of the
        // ordering: a discussion nobody has posted in yet sorts by when it was *made*, so a
        // brand-new one leads the list instead of sinking to the bottom where it stays empty.
        // A channel's discussions are a list you can read, so sorting them in PHP costs nothing.
        if ($sort === 'active') {
            $discussions = $discussions
                ->sortByDesc(fn (Channel $d) => $d->messages_max_created_at ?? $d->created_at)
                ->values();
        }

        // The badge, same as the sidebar's, because this list is the other place you decide
        // where to go next — and "which of these has something new in it" is most of that
        // decision. One grouped query for the page, not one per row.
        $unread = $this->reads->unreadCounts($request->user(), $discussions->pluck('id')->all());

        $discussions->each(
            fn (Channel $d) => $d->unread_count = (int) ($unread[$d->id] ?? 0)
        );

        return ChannelResource::collection($discussions);
    }

    public function store(StoreDiscussionRequest $request, Channel $channel, CreateDiscussionAction $action): ChannelResource
    {
        // Addressed to the container. Being handed a discussion means somebody tried to nest one
        // inside another, which is the level of nesting that makes a sidebar unreadable.
        if ($channel->isDiscussion()) {
            throw ValidationException::withMessages([
                'channel' => 'Discussions live inside a channel, not inside another discussion.',
            ]);
        }

        $data = $request->validated();

        return new ChannelResource($action->handle(
            $channel,
            $data['name'],
            isset($data['copy_from']) ? Channel::find($data['copy_from']) : null,
        ));
    }

    /** Staff only. Takes the discussion's messages, threads and uploaded files with it. */
    public function destroy(DeleteDiscussionRequest $request, Channel $channel, DeleteChannelAction $action): Response
    {
        if (! $channel->isDiscussion()) {
            throw ValidationException::withMessages([
                'channel' => 'That is a channel, not a discussion. Delete the channel instead.',
            ]);
        }

        // A container with no discussions is a channel you can open but not read: every route
        // into it resolves to a child, and there would be none. Deleting the channel itself is
        // the thing this person is reaching for, and it's one endpoint over.
        if ($channel->parent()->withCount('discussions')->first()?->discussions_count === 1) {
            throw ValidationException::withMessages([
                'channel' => 'A channel needs at least one discussion. Delete the channel instead.',
            ]);
        }

        $action->handle($channel);

        return response()->noContent();
    }

    /**
     * "Take me here when I open this channel", or stop doing that.
     *
     * Stored per person on the container's read row, so it is a preference and not a setting:
     * one member pinning a discussion changes nothing for anybody else, and the channel still
     * opens on General for everyone who hasn't chosen.
     */
    public function setDefault(SetDefaultDiscussionRequest $request, Channel $channel): Response
    {
        $parent = $channel->parent;

        if (! $channel->isDiscussion() || $parent === null) {
            throw ValidationException::withMessages(['channel' => 'Only a discussion can be your default.']);
        }

        $parent->reads()->updateOrCreate(
            ['user_id' => $request->user()->getKey()],
            [
                'default_child_id' => $channel->getKey(),
                // The column is NOT NULL and this row may be created for the preference alone.
                // Harmless: a container holds no messages, so there is nothing here to be unread.
                'read_at' => now(),
            ],
        );

        return response()->noContent();
    }

    /** Clear it — the channel goes back to opening on its first discussion. */
    public function clearDefault(SetDefaultDiscussionRequest $request, Channel $channel): Response
    {
        $channel->parent?->reads()
            ->where('user_id', $request->user()->getKey())
            ->update(['default_child_id' => null]);

        return response()->noContent();
    }
}
