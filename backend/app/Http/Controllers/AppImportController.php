<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tracker\TrackerRequest;
use App\Models\Channel;
use App\Support\Apps\AppImports;
use Illuminate\Http\JsonResponse;

/**
 * Bringing an app's content in from another channel.
 *
 * One controller for every app, the same way {@see AppCommentController} is one controller for
 * every commentable thing: the apps differ in what they store, not in what "import" means, and
 * {@see AppImports} is where that difference lives. Adding an importer is a row there.
 *
 * ## The question it answers
 *
 * Somebody's kanban board grew in a text channel's Side Desk and now wants to be an app channel
 * — or the reverse. Both storages are per-channel and identical in shape, so the copy is
 * well-defined; before this the only route across was retyping it.
 *
 * ## Authorisation
 *
 * Two channels, so two checks. `TrackerRequest` establishes membership of the *destination* in
 * the usual way, and the source is looked up through `Channel::visibleTo`, which is the same
 * scope search and the sidebar use. That second half is the one that matters: without it, an
 * import would be a way to read the contents of a private channel by copying it into your own.
 */
class AppImportController extends Controller
{
    /**
     * Where this app's content could come from — every channel you can see that has some.
     *
     * The app defaults to whatever this channel *is*, which covers the app-channel case with no
     * parameter; `?app=` names it for a Side Desk tab, where the channel is an ordinary text
     * channel and the tab is what's being filled.
     */
    public function sources(TrackerRequest $request, Channel $channel): JsonResponse
    {
        $app = $this->app($request, $channel);

        if (! AppImports::supports($app)) {
            // A real answer rather than a 422: the client asks this to decide whether to offer
            // the button at all, and "this app has nothing to import" is the answer for the
            // games and for an app that stores nothing per channel.
            return response()->json(['app' => $app, 'importable' => false, 'sources' => []]);
        }

        $candidates = Channel::query()
            ->visibleTo($request->user())
            ->whereKeyNot($channel->getKey())
            ->with('server:id,name')
            ->orderBy('name')
            // A ceiling rather than pagination: this is a picker, and somebody in four hundred
            // channels needs the search box the client already draws, not page two.
            ->limit(200)
            ->get();

        $sources = $candidates
            ->map(fn (Channel $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'server' => $source->server?->name,
                'count' => AppImports::count($source, $app),
            ])
            // Only channels that actually hold something. A list of every channel you're in,
            // almost all of them empty, is a list nobody can find anything in.
            ->filter(fn (array $row) => $row['count'] > 0)
            ->sortByDesc('count')
            ->values();

        return response()->json(['app' => $app, 'importable' => true, 'sources' => $sources]);
    }

    /** Copy it in. The source keeps everything — see {@see AppImports}. */
    public function store(TrackerRequest $request, Channel $channel): JsonResponse
    {
        $data = $request->validate([
            'source_channel_id' => ['required', 'integer'],
            'app' => ['sometimes', 'string'],
        ]);

        $app = $this->app($request, $channel);
        abort_unless(AppImports::supports($app), 422, 'There is nothing to import for this app.');

        $source = Channel::query()
            ->visibleTo($request->user())
            ->whereKey($data['source_channel_id'])
            ->first();

        // 404 rather than 403 for a channel you can't see, so an import can't be used to probe
        // which channel ids exist — the same rule AppSubjects follows.
        abort_if($source === null, 404);
        abort_if($source->id === $channel->id, 422, 'That is this channel.');

        $imported = AppImports::run($app, $source, $channel, $request->user());

        return response()->json(['imported' => $imported, 'app' => $app]);
    }

    /** The app being filled: what this channel is, unless the caller names another. */
    private function app(TrackerRequest $request, Channel $channel): string
    {
        return (string) ($request->input('app') ?? $request->query('app') ?? $channel->app?->app_id ?? '');
    }
}
