<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The watch-along video widget — a shared screening room for a channel.
 *
 * It borrows {@see MusicWidget}'s central idea: the server owns the *transport* (the
 * playlist, which video is current, playing/paused, speed, and the position as of
 * `updated_at`) and never a single frame of video. It is deliberately not a clock — while
 * something plays, `position` isn't ticked here. `position` + `updated_at` + `speed` let
 * every viewer, including one who arrives late, work out where the room is now and put their
 * own player there. That's what keeps a room watching together without us restreaming
 * anything.
 *
 * What it plays is the part that isn't like music. A source declares the engine that can play
 * it ({@see VideoResolver}), and only two of those can be *driven*: YouTube's IFrame player
 * and a plain <video> (an upload, or a direct .mp4/.webm link). Those stay in lockstep. A
 * third-party `embed` — Vimeo, Dailymotion, Twitch, Streamable — plays for everyone from the
 * same starting offset and then goes its own way, because the provider's iframe won't take a
 * seek from us. The card labels those rather than pretending they're synced.
 *
 * Local video reaches the playlist two ways, and the difference matters. An *upload* is a file
 * the widget hosts itself. An *attachment* is a video already posted somewhere in this chat —
 * the timeline, a thread, a side chat — added by reference, so nothing is copied and removing
 * it from the playlist can't delete it out of the conversation. {@see VideoLibrary} owns both.
 *
 * Neither a disk path nor an attachment id may reach a browser, so this handler
 * {@see RedactsState}: each viewer gets a freshly-minted `url` and none of the bookkeeping
 * behind it.
 *
 * State shape:
 *   status:        'idle' | 'playing' | 'paused'
 *   playlist:      [ { id, kind, key, url, embedUrl, provider, title, author, duration,
 *                      thumbnail, addedBy, (disk, path, mime, size — uploads, server-only),
 *                      (attachmentId — borrowed attachments, server-only) } ]
 *   currentIndex:  int|null  — index into playlist, null when nothing is seated
 *   position:      float     — seconds into the current video at `updated_at`
 *   updated_at:    iso8601   — server time position/status/speed was last set
 *   loop:          'off' | 'one' | 'all'
 *   speed:         float     — playback rate (0.25–2), shared so everyone matches
 *   pendingSearch: null | { query, by, results: [Source] }  — the picker, awaiting a choice
 */
final class VideoWidget implements RedactsState, WidgetHandler
{
    private const MIN_SPEED = 0.25;

    private const MAX_SPEED = 2.0;

    /** A playlist is a sitting, not an archive — and every upload in it costs disk. */
    private const MAX_ITEMS = 100;

    public function __construct(
        private readonly VideoResolver $resolver,
        private readonly VideoLibrary $library,
    ) {}

    public function type(): string
    {
        return 'video';
    }

    public function initialState(): array
    {
        return [
            'status' => 'idle',
            'playlist' => [],
            'currentIndex' => null,
            'position' => 0,
            'updated_at' => now()->toIso8601String(),
            'loop' => 'off',
            'speed' => 1,
            'pendingSearch' => null,
        ];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'p', 'play', 'add', 'watch' => $this->play($widget, $user, $command->args),
            'pn', 'playnext' => $this->playNext($widget, $user, $command->args),
            'search', 'find' => $this->search($widget, $user, $command->args),
            'pause' => $this->pause($widget),
            'resume' => $this->resume($widget),
            'next', 'skip', 'n' => $this->next($widget),
            'prev', 'back', 'previous' => $this->prev($widget),
            'stop' => $this->stop($widget),
            'seek' => $this->seek($widget, $command->firstArg()),
            'move', 'mv' => $this->moveCmd($widget, $command),
            'remove', 'rm' => $this->remove($widget, $command->firstArg()),
            'clear' => $this->clear($widget),
            'shuffle' => $this->shuffle($widget),
            'loop', 'repeat' => $this->cycleLoop($widget),
            'speed' => $this->setSpeed($widget, (float) $command->firstArg()),
            'normal' => $this->setSpeed($widget, 1.0),
            'list', 'queue', 'q', 'np' => WidgetOutcome::show(),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown video command `v!{$command->verb}`. Try `v!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        return match ($action) {
            // The card's own "add" field — the button twin of `v!play <link>`, so a card on the
            // Open Canvas (with no composer behind it) can still add something.
            'add' => $this->play($widget, $user, (string) ($payload['query'] ?? '')),
            // A file the browser already staged through the chunked-upload endpoint; all that
            // arrives here is the id it can be claimed by.
            'upload' => $this->addUpload($widget, $user, (string) ($payload['upload'] ?? '')),
            // A video already posted somewhere in this chat, picked from the card's library.
            'addAttachment' => $this->addAttachment($widget, $user, (int) ($payload['attachment'] ?? 0)),
            'pause' => $this->pause($widget),
            'resume' => $this->resume($widget),
            'next' => $this->next($widget),
            'prev' => $this->prev($widget),
            'stop' => $this->stop($widget),
            'seek' => $this->seekTo($widget, (float) ($payload['position'] ?? 0)),
            'jump' => $this->jump($widget, (int) ($payload['index'] ?? -1)),
            'move' => $this->move($widget, (int) ($payload['from'] ?? 0), (int) ($payload['to'] ?? 0)),
            'remove' => $this->removeById($widget, (string) ($payload['id'] ?? '')),
            'clear' => $this->clear($widget),
            'shuffle' => $this->shuffle($widget),
            'loop' => $this->cycleLoop($widget),
            'speed' => $this->setSpeed($widget, (float) ($payload['value'] ?? 1)),
            'pick' => $this->pick($widget, $user, (int) ($payload['index'] ?? -1)),
            'cancelSearch' => $this->cancelSearch($widget),
            // A viewer's player learned the real length — persist it once, so the list can show
            // durations for sources no metadata lookup covered (uploads, direct links).
            'meta' => $this->backfillDuration($widget, (string) ($payload['id'] ?? ''), (int) ($payload['duration'] ?? 0)),
            // A player ran off the end. Guarded by `id` so a stale tab can't skip a video
            // someone already moved past.
            'ended' => $this->videoEnded($widget, (string) ($payload['id'] ?? '')),
            default => WidgetOutcome::noop(),
        };
    }

    /**
     * Hand each viewer a *playable* playlist: an uploaded clip's disk location is replaced
     * with a short-lived signed URL, which is the only form of it that may leave the server.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function forViewer(Widget $widget, array $state, ?User $viewer): array
    {
        foreach (($state['playlist'] ?? []) as $i => $source) {
            if (! is_array($source)) {
                continue;
            }

            if (($source['provider'] ?? null) === 'upload') {
                $state['playlist'][$i]['url'] = $this->library->signedUrl((int) $widget->id, (string) $source['id']);
                unset($state['playlist'][$i]['disk'], $state['playlist'][$i]['path']);

                continue;
            }

            // A borrowed attachment: the URL is the *message's* own signed one, fetched fresh
            // each time. If it comes back null the file has gone (its message was deleted) —
            // flag it rather than handing the card a link to nothing, and drop the id, which
            // is no use to a browser.
            if (($source['provider'] ?? null) === 'attachment') {
                $url = $this->library->attachmentUrl((int) ($source['attachmentId'] ?? 0));
                $state['playlist'][$i]['url'] = $url;
                if ($url === null) {
                    $state['playlist'][$i]['missing'] = true;
                }
                unset($state['playlist'][$i]['attachmentId']);
            }
        }

        return $state;
    }

    // --- adding videos ------------------------------------------------------

    private function play(Widget $widget, User $user, string $args): WidgetOutcome
    {
        $args = trim($args);
        if ($args === '') {
            return $this->resume($widget); // `v!play` on its own resumes
        }

        // A link is added straight away; plain words open the picker instead.
        if (! $this->resolver->looksLikeLink($args)) {
            return $this->search($widget, $user, $args);
        }

        $result = $this->resolver->resolveLink($args);
        if ($result['error'] !== null) {
            return WidgetOutcome::reply($result['error']);
        }

        $state = $widget->state;
        if (! $this->append($state, $result['sources'], $user->name)) {
            return WidgetOutcome::reply('This playlist is full ('.self::MAX_ITEMS.' videos). Remove something first.');
        }
        $widget->state = $state;

        return WidgetOutcome::card();
    }

    private function playNext(Widget $widget, User $user, string $args): WidgetOutcome
    {
        $args = trim($args);
        if ($args === '') {
            return WidgetOutcome::reply('What should play next? `v!pn <link>`.');
        }

        $result = $this->resolver->looksLikeLink($args)
            ? $this->resolver->resolveLink($args)
            : $this->resolver->search($args, 1);
        if ($result['error'] !== null) {
            return WidgetOutcome::reply($result['error']);
        }

        $state = $widget->state;
        if (count($state['playlist']) + count($result['sources']) > self::MAX_ITEMS) {
            return WidgetOutcome::reply('This playlist is full ('.self::MAX_ITEMS.' videos). Remove something first.');
        }

        if ($state['currentIndex'] === null) {
            $this->append($state, $result['sources'], $user->name);
        } else {
            $sources = array_map(fn ($s) => $this->makeSource($s, $user->name), $result['sources']);
            array_splice($state['playlist'], $state['currentIndex'] + 1, 0, $sources);
        }
        $widget->state = $state;

        return WidgetOutcome::card();
    }

    /** Stage a file the browser already uploaded, and start it if nothing was playing. */
    private function addUpload(Widget $widget, User $user, string $uploadId): WidgetOutcome
    {
        if ($uploadId === '') {
            return WidgetOutcome::noop();
        }

        $state = $widget->state;
        if (count($state['playlist']) >= self::MAX_ITEMS) {
            return WidgetOutcome::reply('This playlist is full ('.self::MAX_ITEMS.' videos). Remove something first.');
        }

        $source = $this->library->claim($uploadId, $user, (int) $widget->channel_id);
        if ($source === null) {
            return WidgetOutcome::reply("That upload couldn't be added — it has to be a finished video file you uploaded.");
        }

        $this->append($state, [$source], $user->name);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /**
     * Add a video already posted in this chat — the picker's counterpart to an upload.
     *
     * Only the reference is stored, so this costs nothing on disk and the file stays where it
     * was posted. `addedBy` is whoever put it on the playlist, not whoever posted it
     * originally; the source keeps that name in `author`.
     */
    private function addAttachment(Widget $widget, User $user, int $attachmentId): WidgetOutcome
    {
        if ($attachmentId <= 0) {
            return WidgetOutcome::noop();
        }

        $state = $widget->state;
        if (count($state['playlist']) >= self::MAX_ITEMS) {
            return WidgetOutcome::reply('This playlist is full ('.self::MAX_ITEMS.' videos). Remove something first.');
        }

        $source = $this->library->claimAttachment($attachmentId, (int) $widget->channel_id);
        if ($source === null) {
            return WidgetOutcome::reply("That file couldn't be added — it has to be a video posted in this chat.");
        }

        $this->append($state, [$source], $user->name);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function search(Widget $widget, User $user, string $query): WidgetOutcome
    {
        $query = trim($query);
        if ($query === '') {
            return WidgetOutcome::reply('Search for what? `v!search <words>`.');
        }

        $result = $this->resolver->search($query, 5);
        if ($result['error'] !== null) {
            return WidgetOutcome::reply($result['error']);
        }

        $state = $widget->state;
        $state['pendingSearch'] = ['query' => $query, 'by' => $user->name, 'results' => $result['sources']];
        $widget->state = $state;

        return WidgetOutcome::card();
    }

    private function pick(Widget $widget, User $user, int $index): WidgetOutcome
    {
        $state = $widget->state;
        $results = $state['pendingSearch']['results'] ?? [];
        if (! isset($results[$index])) {
            return WidgetOutcome::noop();
        }

        $this->append($state, [$results[$index]], $user->name);
        $state['pendingSearch'] = null;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function cancelSearch(Widget $widget): WidgetOutcome
    {
        if (($widget->state['pendingSearch'] ?? null) === null) {
            return WidgetOutcome::noop();
        }
        $state = $widget->state;
        $state['pendingSearch'] = null;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /**
     * Append sources, seating the first one if nothing was playing. Mutates $state by
     * reference; false when the playlist has no room left.
     *
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function append(array &$state, array $sources, string $addedBy): bool
    {
        if (count($state['playlist']) + count($sources) > self::MAX_ITEMS) {
            return false;
        }

        $wasEmpty = $state['playlist'] === [] || $state['currentIndex'] === null;

        foreach ($sources as $source) {
            $state['playlist'][] = $this->makeSource($source, $addedBy);
        }

        if ($wasEmpty && $state['playlist'] !== []) {
            $this->seat($state, 0);
        }

        return true;
    }

    /** @param  array<string, mixed>  $source */
    private function makeSource(array $source, string $addedBy): array
    {
        // An upload already carries its own server-only keys; keep them and add the rest.
        return array_merge($source, [
            'id' => (string) Str::uuid(),
            'kind' => $source['kind'] ?? 'embed',
            'key' => $source['key'] ?? null,
            'url' => $source['url'] ?? null,
            'embedUrl' => $source['embedUrl'] ?? null,
            'provider' => $source['provider'] ?? 'unknown',
            'title' => $source['title'] ?? 'Video',
            'author' => $source['author'] ?? null,
            'duration' => $source['duration'] ?? null,
            'thumbnail' => $source['thumbnail'] ?? null,
            'addedBy' => $addedBy,
        ]);
    }

    // --- transport ----------------------------------------------------------

    private function pause(Widget $widget): WidgetOutcome
    {
        if ($widget->state['status'] !== 'playing') {
            return WidgetOutcome::reply('Nothing is playing.');
        }

        $state = $widget->state;
        $state['position'] = $this->livePosition($state);
        $state['status'] = 'paused';
        $state['updated_at'] = now()->toIso8601String();
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function resume(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['currentIndex'] === null) {
            return WidgetOutcome::reply('Nothing queued. Add something with `v!play <link>`, or upload a file.');
        }
        if ($state['status'] === 'playing') {
            return WidgetOutcome::show();
        }

        $state['status'] = 'playing';
        $state['updated_at'] = now()->toIso8601String();
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function next(Widget $widget): WidgetOutcome
    {
        return $this->advance($widget, +1) ?? WidgetOutcome::reply('Nothing to skip to.');
    }

    private function prev(Widget $widget): WidgetOutcome
    {
        return $this->advance($widget, -1) ?? WidgetOutcome::reply('Nothing before this.');
    }

    /** Step through the playlist by hand. Null when there's nowhere to go. */
    private function advance(Widget $widget, int $step): ?WidgetOutcome
    {
        $state = $widget->state;
        $count = count($state['playlist']);
        if ($count === 0 || $state['currentIndex'] === null) {
            return null;
        }

        $next = $state['currentIndex'] + $step;
        if ($state['loop'] === 'all') {
            $next = ($next % $count + $count) % $count; // wrap both ways
        } elseif ($next < 0 || $next >= $count) {
            return $this->stop($widget); // off the end with no looping → stop at the end
        }

        return $this->goTo($widget, $next);
    }

    private function videoEnded(Widget $widget, string $endedId): WidgetOutcome
    {
        // Every viewer's player fires 'ended' at once. Only the first should count: once it
        // advances, `currentIndex` moves, so the stale duplicates (still naming the old video)
        // no longer match and fall through as no-ops.
        $state = $widget->state;
        $current = $state['currentIndex'] ?? null;
        if ($current === null || (string) ($state['playlist'][$current]['id'] ?? '') !== $endedId) {
            return WidgetOutcome::noop();
        }

        if ($state['loop'] === 'one') {
            return $this->goTo($widget, $current); // play it again
        }

        $count = count($state['playlist']);
        if ($current + 1 < $count) {
            return $this->goTo($widget, $current + 1);
        }

        return $state['loop'] === 'all' ? $this->goTo($widget, 0) : $this->stop($widget);
    }

    /** Seat the video at $index and start it from the top. */
    private function goTo(Widget $widget, int $index): WidgetOutcome
    {
        $state = $widget->state;
        if (! isset($state['playlist'][$index])) {
            return $this->stop($widget);
        }

        $this->seat($state, $index);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function seat(array &$state, int $index): void
    {
        $state['currentIndex'] = $index;
        $state['status'] = 'playing';
        $state['position'] = 0;
        $state['updated_at'] = now()->toIso8601String();
    }

    private function jump(Widget $widget, int $index): WidgetOutcome
    {
        return isset($widget->state['playlist'][$index]) ? $this->goTo($widget, $index) : WidgetOutcome::noop();
    }

    /**
     * Stop the screening but keep the playlist.
     *
     * This is where the video widget parts company with the music one, whose `m!stop` empties
     * the queue. A watch-along playlist is assembled deliberately — and can contain files
     * people uploaded — so "stop" means the room stops watching, not that the evening's
     * viewing is thrown away. Emptying it is `v!clear`, which asks for it explicitly.
     */
    private function stop(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $state['status'] = 'idle';
        $state['position'] = 0;
        $state['updated_at'] = now()->toIso8601String();
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function seek(Widget $widget, string $time): WidgetOutcome
    {
        if ($widget->state['currentIndex'] === null) {
            return WidgetOutcome::reply('Nothing is playing to seek.');
        }

        return $this->seekTo($widget, $this->parseTime($time));
    }

    private function seekTo(Widget $widget, float $position): WidgetOutcome
    {
        if ($widget->state['currentIndex'] === null) {
            return WidgetOutcome::noop();
        }
        $state = $widget->state;
        $state['position'] = max(0, $position);
        $state['updated_at'] = now()->toIso8601String();
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    // --- playlist management ------------------------------------------------

    private function moveCmd(Widget $widget, ParsedCommand $command): WidgetOutcome
    {
        $from = (int) $command->firstArg();
        $to = (int) $command->restAfterFirst();
        if ($from < 1 || $to < 1) {
            return WidgetOutcome::reply('Move which? `v!move <from> <to>` (positions in the up-next list).');
        }

        return $this->move($widget, $from, $to);
    }

    /** Reorder by 1-based positions within the *upcoming* list, like the music queue. */
    private function move(Widget $widget, int $from, int $to): WidgetOutcome
    {
        $state = $widget->state;
        $base = ($state['currentIndex'] ?? -1) + 1;
        $upcomingCount = count($state['playlist']) - $base;

        if ($from < 1 || $to < 1 || $from > $upcomingCount || $to > $upcomingCount || $from === $to) {
            return WidgetOutcome::noop();
        }

        $moved = array_splice($state['playlist'], $base + ($from - 1), 1);
        array_splice($state['playlist'], $base + ($to - 1), 0, $moved);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function remove(Widget $widget, string $ordinal): WidgetOutcome
    {
        $n = (int) $ordinal;
        if ($n < 1) {
            return WidgetOutcome::reply('Which one? `v!remove <number>` from the up-next list.');
        }

        $state = $widget->state;
        $target = ($state['currentIndex'] ?? -1) + $n;
        if (! isset($state['playlist'][$target])) {
            return WidgetOutcome::reply("There's no #{$n} in the list.");
        }

        return $this->removeById($widget, (string) $state['playlist'][$target]['id']);
    }

    private function removeById(Widget $widget, string $id): WidgetOutcome
    {
        $state = $widget->state;
        $index = null;
        foreach ($state['playlist'] as $i => $source) {
            if ((string) $source['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return WidgetOutcome::noop();
        }

        $isCurrent = $index === $state['currentIndex'];
        $removed = array_splice($state['playlist'], $index, 1);
        // Nothing else can reference these bytes — one source owns its file.
        $this->library->forgetAll($removed);

        if ($state['currentIndex'] !== null && $index < $state['currentIndex']) {
            $state['currentIndex']--;
        }

        if ($state['playlist'] === []) {
            $state['currentIndex'] = null;
            $widget->state = $state;

            return $this->stop($widget);
        }

        if ($isCurrent) {
            $widget->state = $state;

            // The removed video was the one on screen — roll straight into whatever took its
            // place, or the last one if it was the tail.
            return $this->goTo($widget, min((int) $state['currentIndex'], count($state['playlist']) - 1));
        }

        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /** Empty the playlist — and, with it, delete every clip the room uploaded into it. */
    private function clear(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['playlist'] === []) {
            return WidgetOutcome::reply('The playlist is already empty.');
        }

        $this->library->forgetAll($state['playlist']);

        $fresh = $this->initialState();
        $fresh['loop'] = $state['loop'] ?? 'off';
        $fresh['speed'] = $state['speed'] ?? 1;
        $widget->state = $fresh;

        return WidgetOutcome::updated();
    }

    private function shuffle(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $current = $state['currentIndex'];
        if ($current === null || count($state['playlist']) < 3) {
            return WidgetOutcome::reply('Not enough queued to shuffle.');
        }

        $upcoming = array_slice($state['playlist'], $current + 1);
        shuffle($upcoming);
        $state['playlist'] = array_merge(array_slice($state['playlist'], 0, $current + 1), $upcoming);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    // --- modes --------------------------------------------------------------

    private function cycleLoop(Widget $widget): WidgetOutcome
    {
        $order = ['off' => 'one', 'one' => 'all', 'all' => 'off'];
        $state = $widget->state;
        $state['loop'] = $order[$state['loop']] ?? 'off';
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function setSpeed(Widget $widget, float $value): WidgetOutcome
    {
        $value = round(max(self::MIN_SPEED, min(self::MAX_SPEED, $value ?: 1)), 2);
        $state = $widget->state;
        // Freeze where we are before changing the slope, so the position doesn't jump.
        $state['position'] = $this->livePosition($state);
        $state['speed'] = $value;
        $state['updated_at'] = now()->toIso8601String();
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function backfillDuration(Widget $widget, string $id, int $duration): WidgetOutcome
    {
        if ($duration <= 0) {
            return WidgetOutcome::noop();
        }
        $state = $widget->state;
        foreach ($state['playlist'] as $i => $source) {
            if ((string) $source['id'] === $id && empty($source['duration'])) {
                $state['playlist'][$i]['duration'] = $duration;
                $widget->state = $state;

                return WidgetOutcome::updated();
            }
        }

        return WidgetOutcome::noop();
    }

    // --- helpers ------------------------------------------------------------

    /** Where the current video should be *now*, given it's played since `updated_at` at `speed`. */
    private function livePosition(array $state): float
    {
        $base = (float) ($state['position'] ?? 0);
        if (($state['status'] ?? 'idle') !== 'playing') {
            return $base;
        }
        $elapsed = now()->diffInMilliseconds(Carbon::parse($state['updated_at']), absolute: true) / 1000;

        return $base + $elapsed * (float) ($state['speed'] ?? 1);
    }

    /** `1:23` / `83` / `1:02:03` → seconds. */
    private function parseTime(string $time): float
    {
        $seconds = 0;
        foreach (array_map('intval', explode(':', trim($time))) as $part) {
            $seconds = $seconds * 60 + $part;
        }

        return (float) $seconds;
    }

    private function help(): string
    {
        return implode("\n", [
            '📺 **Video commands**',
            '`v!play <link/search>` — add & watch (YouTube, Vimeo, Dailymotion, Twitch, Streamable, .mp4/.webm, or words)',
            '`v!pn <link>` — play next · `v!search <words>` — pick from results',
            'Drop a file on the card to upload one, or browse videos already posted in this chat from the card',
            '`v!pause` · `v!resume` · `v!next` · `v!prev` · `v!stop` (keeps the playlist)',
            '`v!seek 1:30` · `v!move <from> <to>` · `v!remove <n>` · `v!clear` (empties it) · `v!shuffle`',
            '`v!loop` (off/one/all) · `v!speed 1.5` · `v!list` — bring the card back to the bottom',
        ]);
    }
}
