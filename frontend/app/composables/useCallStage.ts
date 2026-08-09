import type { Ref } from 'vue'
import type { Peer } from '~/types'

/**
 * What's on the big screen, and how it got there.
 *
 * ## Why this is a composable rather than two copies
 *
 * A voice channel and a Side Space both have a stage — the large area a shared screen plays in
 * — and both grew their own `sharers`/`watching`/`stage` triple, near-identical and separately
 * maintained. Adding cameras to the stage meant changing the rules in both, which is exactly the
 * moment a duplicated rulebook stops being cheap. The two callers still differ in the ways they
 * genuinely differ (a Side Space stages only the people in earshot; a voice channel stages
 * everybody), and that difference is the `peers` accessor they pass in.
 *
 * ## Screens and faces are both watchable, and they are not the same
 *
 * The stage used to hold screens only. It now holds either, which is what "put that person on
 * the main screen" means — but the two arrive by different routes on purpose:
 *
 *   - **A screen claims the stage.** Somebody who starts sharing has said "look at this", and
 *     making you hunt for a button first would be the wrong default. This is the pre-existing
 *     behaviour and it is unchanged.
 *   - **A face never claims it by itself.** Cameras go on and off constantly and mean nothing
 *     in particular; a camera that seized the main screen every time somebody tidied their hair
 *     would make the stage unusable. A face gets there because you picked it — or because its
 *     owner walked onto a Side Space stage, which is the one camera event that *is* an
 *     announcement (see `priority`).
 *
 * ## Keys
 *
 * `owner:kind` rather than a bare id, because one person can be showing you two things at once
 * — their screen and their face — and the stage has to be able to tell which of them you asked
 * for. `owner` is a user id or the string `self`.
 *
 * ## The stage holds several things at once
 *
 * It used to hold exactly one, and switching between two shared screens meant losing sight of
 * the first — which is the wrong shape for the case that produces two screens in the first
 * place (someone demoing while someone else follows along). So `watching` is now an ordered
 * list of keys and the surface lays them out as a grid, the way Discord does.
 *
 * Capped at {@link MAX_WATCHING}. The ceiling is a decode cost, not a layout one: in a mesh
 * every screen you watch is its own video decode, and four 720p screens is already more work
 * than a laptop on battery wants to be doing beside the call's own encoding. Past four the
 * tiles are too small to read anyway, so the cap costs nothing that was worth having.
 */
export type WatchKind = 'screen' | 'camera'
export type WatchOwner = number | 'self'

export interface Watchable {
  key: string
  owner: WatchOwner
  kind: WatchKind
  /** What the picker calls it — "Ada's screen", "Ada". */
  name: string
  stream: MediaStream | null
}

export function watchKey(owner: WatchOwner, kind: WatchKind): string {
  return `${owner}:${kind}`
}

/** How many things can be on the stage at once. See the note above on why it's a decode budget. */
export const MAX_WATCHING = 4

interface Options {
  /** Who is eligible — everybody in a voice channel, everybody in earshot in a Side Space. */
  peers: () => Peer[]
  /** Your own state, which never comes from a peer connection. */
  self: () => { sharing: boolean, screen: MediaStream | null, cameraOn: boolean, camera: MediaStream | null }
  /**
   * People whose *camera* is an announcement — currently whoever is live on a Side Space stage.
   *
   * They're the exception to "a face never claims the stage by itself", and they earn it by the
   * same rule a screen share does: walking onto a stage in front of the room is saying "look at
   * me" as plainly as pressing Share is. Only ever claims an *empty* stage, so it can't shove
   * aside the screen you're reading, and only on the transition — turn a spotlit speaker off the
   * main screen and they stay off it.
   */
  priority?: () => number[]
  /** Called when a screen starts, for callers that expand something to make room. */
  onScreenStarted?: () => void
}

export function useCallStage(options: Options) {
  const { setWatchedScreens } = useVoice()
  const { nameFor } = useNicknames()

  /** Everything you could put on the stage: every screen being shared, then every camera on. */
  const watchables = computed<Watchable[]>(() => {
    const self = options.self()
    const list: Watchable[] = []

    if (self.sharing) {
      list.push({ key: watchKey('self', 'screen'), owner: 'self', kind: 'screen', name: 'Your screen', stream: self.screen })
    }

    for (const peer of options.peers()) {
      if (peer.screenSharing && peer.screen) {
        list.push({ key: watchKey(peer.id, 'screen'), owner: peer.id, kind: 'screen', name: `${nameFor(peer)}'s screen`, stream: peer.screen })
      }
    }

    // Faces after screens, and your own last of all: a picker is read top-down, and the thing
    // somebody deliberately shared belongs above the thing a camera does by simply being on.
    for (const peer of options.peers()) {
      if (peer.cameraOn && peer.camera) {
        list.push({ key: watchKey(peer.id, 'camera'), owner: peer.id, kind: 'camera', name: nameFor(peer), stream: peer.camera })
      }
    }

    if (self.cameraOn && self.camera) {
      list.push({ key: watchKey('self', 'camera'), owner: 'self', kind: 'camera', name: 'You', stream: self.camera })
    }

    return list
  })

  /**
   * What's on the stage, in the order you added it — first one first, so a grid never reshuffles
   * the thing you were already reading when a second screen joins it.
   */
  const watching: Ref<string[]> = ref([])

  /** The watchables actually on the stage, in `watching` order. Empty means the stage is down. */
  const stages = computed(() => {
    const live = watchables.value

    return watching.value
      .map(key => live.find(w => w.key === key))
      .filter((w): w is Watchable => !!w)
  })

  /**
   * The one at the front.
   *
   * Kept because plenty of questions are still singular even with a grid up — "is anything
   * showing", and what to call it in a one-line summary like the Side Space's "showing over the
   * room" button.
   */
  const stage = computed(() => stages.value[0] ?? null)

  const isWatching = (key: string) => watching.value.includes(key)

  /** Room for one more? Surfaces grey out their watch buttons on this rather than fail silently. */
  const stageFull = computed(() => watching.value.length >= MAX_WATCHING)

  /**
   * The peer whose *screen* this is, when it's somebody else's.
   *
   * Everything that reads this is screen-only — the shared-screen volume, its mute, and remote
   * control — so the kind is part of the question rather than a check each caller has to
   * remember. Null for your own screen too: none of those three mean anything pointed at
   * yourself.
   *
   * Takes the watchable now that several are on the stage at once; each tile asks about itself.
   */
  function screenPeerFor(on: Watchable | null): Peer | null {
    if (on?.kind !== 'screen' || typeof on.owner !== 'number') return null

    return options.peers().find(p => p.id === on.owner) ?? null
  }

  /*
   * The watchable set as a string.
   *
   * `peers` is patched constantly — the speaking rings alone repaint it many times a second —
   * so watching the list itself fires continuously. That's the bug that used to make "Hide"
   * spring back open in a voice channel. Reduced to keys, the watcher wakes only when somebody
   * actually starts or stops showing something.
   */
  const keys = computed(() => watchables.value.map(w => w.key).join('|'))

  watch(keys, (now, before) => {
    const current = now ? now.split('|') : []
    const previous = before ? before.split('|') : []
    const started = current.filter(k => !previous.includes(k))

    if (started.some(k => k.endsWith(':screen'))) options.onScreenStarted?.()

    // Drop whatever has stopped, and only that — the rest of the grid is untouched, so one
    // person ending their share doesn't disturb the screen you were reading beside it.
    const kept = watching.value.filter(k => current.includes(k))

    /*
     * New screens join the grid.
     *
     * The old single stage *replaced* what you were watching; with room for several, a second
     * share is added instead, which is the whole point of the grid. Faces still never claim the
     * stage by themselves (see the note at the top) unless they're spotlit.
     *
     * Nothing is evicted to make room: once the stage is full, a new share is announced by the
     * sharer's tile and the picker and waits to be asked for. Shoving aside a screen somebody is
     * mid-sentence about is worse than making them click.
     */
    const claims = [
      ...started.filter(k => k.endsWith(':screen')),
      ...started.filter(k => spotlit(k)),
    ]

    const next = [...kept, ...claims.filter(k => !kept.includes(k))].slice(0, MAX_WATCHING)

    // Only when it actually differs. This watcher fires on every arrival and departure in the
    // call, and a fresh array each time would re-run the audio gate and repaint the grid for
    // somebody else's camera going off across the room.
    if (next.join('|') !== watching.value.join('|')) watching.value = next
  })

  /** Is this key a camera belonging to somebody whose camera is an announcement? See `priority`. */
  function spotlit(key: string): boolean {
    if (!key.endsWith(':camera')) return false

    const id = Number(key.split(':')[0])

    return Number.isFinite(id) && !!options.priority?.().includes(id)
  }

  /*
   * Somebody live on a stage turning their camera on *after* they got there.
   *
   * The watcher above fires on the set of watchables changing, which covers "walks on with a
   * camera already running". This covers the other order, which is at least as common: you step
   * up, the room turns to look, and then you turn your camera on. Both are the same moment as
   * far as anybody watching is concerned, so both should claim an empty stage.
   */
  watch(() => options.priority?.().join('|') ?? '', () => {
    // Only ever an *empty* stage, as before: somebody stepping up shouldn't push aside screens
    // you deliberately gathered, and with a grid there's usually something up.
    if (watching.value.length) return

    const claim = watchables.value.find(w => spotlit(w.key))
    if (claim) watching.value = [claim.key]
  })

  /*
   * Keep the audio layer in step with the stage.
   *
   * Only screens actually on the stage are allowed to make a sound, which is what makes "stop
   * watching" silence one. With several up you hear all of them — they're all on your screen, and
   * silencing all but one would be a rule nobody could see; the per-screen volume and mute are
   * there for the moment two soundtracks is one too many.
   */
  // Driven off the owners as a string rather than the watchables themselves: `stages` is rebuilt
  // whenever a peer is patched (constantly — the speaking rings alone), and a deep watch on it
  // would be a deep watch over live MediaStreams.
  const watchedScreenOwners = computed(() =>
    stages.value.filter(w => w.kind === 'screen').map(w => w.owner))

  watch(() => watchedScreenOwners.value.join('|'), () => {
    setWatchedScreens(watchedScreenOwners.value)
  }, { immediate: true })

  /**
   * Put something on the stage, or take it off if it's already there.
   *
   * One function for both, because every surface that offers this is a toggle: a tile's pin
   * button, the picker row, the close button on the stage itself. A no-op when the stage is
   * full and this would be an addition — the surfaces disable the control, and this is the
   * backstop for the ones that can't.
   */
  function toggleWatch(owner: WatchOwner, kind: WatchKind) {
    const key = watchKey(owner, kind)

    if (isWatching(key)) watching.value = watching.value.filter(k => k !== key)
    else if (!stageFull.value) watching.value = [...watching.value, key]
  }

  /** Take everything off the stage — the "hide the screens, keep the call" button. */
  function clearWatching() {
    watching.value = []
  }

  return { watchables, watching, stages, stage, stageFull, isWatching, screenPeerFor, toggleWatch, clearWatching }
}
