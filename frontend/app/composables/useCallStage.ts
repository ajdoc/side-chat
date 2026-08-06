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
  const { setWatchedScreen } = useVoice()
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

  const watching: Ref<string | null> = ref(null)

  const stage = computed(() => watchables.value.find(w => w.key === watching.value) ?? null)

  /**
   * The peer whose *screen* is on the stage, when it's somebody else's.
   *
   * Everything that reads this is screen-only — the shared-screen volume, its mute, and remote
   * control — so the kind is part of the question rather than a check each caller has to
   * remember. Null for your own screen too: none of those three mean anything pointed at
   * yourself.
   */
  const stageScreenPeer = computed(() => {
    const on = stage.value
    if (on?.kind !== 'screen' || typeof on.owner !== 'number') return null

    return options.peers().find(p => p.id === on.owner) ?? null
  })

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

    // Whatever you were watching has stopped. Clearing rather than falling through to the next
    // thing: the alternative puts a stranger's face on your main screen because the screen you
    // were reading ended, which nobody asked for. A new *screen* still claims it, below.
    if (watching.value !== null && !current.includes(watching.value)) watching.value = null

    if (watching.value !== null) return

    // An empty stage, and something worth putting on it. Screens first — the deliberate act
    // outranks the automatic one.
    const claim = started.find(k => k.endsWith(':screen'))
      ?? started.find(k => spotlit(k))

    if (claim) watching.value = claim
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
    if (watching.value !== null) return

    const claim = watchables.value.find(w => spotlit(w.key))
    if (claim) watching.value = claim.key
  })

  /*
   * Keep the audio layer in step with the stage.
   *
   * Only the screen actually on the stage is allowed to make a sound, which is what makes
   * "stop watching" silence it too. A *camera* on the stage therefore means no screen is being
   * watched, and any share audio goes quiet — correct rather than merely convenient: the share
   * is no longer on your screen, and a soundtrack to a picture you closed is a mystery noise.
   */
  watch(stage, (on) => {
    setWatchedScreen(on?.kind === 'screen' ? on.owner : null)
  }, { immediate: true })

  /**
   * Put something on the stage, or take it off if it's already there.
   *
   * One function for both, because every surface that offers this is a toggle: a tile's pin
   * button, the picker row, the close button on the stage itself.
   */
  function toggleWatch(owner: WatchOwner, kind: WatchKind) {
    const key = watchKey(owner, kind)
    watching.value = watching.value === key ? null : key
  }

  return { watchables, watching, stage, stageScreenPeer, toggleWatch }
}
