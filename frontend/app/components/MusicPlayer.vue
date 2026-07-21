<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core'
import { ArrowDown, ArrowUp, FastForward, ListMusic, Maximize2, Mic2, Pause, Pin, PinOff, Play, Radio, RefreshCw, Repeat, Repeat1, Rewind, Search, Shuffle, SkipBack, SkipForward, Square, Volume2, VolumeX, X } from 'lucide-vue-next'
import type { MusicState, MusicTrack, Widget } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The listen-along music player card — a bot-style player in the spirit of Jockie.
 *
 * The server owns the transport (queue, current track, playing/paused, speed, position as
 * of `updated_at`). This component makes a *player* obey that shared truth: load the right
 * track and keep it seeked to where the room is now. Nobody streams audio to anyone; each
 * viewer plays their own copy in lockstep. Volume is the one local thing.
 *
 * Two engines, chosen per current track:
 *   - Spotify Web Playback SDK — the *real* track, for viewers who've linked a Premium
 *     Spotify account, when the track came from Spotify (and no speed effect is on, which
 *     the SDK can't do).
 *   - YouTube IFrame — the fallback everyone else hears, and the only option for
 *     non-Spotify sources.
 * Both obey the same shared state, so a Premium listener on Spotify and a free listener on
 * YouTube stay on the same song at the same spot (versions differ in length, so it's
 * approximate near track boundaries — see the notes in MusicWidget).
 *
 * Playback only ever starts from a real click ("Listen along"), sidestepping autoplay
 * blocking; the transport buttons drive the shared state for everyone regardless.
 *
 * The card normally lives in a timeline and unmounts when you leave it. Pin it and this same
 * component is rendered instead by MusicDock, at the app level, where navigation can't reach
 * it — see useMusicPin. `docked` says which of the two we are.
 */
const props = defineProps<{ widget: Widget, docked?: boolean }>()

const { $youtube, $spotify } = useNuxtApp() as any
const { action } = useWidgets()
const { isPinned, toggle: togglePin, hasJoined, markJoined } = useMusicPin()
const { status: spotifyStatus, canUseSpotify, ensureLoaded, connect: connectSpotify, getToken } = useSpotifyAuth()

const state = computed(() => props.widget.state as MusicState)
const queue = computed<MusicTrack[]>(() => state.value.queue ?? [])
const currentIndex = computed(() => state.value.currentIndex)
const current = computed<MusicTrack | null>(() =>
  currentIndex.value != null ? queue.value[currentIndex.value] ?? null : null,
)
const upcoming = computed(() =>
  currentIndex.value == null ? [] : queue.value.slice(currentIndex.value + 1),
)
const isPlaying = computed(() => state.value.status === 'playing')
const speed = computed(() => state.value.speed ?? 1)
const pending = computed(() => state.value.pendingSearch ?? null)

const SPEED_OPTIONS = [
  { value: 0.75, label: '0.75× Vaporwave' },
  { value: 1, label: '1× Normal' },
  { value: 1.25, label: '1.25×' },
  { value: 1.3, label: '1.3× Nightcore' },
  { value: 1.5, label: '1.5×' },
  { value: 2, label: '2×' },
]

const SOURCE_LABEL: Record<string, string> = {
  youtube: 'YouTube', spotify: 'Spotify', soundcloud: 'SoundCloud', deezer: 'Deezer',
}

// Has this viewer opted in to hearing the room? Playback never begins without it. Kept in
// app state rather than here so pinning hands the opt-in over to the dock intact — the sound
// is supposed to *continue*, not ask permission again on the other side of a navigation.
const joined = computed(() => hasJoined(props.widget.id))
const volume = useLocalStorage('music:volume', 100)
const muted = ref(false)
// A hand-off between the timeline card and the dock builds a *new* iframe, and a browser is
// within its rights to refuse that one audio until it sees a gesture it likes. Rather than
// leave a silent player looking like it's playing, notice a YouTube engine that stays
// unstarted while the room plays on, and offer a one-click resume.
const blocked = ref(false)
let stalledTicks = 0

// --- engine selection ---------------------------------------------------
// Spotify's SDK reported this account can't stream (not Premium) — stop offering it.
const spAccountError = ref(false)
// The account *can* stream, but its token can't — the SDK rejected auth, or a playback call
// came back 401/403. Almost always a stale link: the token was minted before the current
// scope set, so a full re-consent (reconnect) is what fixes it. Fall back to YouTube and
// surface a "Reconnect Spotify" prompt until they do.
const spAuthError = ref(false)
// The SDK's device_id isn't playable for this token even after a transfer — a 404 that
// survives the retry. Usually a ghost/stale device from an account or session mismatch; a
// reconnect re-registers a fresh one. Fall back to YouTube instead of looping the 404.
const spDeviceError = ref(false)
const spotifyEligible = computed(() =>
  canUseSpotify.value && !spAccountError.value && !spAuthError.value && !spDeviceError.value
  && !!current.value?.spotifyUri && (state.value.speed ?? 1) === 1,
)
// Which engine actually plays for *this* viewer right now.
const engine = computed<'spotify' | 'youtube'>(() => (spotifyEligible.value && joined.value ? 'spotify' : 'youtube'))

// --- YouTube engine -----------------------------------------------------
const mountEl = ref<HTMLElement | null>(null)
let yt: any = null
let ytReady = false
let loadedVideoId: string | null = null
const reportedDuration = new Set<string>()

// --- Spotify engine -----------------------------------------------------
let sp: any = null
let spReady = false
let spDeviceId: string | null = null
let loadedUri: string | null = null
let spState: { position: number, duration: number, paused: boolean } | null = null
let spLastPos = 0
const spEndedFor = new Set<string>()
let spTokenCache: { token: string, exp: number } | null = null

let ticker: ReturnType<typeof setInterval> | null = null
const displayTime = ref(0)
const duration = ref(0)

// Where the current track should be *right now*, extrapolating from the server snapshot.
// Spotify is only ever chosen at 1× speed, so this stays correct for both engines.
function targetPosition(): number {
  const s = state.value
  const base = s.position ?? 0
  if (s.status !== 'playing') return base
  const elapsed = (Date.now() - Date.parse(s.updated_at)) / 1000
  return Math.max(0, base + elapsed * (s.speed ?? 1))
}

// ---- reconcile: point the *active* engine at the shared state, idle the other ----
function sync() {
  if (!current.value) { idleYouTube(); idleSpotify(true); return }
  if (engine.value === 'spotify') { idleYouTube(); syncSpotify() }
  else { idleSpotify(); syncYouTube() }
}

function syncYouTube() {
  if (!ytReady || !yt) return
  if (!current.value?.videoId) {
    // A Spotify shell still resolving to a YouTube fallback id — nothing to load yet.
    return
  }
  if (loadedVideoId !== current.value.videoId) {
    loadedVideoId = current.value.videoId
    const start = targetPosition()
    if (joined.value && isPlaying.value) yt.loadVideoById({ videoId: current.value.videoId, startSeconds: start })
    else yt.cueVideoById({ videoId: current.value.videoId, startSeconds: start })
    yt.setPlaybackRate?.(speed.value)
    return
  }
  if (yt.getPlaybackRate?.() !== speed.value) yt.setPlaybackRate?.(speed.value)
  if (!joined.value) return

  const drift = Math.abs((yt.getCurrentTime?.() ?? 0) - targetPosition())
  if (isPlaying.value) {
    if (drift > 1.5) yt.seekTo(targetPosition(), true)
    const st = yt.getPlayerState?.()
    if (st !== 1 && st !== 3) yt.playVideo()
  } else {
    if (drift > 1) yt.seekTo(targetPosition(), true)
    yt.pauseVideo()
  }
}

function idleYouTube() {
  if (ytReady && yt && loadedVideoId) yt.pauseVideo?.()
}

async function syncSpotify() {
  await ensureSpotifyPlayer()
  if (!spReady || !sp || !spDeviceId || !current.value?.spotifyUri) return

  if (loadedUri !== current.value.spotifyUri) {
    loadedUri = current.value.spotifyUri
    spEndedFor.delete(current.value.id)
    await startSpotifyTrack(current.value.spotifyUri, targetPosition())
    return
  }

  // Coarse reconcile off the last polled state (getCurrentState is async — see tick()).
  const cur = spState
  if (isPlaying.value) {
    if (cur?.paused) sp.resume()
    if (cur && Math.abs(cur.position - targetPosition()) > 2) sp.seek(Math.round(targetPosition() * 1000))
  } else if (cur && !cur.paused) {
    sp.pause()
  }
}

function idleSpotify(hard = false) {
  if (sp && spReady && loadedUri) {
    sp.pause?.()
    if (hard) loadedUri = null
  }
}

async function ensureSpotifyPlayer() {
  if (sp || !canUseSpotify.value) return
  const Spotify = await $spotify.ready()
  sp = new Spotify.Player({
    name: 'Side Chat',
    // Share the one cached token with the playback calls below — the SDK registers its
    // device under whatever token this returns, so if the two diverged (e.g. after relinking
    // a different account) the device would 404. One source keeps them on one identity.
    getOAuthToken: (cb: (t: string) => void) => { cachedSpotifyToken().then(t => t && cb(t)) },
    volume: (muted.value ? 0 : volume.value) / 100,
  })
  sp.addListener('ready', ({ device_id }: any) => { spDeviceId = device_id; spReady = true; sync() })
  sp.addListener('not_ready', () => { spReady = false })
  sp.addListener('player_state_changed', (st: any) => {
    if (st) spState = { position: st.position / 1000, duration: st.duration / 1000, paused: st.paused }
  })
  // The decisive "you can't stream" signal — free accounts land here. Fall back for good.
  sp.addListener('account_error', () => { spAccountError.value = true; idleSpotify(true); sync() })
  // The token was rejected outright — needs a fresh consent, not a refresh. Prompt reconnect.
  sp.addListener('authentication_error', () => { spReady = false; spAuthError.value = true })
  sp.connect()
}

async function startSpotifyTrack(uri: string, posSec: number) {
  const token = await cachedSpotifyToken()
  if (!token || !spDeviceId) return
  const auth = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }
  const play = () => $fetch(`https://api.spotify.com/v1/me/player/play?device_id=${spDeviceId}`, {
    method: 'PUT',
    headers: auth,
    body: { uris: [uri], position_ms: Math.round(posSec * 1000) },
  })
  try {
    await play()
  } catch (err) {
    // A rejected token (stale scopes) — fall back and prompt a reconnect, don't retry.
    if (isAuthError(err)) { spAuthError.value = true; loadedUri = null; return }
    // A just-`ready` SDK device is registered but not yet the *active* device, so the first
    // /play can 404 ("Device not found") — deployed latency loses the race localhost wins.
    // Activate it with a transfer, then retry once. If that still fails, let the next sync try.
    try {
      await $fetch('https://api.spotify.com/v1/me/player', {
        method: 'PUT',
        headers: auth,
        body: { device_ids: [spDeviceId], play: false },
      })
      await new Promise(r => setTimeout(r, 400))
      await play()
    } catch (err2) {
      // Still failing after activating the device. Stop hammering Spotify — fall back to
      // YouTube and flag it so a reconnect (fresh device/consent) is offered.
      if (isAuthError(err2)) spAuthError.value = true
      else spDeviceError.value = true
      loadedUri = null
      return
    }
  }
  if (!isPlaying.value) setTimeout(() => sp?.pause?.(), 300)
}

// 401 (token expired/invalid) or 403 (insufficient scope) from a playback call — the link
// is there but the token can't act. ofetch surfaces the code on the thrown error.
function isAuthError(err: any): boolean {
  const status = err?.status ?? err?.statusCode ?? err?.response?.status
  return status === 401 || status === 403
}

async function cachedSpotifyToken(): Promise<string | null> {
  if (spTokenCache && spTokenCache.exp > Date.now() + 5000) return spTokenCache.token
  const t = await getToken()
  if (t) spTokenCache = { token: t, exp: Date.now() + 50 * 60 * 1000 }
  return t
}

// ---- ticker: progress readout + end-of-track detection for the active engine ----
function tick() {
  if (engine.value === 'spotify' && joined.value && sp) {
    sp.getCurrentState?.().then((st: any) => {
      if (!st) return
      spState = { position: st.position / 1000, duration: st.duration / 1000, paused: st.paused }
      setPosition(spState.position)
      duration.value = spState.duration || current.value?.duration || 0
      detectSpotifyEnd(spState)
    })
    return
  }
  if (engine.value === 'youtube' && joined.value && ytReady && yt?.getCurrentTime) {
    setPosition(yt.getCurrentTime() || 0)
    duration.value = yt.getDuration() || current.value?.duration || 0
    maybeReportDuration()
    detectBlocked()
    return
  }
  setPosition(targetPosition())
  duration.value = current.value?.duration || 0
}

/** The one place a fresh reading of "where playback actually is" enters the component. */
function setPosition(pos: number) {
  displayTime.value = pos
  sampleClock(pos)
}

// Spotify plays a single uri with no "up next", so it just stops at the end. Detect that
// (near the end while playing, or paused right after) and ask the room to advance — once.
function detectSpotifyEnd(st: { position: number, duration: number, paused: boolean }) {
  const id = current.value?.id
  if (!id || !st.duration || spEndedFor.has(id)) return
  if (!st.paused) spLastPos = st.position
  const nearEnd = st.duration - st.position < 1.2
  const stoppedAtEnd = st.paused && spLastPos > 0 && st.duration - spLastPos < 2.5
  if ((!st.paused && nearEnd) || stoppedAtEnd) {
    spEndedFor.add(id)
    action(props.widget.id, 'ended', { id })
  }
}

// PLAYING (1) and BUFFERING (3) are the healthy states; anything else while the room is
// playing means our sound isn't coming. Give it a couple of seconds — loading a video passes
// through UNSTARTED legitimately — before admitting it and asking for a click.
function detectBlocked() {
  const st = yt?.getPlayerState?.()
  if (!isPlaying.value || st === 1 || st === 3) { stalledTicks = 0; blocked.value = false; return }
  if (++stalledTicks >= 6) blocked.value = true
}

function maybeReportDuration() {
  const t = current.value
  if (!t || t.duration || reportedDuration.has(t.id)) return
  const d = Math.round(yt?.getDuration?.() || 0)
  if (d > 0) { reportedDuration.add(t.id); action(props.widget.id, 'meta', { id: t.id, duration: d }) }
}

async function ensureYouTube() {
  if (yt || !mountEl.value) return
  const YT = await $youtube.ready()
  if (!mountEl.value) return
  yt = new YT.Player(mountEl.value, {
    height: '0', width: '0',
    playerVars: { autoplay: 0, controls: 0, disablekb: 1, playsinline: 1 },
    events: {
      onReady: () => { ytReady = true; applyVolume(); sync() },
      onStateChange: (e: any) => {
        if (e.data === YT.PlayerState.ENDED && engine.value === 'youtube' && current.value) {
          action(props.widget.id, 'ended', { id: current.value.id })
        }
      },
    },
  })
}

function applyVolume() {
  const v = muted.value ? 0 : volume.value
  if (ytReady) yt?.setVolume?.(v)
  if (spReady) sp?.setVolume?.(v / 100)
}

function joinListening() {
  markJoined(props.widget.id)
  blocked.value = false
  loadedVideoId = null
  loadedUri = null
  stalledTicks = 0
  ensureSpotifyPlayer()
  sync()
  // Inside the click, so the gesture is still the browser's reason to allow sound. sync()
  // has just reloaded the video; nudging play covers the case where it only cued it.
  if (isPlaying.value) { try { yt?.playVideo?.() } catch { /* not ready yet — sync will */ } }
}

/**
 * Pin this player to the dock (or send it back to the timeline).
 *
 * Handing playback from one instance to the other means a fresh <iframe>, and a fresh iframe
 * only gets to make noise off a user gesture — which this click is. Nothing else is needed:
 * the new instance reads the same shared transport and seeks itself to where the room is.
 */
function onTogglePin() {
  togglePin(props.widget)
}

// --- transport (drives the shared state for everyone) ---
const busy = ref(false)
async function send(name: string, payload: Record<string, unknown> = {}) {
  if (busy.value) return
  busy.value = true
  try { await action(props.widget.id, name, payload) }
  finally { busy.value = false }
}
const togglePlay = () => send(isPlaying.value ? 'pause' : 'resume')
const next = () => send('next')
const prev = () => send('prev')
const stop = () => send('stop')
const shuffle = () => send('shuffle')
const cycleLoop = () => send('loop')
const toggleAutoplay = () => send('autoplay')
const jump = (i: number) => send('jump', { index: i })
const removeTrack = (t: MusicTrack) => send('remove', { id: t.id })
const moveTrack = (pos: number, dir: -1 | 1) => send('move', { from: pos, to: pos + dir })
const seekBy = (secs: number) => send('seek', { position: Math.max(0, displayTime.value + secs) })
const onSpeed = (e: Event) => send('speed', { value: Number((e.target as HTMLInputElement).value) })
const pick = (i: number) => send('pick', { index: i })
const cancelSearch = () => send('cancelSearch')

// Add to the queue from inside the card — the composer's `m!p` isn't reachable everywhere
// (the Open Canvas has no message box), so the player carries its own search/link field.
const addQuery = ref('')
async function addMusic() {
  const q = addQuery.value.trim()
  if (!q) return
  await send('add', { query: q })
  addQuery.value = ''
}

// --- karaoke ------------------------------------------------------------
// Lyrics are a *view* on playback, not new shared state: every viewer's highlight rides the
// position their own engine is at, which the transport already keeps locked to the room. So
// opening the pane (or the stage) is purely local — one person singing along changes nothing
// for anyone else.
const karaokeOpen = useLocalStorage('music:karaoke', false)
const stageOpen = ref(false)
const wantLyrics = computed(() => karaokeOpen.value || stageOpen.value)
const { lines: lyricLines, synced: lyricsSynced, instrumental, loading: lyricsLoading, missing: lyricsMissing } = useLyrics(current, wantLyrics)

function toggleKaraoke() {
  if (stageOpen.value) { stageOpen.value = false; return }
  karaokeOpen.value = !karaokeOpen.value
}
const seekTo = (seconds: number) => send('seek', { position: seconds })

// ---- the karaoke clock -------------------------------------------------
// `displayTime` is polled twice a second, which is fine for a progress readout but makes the
// highlight advance in visible steps. For singing, the position has to move *continuously*,
// so karaoke runs its own clock: hold the last real reading and extrapolate from it every
// frame, snapping back whenever a fresh sample arrives.
// Null until the first reading — an explicit sentinel, because 0 is a perfectly valid
// timestamp and a falsy check on it would silently disable extrapolation.
let clockSample: { pos: number, at: number } | null = null
const lyricTime = ref(0)
let raf: number | null = null

/** Extrapolate the held sample to now. Media time advances at the playback rate. */
function extrapolated(): number {
  if (!clockSample) return displayTime.value
  const elapsed = isPlaying.value ? (performance.now() - clockSample.at) / 1000 * speed.value : 0
  return clockSample.pos + elapsed
}

function sampleClock(pos: number) {
  const predicted = extrapolated()
  // Engine readings jitter by a few tens of milliseconds — snapping straight to each one
  // makes the highlight twitch, sometimes backwards. Ease across small disagreements and
  // take only the big ones (a seek, a track change) at face value.
  const converged = clockSample && Math.abs(pos - predicted) < 0.35
    ? predicted + (pos - predicted) * 0.25
    : pos
  clockSample = { pos: converged, at: performance.now() }
}

function frame() {
  lyricTime.value = extrapolated()
  raf = requestAnimationFrame(frame)
}

// Only run the animation loop while lyrics are actually on screen. Client-only: this fires
// immediately, and there's no requestAnimationFrame during SSR.
watch(wantLyrics, (on) => {
  if (!import.meta.client) return
  if (on && raf === null) { clockSample = null; frame() }
  else if (!on && raf !== null) { cancelAnimationFrame(raf); raf = null }
}, { immediate: true })

// ---- manual sync nudge -------------------------------------------------
// LRCLIB's stamps describe *a* recording; the queue is playing whichever version YouTube
// had. A long intro, a live take or a remaster shifts everything by a second or three —
// constant, so one offset fixes the whole song. Kept per *video*, not per queue entry, so
// re-adding a track remembers it, and kept local rather than shared: two listeners on the
// Spotify and YouTube engines are hearing genuinely different masters.
const lyricOffsets = useLocalStorage<Record<string, number>>('music:lyricOffset', {})
const offsetKey = computed(() => current.value?.videoId ?? current.value?.spotifyUri ?? current.value?.id ?? '')
const lyricOffset = computed(() => lyricOffsets.value[offsetKey.value] ?? 0)

function nudgeLyrics(delta: number) {
  const key = offsetKey.value
  if (!key) return
  // Round to kill floating-point crumbs, and cap it — past ±15s you've got the wrong song,
  // not a timing problem.
  const next = Math.max(-15, Math.min(15, Math.round((lyricOffset.value + delta) * 100) / 100))
  lyricOffsets.value = { ...lyricOffsets.value, [key]: next }
}
function resetLyricOffset() {
  const { [offsetKey.value]: _drop, ...rest } = lyricOffsets.value
  lyricOffsets.value = rest
}

// A positive offset means "the lyrics are running late, show them sooner".
const karaokePosition = computed(() => lyricTime.value + lyricOffset.value)

const linkingSpotify = ref(false)
async function onConnectSpotify() {
  if (linkingSpotify.value) return
  linkingSpotify.value = true
  try { await connectSpotify() }
  finally { linkingSpotify.value = false }
}

// Re-consent a stale link (see spAuthError). connect() re-runs the OAuth popup, minting a
// token with today's scopes; then we tear down the errored player so a fresh token and
// device register cleanly, and hand playback back to Spotify.
const reconnecting = ref(false)
async function onReconnectSpotify() {
  if (reconnecting.value) return
  reconnecting.value = true
  try {
    await connectSpotify()
    spAuthError.value = false
    spDeviceError.value = false
    try { sp?.disconnect?.() } catch { /* already gone */ }
    sp = null; spReady = false; spDeviceId = null; loadedUri = null; spTokenCache = null
    if (joined.value) { await ensureSpotifyPlayer(); sync() }
  }
  finally { reconnecting.value = false }
}

function toggleMute() { muted.value = !muted.value; applyVolume() }
function onVolume(e: Event) {
  volume.value = Number((e.target as HTMLInputElement).value)
  muted.value = false
  applyVolume()
}

function onScrub(e: MouseEvent) {
  if (!duration.value) return
  const bar = e.currentTarget as HTMLElement
  const ratio = (e.clientX - bar.getBoundingClientRect().left) / bar.offsetWidth
  send('seek', { position: Math.max(0, Math.min(1, ratio)) * duration.value })
}

function fmt(secs: number | null | undefined): string {
  const s = Math.max(0, Math.floor(secs ?? 0))
  if (!secs || !isFinite(secs)) return '--:--'
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = (s % 60).toString().padStart(2, '0')
  return h > 0 ? `${h}:${m.toString().padStart(2, '0')}:${sec}` : `${m}:${sec}`
}

const loopIcon = computed(() => (state.value.loop === 'track' ? Repeat1 : Repeat))
// Show a "connect for the real track" nudge when this track *could* be Spotify but isn't for us.
const spotifyOffer = computed(() =>
  !!current.value?.spotifyUri && !canUseSpotify.value
    ? (spotifyStatus.value.linked ? 'premium' : 'connect')
    : null,
)
// A recoverable Spotify failure a reconnect can fix — a rejected token or an unplayable
// (ghost/mismatched) device. Drives the auto "Reconnect" banner and its wording.
const reconnectPrompt = computed(() =>
  spAuthError.value ? 'Spotify needs reconnecting — click to fix'
    : spDeviceError.value ? 'Spotify device unavailable — reconnect to fix'
      : null,
)

const syncKey = computed(() =>
  [engine.value, current.value?.videoId, current.value?.spotifyUri, state.value.status, state.value.position, state.value.updated_at, state.value.speed, joined.value].join('|'),
)
watch(syncKey, () => sync())
watch([volume, muted], applyVolume)
// If the link status flips (just connected / went Premium), re-evaluate the engine.
watch(canUseSpotify, () => { if (joined.value) { ensureSpotifyPlayer(); sync() } })

onMounted(() => {
  ensureLoaded()
  ensureYouTube()
  // Twice a second is plenty even for karaoke: this only supplies *samples*, and the
  // karaoke clock interpolates between them every frame.
  ticker = setInterval(tick, 500)
})
onBeforeUnmount(() => {
  if (ticker) clearInterval(ticker)
  if (raf !== null) cancelAnimationFrame(raf)
  try { yt?.destroy?.() } catch { /* gone */ }
  try { sp?.disconnect?.() } catch { /* gone */ }
  yt = null; sp = null
})
</script>

<template>
  <div
    class="w-full overflow-hidden rounded-xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-sm"
    :class="docked ? 'bg-background' : 'mt-1.5 max-w-md'"
  >
    <div class="flex items-center gap-1.5 border-b bg-background/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <ListMusic class="h-3.5 w-3.5" /> Music
      <span class="ml-auto flex items-center gap-1.5">
        <span v-if="engine === 'spotify' && joined" class="inline-flex items-center gap-1 rounded-full bg-green-500/15 px-1.5 py-px text-[10px] normal-case text-green-600 dark:text-green-400" title="Playing the real track via your Spotify Premium">
          via Spotify
        </span>
        <span v-else-if="state.autoplay" class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case text-primary" title="Autoplay on">
          <Radio class="h-3 w-3" /> radio
        </span>
        <!-- Keep the sound when you walk away: pinned, this player is rendered by the dock
             instead of the timeline, so changing channel/server/DM doesn't unmount it. -->
        <button
          class="p-0.5 text-muted-foreground hover:text-foreground"
          :class="isPinned(widget.id) && 'text-primary'"
          :title="isPinned(widget.id) ? 'Unpin — playback stops when you leave this chat' : 'Keep playing while I move around'"
          @click="onTogglePin"
        >
          <component :is="isPinned(widget.id) ? PinOff : Pin" class="h-3.5 w-3.5" />
        </button>
      </span>
    </div>

    <div class="p-3">
      <!-- Add to queue — works without the m! command (e.g. on the Open Canvas). -->
      <form class="mb-3 flex items-center gap-1.5" @submit.prevent="addMusic">
        <div class="flex min-w-0 flex-1 items-center gap-1.5 rounded-lg border bg-background/60 px-2">
          <Search class="h-3.5 w-3.5 flex-none text-muted-foreground" />
          <input
            v-model="addQuery"
            placeholder="Search or paste a link…"
            class="min-w-0 flex-1 bg-transparent py-1.5 text-xs outline-none placeholder:text-muted-foreground"
          >
        </div>
        <Button type="submit" size="sm" class="h-8 flex-none" :disabled="busy || !addQuery.trim()">Add</Button>
      </form>

      <!-- Search picker -->
      <div v-if="pending" class="mb-3 rounded-lg border bg-background/60 p-2">
        <div class="mb-1.5 flex items-center gap-1.5 text-xs font-medium">
          <Search class="h-3.5 w-3.5 text-muted-foreground" />
          Results for “{{ pending.query }}”
          <button class="ml-auto text-muted-foreground hover:text-foreground" title="Cancel" @click="cancelSearch"><X class="h-3.5 w-3.5" /></button>
        </div>
        <ul class="space-y-0.5">
          <li v-for="(r, i) in pending.results" :key="r.videoId ?? r.title">
            <button class="flex w-full items-center gap-2 rounded p-1 text-left text-xs hover:bg-muted" @click="pick(i)">
              <img v-if="r.thumbnail" :src="r.thumbnail" alt="" class="h-8 w-12 flex-none rounded object-cover">
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ r.title }}</span>
                <span class="block truncate text-muted-foreground">{{ r.artist }}</span>
              </span>
              <span class="flex-none tabular-nums text-muted-foreground">{{ fmt(r.duration) }}</span>
            </button>
          </li>
        </ul>
        <p class="mt-1 text-[10px] text-muted-foreground">searched by {{ pending.by }}</p>
      </div>

      <!-- Now playing -->
      <div v-if="current" class="flex gap-3">
        <div class="relative h-16 w-16 flex-none overflow-hidden rounded-lg bg-muted">
          <img v-if="current.thumbnail" :src="current.thumbnail" alt="" class="h-full w-full object-cover">
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex items-start gap-1.5">
            <p class="min-w-0 flex-1 truncate text-sm font-semibold leading-tight" :title="current.title">{{ current.title }}</p>
            <span class="flex-none rounded bg-muted px-1.5 py-px text-[9px] font-medium uppercase text-muted-foreground">{{ SOURCE_LABEL[current.source] ?? current.source }}</span>
          </div>
          <p v-if="current.artist" class="truncate text-xs text-muted-foreground">{{ current.artist }}</p>

          <div class="mt-2 flex items-center gap-2 text-[10px] tabular-nums text-muted-foreground">
            <span>{{ fmt(displayTime) }}</span>
            <div class="h-1.5 flex-1 cursor-pointer rounded-full bg-border" @click="onScrub">
              <div class="h-full rounded-full bg-primary" :style="{ width: duration ? `${Math.min(100, (displayTime / duration) * 100)}%` : '0%' }" />
            </div>
            <span>{{ fmt(duration) }}</span>
          </div>
        </div>
      </div>
      <p v-else class="py-2 text-center text-sm text-muted-foreground">
        Nothing playing. Search or paste a link above to start.
      </p>

      <!-- Controls -->
      <template v-if="current">
        <div class="mt-3 flex items-center justify-center gap-1">
          <Button variant="ghost" size="icon" class="h-8 w-8" title="Shuffle" @click="shuffle"><Shuffle class="h-4 w-4" /></Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" title="Previous" @click="prev"><SkipBack class="h-4 w-4" /></Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" title="Back 10s" @click="seekBy(-10)"><Rewind class="h-4 w-4" /></Button>
          <Button variant="secondary" size="icon" class="h-10 w-10" :title="isPlaying ? 'Pause' : 'Play'" @click="togglePlay">
            <component :is="isPlaying ? Pause : Play" class="h-5 w-5" />
          </Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" title="Forward 10s" @click="seekBy(10)"><FastForward class="h-4 w-4" /></Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" title="Next" @click="next"><SkipForward class="h-4 w-4" /></Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" :class="state.loop !== 'off' && 'text-primary'" :title="`Loop: ${state.loop}`" @click="cycleLoop">
            <component :is="loopIcon" class="h-4 w-4" />
          </Button>
        </div>

        <div class="mt-2 flex items-center gap-2 text-muted-foreground">
          <Button variant="ghost" size="icon" class="h-7 w-7" :class="state.autoplay && 'text-primary'" title="Autoplay / radio" @click="toggleAutoplay"><Radio class="h-4 w-4" /></Button>
          <Button variant="ghost" size="icon" class="h-7 w-7" title="Stop" @click="stop"><Square class="h-4 w-4" /></Button>
          <Button
            variant="ghost"
            size="icon"
            class="h-7 w-7"
            :class="karaokeOpen && 'text-primary'"
            title="Karaoke — sing along"
            @click="toggleKaraoke"
          >
            <Mic2 class="h-4 w-4" />
          </Button>
          <!-- Force a fresh Spotify link + device — the manual escape hatch for a wedged player. -->
          <Button
            v-if="spotifyStatus.linked"
            variant="ghost"
            size="icon"
            class="h-7 w-7"
            :class="reconnectPrompt && 'text-amber-600 dark:text-amber-400'"
            :disabled="reconnecting"
            title="Reconnect Spotify"
            @click="onReconnectSpotify"
          >
            <RefreshCw class="h-4 w-4" :class="reconnecting && 'animate-spin'" />
          </Button>

          <select class="h-7 rounded border bg-background px-1 text-[11px] tabular-nums" :value="speed" title="Playback speed (YouTube engine only)" @change="onSpeed">
            <option v-for="opt in SPEED_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>

          <div class="ml-auto flex items-center gap-1.5">
            <button :title="muted ? 'Unmute' : 'Mute'" @click="toggleMute">
              <component :is="muted || volume === 0 ? VolumeX : Volume2" class="h-4 w-4" />
            </button>
            <input type="range" min="0" max="100" :value="muted ? 0 : volume" class="h-1 w-16 cursor-pointer accent-primary" :disabled="!joined" @input="onVolume">
          </div>
        </div>

        <!-- Karaoke pane: the lyrics ride the same shared clock the transport does, so
             every viewer is highlighted on the same line. -->
        <div v-if="karaokeOpen" class="mt-2.5 rounded-lg border bg-background/60">
          <div class="flex items-center gap-1.5 border-b px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            <Mic2 class="h-3 w-3" /> Karaoke
            <button class="ml-auto p-0.5 hover:text-foreground" title="Full screen" @click="stageOpen = true">
              <Maximize2 class="h-3 w-3" />
            </button>
            <button class="p-0.5 hover:text-foreground" title="Hide lyrics" @click="karaokeOpen = false">
              <X class="h-3 w-3" />
            </button>
          </div>
          <KaraokeLyrics
            :lines="lyricLines"
            :synced="lyricsSynced"
            :loading="lyricsLoading"
            :missing="lyricsMissing"
            :instrumental="instrumental"
            :position="karaokePosition"
            :offset="lyricOffset"
            @seek="seekTo"
            @nudge="nudgeLyrics"
            @reset-offset="resetLyricOffset"
          />
        </div>

        <!-- Listen-along opt-in: playback only starts from this click. -->
        <Button v-if="!joined" size="sm" class="mt-2.5 w-full gap-1.5" @click="joinListening">
          <Play class="h-3.5 w-3.5" /> Listen along
        </Button>
        <!-- Joined, but the player isn't making noise — usually a fresh iframe the browser
             won't autoplay after a pin hand-off. One click is all it wants. -->
        <Button v-else-if="blocked" size="sm" variant="secondary" class="mt-2.5 w-full gap-1.5" @click="joinListening">
          <Play class="h-3.5 w-3.5" /> Resume audio
        </Button>

        <!-- Recoverable Spotify failure (rejected token / dead device). A reconnect re-consents
             and re-registers a fresh device. -->
        <button
          v-if="reconnectPrompt"
          class="mt-2 w-full rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-[11px] font-medium text-amber-700 hover:bg-amber-500/20 disabled:opacity-60 dark:text-amber-400"
          :disabled="reconnecting"
          @click="onReconnectSpotify"
        >
          {{ reconnecting ? 'Reconnecting…' : reconnectPrompt }}
        </button>

        <!-- Real-Spotify nudge for a Spotify track this viewer can't play natively yet. -->
        <button
          v-else-if="spotifyOffer === 'connect'"
          class="mt-2 w-full rounded-md border border-green-500/30 bg-green-500/10 px-2 py-1 text-[11px] font-medium text-green-700 hover:bg-green-500/20 disabled:opacity-60 dark:text-green-400"
          :disabled="linkingSpotify"
          @click="onConnectSpotify"
        >
          {{ linkingSpotify ? 'Connecting…' : 'Connect Spotify Premium to hear the original' }}
        </button>
        <p v-else-if="spotifyOffer === 'premium'" class="mt-2 text-center text-[10px] text-muted-foreground">
          Spotify Premium is required for original playback — using YouTube.
        </p>
      </template>

      <!-- Up next -->
      <div v-if="upcoming.length" class="mt-3 border-t pt-2">
        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Up next · {{ upcoming.length }}</p>
        <ul class="space-y-0.5">
          <li
            v-for="(track, i) in upcoming.slice(0, 20)"
            :key="track.id"
            class="group flex items-center gap-2 rounded px-1 py-1 text-xs hover:bg-muted"
          >
            <span class="w-4 flex-none text-right text-[10px] text-muted-foreground">{{ i + 1 }}</span>
            <button class="min-w-0 flex-1 text-left" :class="track.unresolved && 'opacity-40'" :title="track.unresolved ? 'Not found on YouTube' : track.title" @click="jump((currentIndex ?? 0) + 1 + i)">
              <span class="block truncate" :class="track.unresolved && 'line-through'">{{ track.title }}</span>
              <span v-if="track.artist" class="block truncate text-muted-foreground">{{ track.artist }}</span>
            </button>
            <span class="flex-none tabular-nums text-muted-foreground">{{ fmt(track.duration) }}</span>
            <span class="flex flex-none items-center opacity-0 group-hover:opacity-100">
              <button class="p-0.5 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Move up" :disabled="i === 0" @click="moveTrack(i + 1, -1)"><ArrowUp class="h-3.5 w-3.5" /></button>
              <button class="p-0.5 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Move down" :disabled="i === upcoming.length - 1" @click="moveTrack(i + 1, 1)"><ArrowDown class="h-3.5 w-3.5" /></button>
              <button class="p-0.5 text-muted-foreground hover:text-destructive" title="Remove" @click="removeTrack(track)"><X class="h-3.5 w-3.5" /></button>
            </span>
          </li>
        </ul>
        <p v-if="upcoming.length > 20" class="mt-1 pl-6 text-[10px] text-muted-foreground">+{{ upcoming.length - 20 }} more</p>
      </div>
    </div>

    <!-- Full-screen sing-along. Teleports out of the message list; the transport buttons
         come back here so there's still one place that drives the shared state. -->
    <KaraokeStage
      v-if="stageOpen && current"
      :track="current"
      :lines="lyricLines"
      :synced="lyricsSynced"
      :loading="lyricsLoading"
      :missing="lyricsMissing"
      :instrumental="instrumental"
      :position="karaokePosition"
      :offset="lyricOffset"
      :duration="duration"
      :playing="isPlaying"
      :fmt="fmt"
      @close="stageOpen = false"
      @toggle="togglePlay"
      @next="next"
      @prev="prev"
      @seek="seekTo"
      @nudge="nudgeLyrics"
      @reset-offset="resetLyricOffset"
    />

    <!-- The hidden YouTube player element (the Spotify SDK needs no DOM node). -->
    <div class="pointer-events-none absolute h-0 w-0 overflow-hidden opacity-0"><div ref="mountEl" /></div>
  </div>
</template>
