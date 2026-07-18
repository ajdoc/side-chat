<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The listen-along music player — a bot-style player in the spirit of Jockie.
 *
 * The server owns the *transport* — the queue, which track is current, playing/paused, the
 * playback speed, and the position as of `updated_at`. It is deliberately not a clock:
 * while a track plays, position isn't ticked here. Instead `position` + `updated_at` +
 * `speed` let every client (including one that joins late) compute "where should I be right
 * now?" and seek their own YouTube player there — see the frontend MusicPlayer. That's what
 * keeps a room in sync without the server streaming a single byte of audio.
 *
 * State shape:
 *   status:        'idle' | 'playing' | 'paused'
 *   queue:         [ { id, videoId, title, artist, duration, thumbnail, source, addedBy } ]
 *   currentIndex:  int|null  — index into queue, null when idle
 *   position:      float      — seconds into the current track at `updated_at`
 *   updated_at:    iso8601     — server time position/status/speed was last set
 *   loop:          'off' | 'track' | 'queue'
 *   speed:         float       — playback rate (0.5–2); >1 is the "nightcore" effect
 *   autoplay:      bool        — keep the music going with a related track when the queue ends
 *   pendingSearch: null | { query, by, results: [Track] }  — the search picker, awaiting a choice
 */
final class MusicWidget implements WidgetHandler
{
    private const MIN_SPEED = 0.5;

    private const MAX_SPEED = 2.0;

    public function __construct(private readonly YouTubeResolver $resolver) {}

    public function type(): string
    {
        return 'music';
    }

    public function initialState(): array
    {
        return [
            'status' => 'idle',
            'queue' => [],
            'currentIndex' => null,
            'position' => 0,
            'updated_at' => now()->toIso8601String(),
            'loop' => 'off',
            'speed' => 1,
            'autoplay' => false,
            'pendingSearch' => null,
        ];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'p', 'play' => $this->play($widget, $user, $command->args),
            'pn', 'playnext', 'next!' => $this->playNext($widget, $user, $command->args),
            'search', 'find' => $this->search($widget, $user, $command->args),
            'pause' => $this->pause($widget),
            'resume' => $this->resume($widget),
            'next', 'skip', 'n' => $this->next($widget),
            'prev', 'back', 'previous' => $this->prev($widget),
            'stop', 'leave', 'dc' => $this->stop($widget),
            'seek' => $this->seek($widget, $command->firstArg()),
            'move', 'mv' => $this->moveCmd($widget, $command),
            'remove', 'rm' => $this->remove($widget, $command->firstArg()),
            'clear' => $this->clear($widget),
            'shuffle' => $this->shuffle($widget),
            'loop', 'repeat' => $this->cycleLoop($widget),
            'autoplay', 'radio' => $this->toggleAutoplay($widget),
            'speed' => $this->setSpeed($widget, (float) $command->firstArg()),
            'nightcore' => $this->setSpeed($widget, 1.3),
            'vaporwave', 'daycore' => $this->setSpeed($widget, 0.8),
            'normal' => $this->setSpeed($widget, 1.0),
            'queue', 'q', 'np', 'nowplaying' => WidgetOutcome::show(),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown music command `m!{$command->verb}`. Try `m!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        return match ($action) {
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
            'autoplay' => $this->toggleAutoplay($widget),
            'speed' => $this->setSpeed($widget, (float) ($payload['value'] ?? 1)),
            'pick' => $this->pick($widget, $user, (int) ($payload['index'] ?? -1)),
            'cancelSearch' => $this->cancelSearch($widget),
            // A client learned the current track's real length from its player — persist it
            // once so the queue can show durations even for keyless (oEmbed-only) tracks.
            'meta' => $this->backfillDuration($widget, (string) ($payload['id'] ?? ''), (int) ($payload['duration'] ?? 0)),
            // A client's player ran off the end. Guarded by `id` so a stale tab can't skip a
            // track someone already changed.
            'ended' => $this->trackEnded($widget, (string) ($payload['id'] ?? '')),
            default => WidgetOutcome::noop(),
        };
    }

    // --- adding music -------------------------------------------------------

    private function play(Widget $widget, User $user, string $args): WidgetOutcome
    {
        $args = trim($args);
        if ($args === '') {
            return $this->resume($widget); // `m!play` on its own resumes
        }

        // A link enqueues straight away; plain words open the picker instead.
        if (! $this->resolver->looksLikeLink($args)) {
            return $this->search($widget, $user, $args);
        }

        $result = $this->resolver->resolveLink($args);
        if ($result['error'] !== null) {
            return WidgetOutcome::reply($result['error']);
        }

        $state = $widget->state;
        $this->enqueue($state, $result['tracks'], $user->name);
        $widget->state = $state;

        return WidgetOutcome::card();
    }

    private function playNext(Widget $widget, User $user, string $args): WidgetOutcome
    {
        $args = trim($args);
        if ($args === '') {
            return WidgetOutcome::reply('What should play next? `m!pn <link or search>`.');
        }

        $result = $this->resolver->looksLikeLink($args)
            ? $this->resolver->resolveLink($args)
            : $this->resolver->searchMany($args, 1);
        if ($result['error'] !== null) {
            return WidgetOutcome::reply($result['error']);
        }

        $state = $widget->state;
        if ($state['currentIndex'] === null) {
            $this->enqueue($state, $result['tracks'], $user->name);
        } else {
            $tracks = array_map(fn ($t) => $this->makeTrack($t, $user->name), $result['tracks']);
            array_splice($state['queue'], $state['currentIndex'] + 1, 0, $tracks);
        }
        $widget->state = $state;

        return WidgetOutcome::card();
    }

    private function search(Widget $widget, User $user, string $query): WidgetOutcome
    {
        $query = trim($query);
        if ($query === '') {
            return WidgetOutcome::reply('Search for what? `m!search <words>`.');
        }

        $result = $this->resolver->searchMany($query, 5);
        if ($result['error'] !== null) {
            return WidgetOutcome::reply($result['error']);
        }

        $state = $widget->state;
        $state['pendingSearch'] = [
            'query' => $query,
            'by' => $user->name,
            'results' => $result['tracks'],
        ];
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

        $this->enqueue($state, [$results[$index]], $user->name);
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
     * Append tracks, starting playback if nothing was going. Mutates $state by reference.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     */
    private function enqueue(array &$state, array $tracks, string $addedBy): void
    {
        $wasEmpty = empty($state['queue']) || $state['currentIndex'] === null;

        foreach ($tracks as $track) {
            $state['queue'][] = $this->makeTrack($track, $addedBy);
        }

        // Kick off the first track. It may be a lazily-resolved Spotify shell, so seat it
        // through the resolver, skipping anything that turns out to be unplayable.
        if ($wasEmpty && ! empty($state['queue'])) {
            $this->resolveAndSeat($state, 0, +1);
        }
    }

    /** @param  array<string, mixed>  $track */
    private function makeTrack(array $track, string $addedBy): array
    {
        return [
            'id' => (string) Str::uuid(),
            // Null for a Spotify shell — filled in by resolveAt when it's about to play.
            'videoId' => $track['videoId'] ?? null,
            // Present for Spotify tracks — Premium listeners play this instead of YouTube.
            'spotifyUri' => $track['spotifyUri'] ?? null,
            'title' => $track['title'] ?? 'Unknown',
            'artist' => $track['artist'] ?? null,
            'duration' => $track['duration'] ?? null,
            'thumbnail' => $track['thumbnail'] ?? null,
            'source' => $track['source'] ?? 'youtube',
            // The YouTube search a shell resolves through ("artist title"); absent once it has a videoId.
            'query' => $track['query'] ?? null,
            'addedBy' => $addedBy,
        ];
    }

    /**
     * Seat the current track at (or after) $index, resolving lazily and skipping any track
     * that can't be turned into something playable. Mutates $state; returns whether it
     * managed to seat anything.
     */
    private function resolveAndSeat(array &$state, int $index, int $step): bool
    {
        $guard = count($state['queue']) + 1;

        while (isset($state['queue'][$index]) && $guard-- > 0) {
            if ($this->resolveAt($state, $index)) {
                $state['currentIndex'] = $index;
                $state['status'] = 'playing';
                $state['position'] = 0;
                $state['updated_at'] = now()->toIso8601String();

                return true;
            }
            // Unplayable — keep going the way we were headed (skip a dud, don't get stuck).
            $index += $step >= 0 ? 1 : -1;
        }

        return false;
    }

    /**
     * Make sure the track at $index has a playable YouTube videoId, searching for it now if
     * it's a shell. Returns false (and flags the track) when nothing suitable turns up.
     */
    private function resolveAt(array &$state, int $index): bool
    {
        $track = $state['queue'][$index] ?? null;
        if ($track === null) {
            return false;
        }
        if (! empty($track['videoId'])) {
            return true; // already playable (a real YouTube track)
        }

        $query = $track['query'] ?? trim(($track['artist'] ?? '').' '.($track['title'] ?? ''));
        $result = $query === '' ? ['tracks' => [], 'error' => 'empty'] : $this->resolver->searchMany($query, 1);
        $match = $result['tracks'][0] ?? null;

        if ($match === null) {
            $state['queue'][$index]['unresolved'] = true; // a client can grey it out

            return false;
        }

        $state['queue'][$index]['videoId'] = $match['videoId'];
        // Keep the shell's own (Spotify) title/artist/art; only borrow a duration if it lacked one.
        if (empty($state['queue'][$index]['duration'])) {
            $state['queue'][$index]['duration'] = $match['duration'];
        }
        unset($state['queue'][$index]['unresolved']);

        return true;
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
            return WidgetOutcome::reply('The queue is empty. Add something with `m!p <link>`.');
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

    /** Move `step` tracks through the queue (manual skip). Null when there's nowhere to go. */
    private function advance(Widget $widget, int $step): ?WidgetOutcome
    {
        $state = $widget->state;
        $count = count($state['queue']);
        if ($count === 0 || $state['currentIndex'] === null) {
            return null;
        }

        $next = $state['currentIndex'] + $step;
        if ($state['loop'] === 'queue') {
            $next = ($next % $count + $count) % $count; // wrap both ways
        } elseif ($next < 0 || $next >= $count) {
            return $this->stop($widget); // off the end with no looping → stop
        }

        return $this->goTo($widget, $next, $step);
    }

    private function trackEnded(Widget $widget, string $endedId): WidgetOutcome
    {
        // Every listener's player fires 'ended' at once. Only the first should count: once
        // it advances, `currentIndex` moves, so the stale duplicates (still naming the old
        // track) no longer match and fall through as no-ops.
        $state = $widget->state;
        $current = $state['currentIndex'] ?? null;
        if ($current === null || (string) ($state['queue'][$current]['id'] ?? '') !== $endedId) {
            return WidgetOutcome::noop();
        }

        if ($state['loop'] === 'track') {
            return $this->goTo($widget, $current); // replay the same track
        }

        $count = count($state['queue']);
        if ($current + 1 < $count) {
            return $this->goTo($widget, $current + 1);
        }

        // The queue just ran dry.
        if ($state['loop'] === 'queue') {
            return $this->goTo($widget, 0);
        }
        if ($state['autoplay']) {
            return $this->autoplayNext($widget);
        }

        return $this->stop($widget);
    }

    /** Radio mode: fetch a related track, queue it and roll straight into it. */
    private function autoplayNext(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $current = $state['queue'][$state['currentIndex']] ?? null;
        if ($current === null) {
            return $this->stop($widget);
        }

        $related = $this->resolver->relatedTo($current, $this->videoIds($state));
        if ($related === null) {
            return $this->stop($widget);
        }

        $state['queue'][] = $this->makeTrack($related, 'Autoplay');
        $widget->state = $state;

        return $this->goTo($widget, count($state['queue']) - 1);
    }

    /**
     * Seat the current track at (or, if it's an unplayable shell, after) $index and start
     * it from the top. `$step` is the direction to skip duds in — forward by default.
     */
    private function goTo(Widget $widget, int $index, int $step = 1): WidgetOutcome
    {
        $state = $widget->state;
        $seated = $this->resolveAndSeat($state, $index, $step);
        $widget->state = $state; // persist any resolved videoIds / unresolved flags

        return $seated ? WidgetOutcome::updated() : $this->stop($widget);
    }

    private function jump(Widget $widget, int $index): WidgetOutcome
    {
        return isset($widget->state['queue'][$index]) ? $this->goTo($widget, $index) : WidgetOutcome::noop();
    }

    private function stop(Widget $widget): WidgetOutcome
    {
        // Keep the room's preferences (loop/speed/autoplay), just empty the deck.
        $state = $this->initialState();
        $state['loop'] = $widget->state['loop'] ?? 'off';
        $state['speed'] = $widget->state['speed'] ?? 1;
        $state['autoplay'] = $widget->state['autoplay'] ?? false;
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

    // --- queue management ---------------------------------------------------

    private function moveCmd(Widget $widget, ParsedCommand $command): WidgetOutcome
    {
        $from = (int) $command->firstArg();
        $to = (int) $command->restAfterFirst();
        if ($from < 1 || $to < 1) {
            return WidgetOutcome::reply('Move which? `m!move <from> <to>` (positions in the queue).');
        }

        return $this->move($widget, $from, $to);
    }

    /** Reorder the queue by 1-based positions within the *upcoming* list. */
    private function move(Widget $widget, int $from, int $to): WidgetOutcome
    {
        $state = $widget->state;
        $base = ($state['currentIndex'] ?? -1) + 1;
        $fromIdx = $base + ($from - 1);
        $toIdx = $base + ($to - 1);
        $upcomingCount = count($state['queue']) - $base;

        if ($from < 1 || $to < 1 || $from > $upcomingCount || $to > $upcomingCount || $from === $to) {
            return WidgetOutcome::noop();
        }

        $moved = array_splice($state['queue'], $fromIdx, 1);
        array_splice($state['queue'], $toIdx, 0, $moved);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function remove(Widget $widget, string $ordinal): WidgetOutcome
    {
        $n = (int) $ordinal;
        if ($n < 1) {
            return WidgetOutcome::reply('Which one? `m!remove <number>` from the queue.');
        }

        $state = $widget->state;
        $target = ($state['currentIndex'] ?? -1) + $n;
        if (! isset($state['queue'][$target])) {
            return WidgetOutcome::reply("There's no #{$n} in the queue.");
        }

        array_splice($state['queue'], $target, 1);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function removeById(Widget $widget, string $id): WidgetOutcome
    {
        $state = $widget->state;
        $index = null;
        foreach ($state['queue'] as $i => $track) {
            if ((string) $track['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return WidgetOutcome::noop();
        }

        $isCurrent = $index === $state['currentIndex'];
        array_splice($state['queue'], $index, 1);

        if ($state['currentIndex'] !== null && $index < $state['currentIndex']) {
            $state['currentIndex']--;
        }
        if (empty($state['queue'])) {
            return $this->stop($widget);
        }
        if ($isCurrent) {
            $widget->state = $state;

            return $this->goTo($widget, min($state['currentIndex'], count($state['queue']) - 1));
        }
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function clear(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['currentIndex'] === null) {
            return WidgetOutcome::reply('The queue is already empty.');
        }
        $state['queue'] = [$state['queue'][$state['currentIndex']]];
        $state['currentIndex'] = 0;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function shuffle(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $current = $state['currentIndex'];
        if ($current === null || count($state['queue']) < 3) {
            return WidgetOutcome::reply('Not enough queued to shuffle.');
        }

        $upcoming = array_slice($state['queue'], $current + 1);
        shuffle($upcoming);
        $state['queue'] = array_merge(array_slice($state['queue'], 0, $current + 1), $upcoming);
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    // --- modes --------------------------------------------------------------

    private function cycleLoop(Widget $widget): WidgetOutcome
    {
        $order = ['off' => 'track', 'track' => 'queue', 'queue' => 'off'];
        $state = $widget->state;
        $state['loop'] = $order[$state['loop']] ?? 'off';
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function toggleAutoplay(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $state['autoplay'] = ! ($state['autoplay'] ?? false);
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
        foreach ($state['queue'] as $i => $track) {
            if ((string) $track['id'] === $id && empty($track['duration'])) {
                $state['queue'][$i]['duration'] = $duration;
                $widget->state = $state;

                return WidgetOutcome::updated();
            }
        }

        return WidgetOutcome::noop();
    }

    // --- helpers ------------------------------------------------------------

    /** Where the current track should be *now*, given it's played since `updated_at` at `speed`. */
    private function livePosition(array $state): float
    {
        $base = (float) ($state['position'] ?? 0);
        if (($state['status'] ?? 'idle') !== 'playing') {
            return $base;
        }
        $elapsed = now()->diffInMilliseconds(Carbon::parse($state['updated_at']), absolute: true) / 1000;

        return $base + $elapsed * (float) ($state['speed'] ?? 1);
    }

    /** @return array<int, string> every videoId currently in the queue (autoplay de-dupe). */
    private function videoIds(array $state): array
    {
        return array_map(fn ($t) => $t['videoId'], $state['queue']);
    }

    /** `1:23` / `83` / `1:02:03` → seconds. */
    private function parseTime(string $time): float
    {
        $parts = array_map('intval', explode(':', trim($time)));
        $seconds = 0;
        foreach ($parts as $part) {
            $seconds = $seconds * 60 + $part;
        }

        return (float) $seconds;
    }

    private function help(): string
    {
        return implode("\n", [
            '🎵 **Music commands**',
            '`m!p <link/search>` — play or queue (YouTube, Spotify, SoundCloud, Deezer, or words)',
            '`m!pn <link/search>` — play next · `m!search <words>` — pick from results',
            '`m!pause` · `m!resume` · `m!next` · `m!prev` · `m!stop`',
            '`m!seek 1:30` · `m!move <from> <to>` · `m!remove <n>` · `m!clear` · `m!shuffle`',
            '`m!loop` (off/track/queue) · `m!autoplay` · `m!speed 1.25` · `m!nightcore` · `m!vaporwave`',
        ]);
    }
}
