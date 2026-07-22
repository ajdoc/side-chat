/**
 * The Spotify Web Playback SDK engine, held as one app-wide singleton.
 *
 * The SDK registers a *device* the instant it connects, and Spotify's playback API will only
 * play to a device it can currently see. Every fresh connection registers a brand-new device
 * id and orphans the previous one (which lingers in /me/player/devices as a ghost for
 * minutes). So a player rebuilt on every component mount — exactly what the pin hand-off
 * between the timeline card and the dock used to do — spits out a new device each time and
 * then races its own stale id, which surfaces as `404 Device not found` on /me/player/play.
 *
 * Holding the player at module scope fixes that at the source: it is created once, outlives
 * every mount/unmount, and keeps a single stable device for the whole tab. The reactive bits
 * (ready, deviceId, the error flags) are shared refs, so whichever MusicPlayer happens to be
 * on screen — timeline card or dock — reads the same truth. See MusicPlayer and MusicDock.
 */

// --- the singleton, at module scope so it outlives every component ---
let player: any = null
let creating: Promise<void> | null = null
let $spotify: any = null
let getTokenFn: (() => Promise<string | null>) | null = null
let canUseRef: { value: boolean } | null = null
let tokenCache: { token: string, exp: number } | null = null

const ready = ref(false)
const deviceId = ref<string | null>(null)
const playbackState = ref<{ position: number, duration: number, paused: boolean } | null>(null)

// The SDK reported this account can't stream (not Premium). Fall back to YouTube for good.
const accountError = ref(false)
// The token was rejected outright (stale scopes) — needs a fresh consent, not a refresh.
const authError = ref(false)
// The device 404s even after a transfer — a ghost/mismatched device. A reconnect re-registers.
const deviceError = ref(false)

// What uri the singleton currently has loaded. Module scope on purpose: a fresh MusicPlayer
// mount must NOT restart a track the shared player is already playing.
let loadedUri: string | null = null

// End-of-track detection state — Spotify plays a single uri with no "up next", so it just
// stops at the end and someone has to notice and advance the room, exactly once.
const endedFor = new Set<string>()
let lastPos = 0

// 401 (token expired/invalid) or 403 (insufficient scope) from a playback call — the link is
// there but the token can't act. ofetch surfaces the code on the thrown error.
function isAuthError(err: any): boolean {
  const status = err?.status ?? err?.statusCode ?? err?.response?.status
  return status === 401 || status === 403
}

async function cachedToken(): Promise<string | null> {
  if (tokenCache && tokenCache.exp > Date.now() + 5000) return tokenCache.token
  const t = getTokenFn ? await getTokenFn() : null
  if (t) tokenCache = { token: t, exp: Date.now() + 50 * 60 * 1000 }
  return t
}

async function ensure(): Promise<void> {
  if (player || !canUseRef?.value) return
  if (creating) return creating
  creating = (async () => {
    const Spotify = await $spotify.ready()
    if (player) return // a concurrent ensure() won the race
    player = new Spotify.Player({
      name: 'Side Chat',
      // Share the one cached token with the playback calls below — the SDK registers its
      // device under whatever token this returns, so if the two diverged the device would
      // 404. One source keeps them on one identity.
      getOAuthToken: (cb: (t: string) => void) => { cachedToken().then(t => t && cb(t)) },
      volume: 1,
    })
    player.addListener('ready', ({ device_id }: any) => {
      deviceId.value = device_id
      ready.value = true
      // A reconnect hands us a *new* device id; drop the loaded uri so the next reconcile
      // re-issues play against it instead of assuming the (now-gone) old device still has it.
      loadedUri = null
    })
    player.addListener('not_ready', () => { ready.value = false })
    player.addListener('player_state_changed', (st: any) => {
      if (st) playbackState.value = { position: st.position / 1000, duration: st.duration / 1000, paused: st.paused }
    })
    // The decisive "you can't stream" signal — free accounts land here. Fall back for good.
    player.addListener('account_error', () => { accountError.value = true; idle(true) })
    // The token was rejected — needs fresh consent, not a refresh. Prompt reconnect.
    player.addListener('authentication_error', () => { ready.value = false; authError.value = true })
    player.connect()
  })()
  try { await creating }
  finally { creating = null }
}

/**
 * Ask Spotify which device is *actually* ours right now.
 *
 * The SDK's `ready` device_id can desync from what Spotify has registered (a stale/ghost id
 * after a reconnect), and playing to that id 404s no matter how many times we reconnect. The
 * live /devices list is the source of truth: prefer the entry that matches our SDK id, else
 * fall back to our named device. Returns null if neither is present.
 */
async function resolveDeviceId(token: string): Promise<string | null> {
  try {
    const res: any = await $fetch('https://api.spotify.com/v1/me/player/devices', {
      headers: { Authorization: `Bearer ${token}` },
    })
    const devices: any[] = res?.devices ?? []
    return devices.find(d => d.id === deviceId.value)?.id
      ?? devices.find(d => d.name === 'Side Chat')?.id
      ?? null
  } catch {
    return null
  }
}

async function start(uri: string, posSec: number, wantPlaying: boolean): Promise<void> {
  const token = await cachedToken()
  if (!token || !deviceId.value) return
  const auth = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }
  const playTo = (id: string) => $fetch(`https://api.spotify.com/v1/me/player/play?device_id=${id}`, {
    method: 'PUT',
    headers: auth,
    body: { uris: [uri], position_ms: Math.round(posSec * 1000) },
  })
  const transferTo = (id: string) => $fetch('https://api.spotify.com/v1/me/player', {
    method: 'PUT',
    headers: auth,
    body: { device_ids: [id], play: false },
  })
  const sleep = (ms: number) => new Promise(r => setTimeout(r, ms))

  try {
    await playTo(deviceId.value)
  } catch (err) {
    // A rejected token (stale scopes) — fall back and prompt a reconnect, don't retry.
    if (isAuthError(err)) { authError.value = true; loadedUri = null; return }
    // A just-`ready` SDK device is registered but not yet the *active* device, so the first
    // /play can 404 ("Device not found"). Activate it with a transfer, then retry once.
    try {
      await transferTo(deviceId.value)
      await sleep(400)
      await playTo(deviceId.value)
    } catch (err2) {
      if (isAuthError(err2)) { authError.value = true; loadedUri = null; return }
      // Still 404ing after a transfer: the SDK id has desynced from what Spotify actually
      // has. Resolve the live device and try *that* — this is what a reconnect alone can't
      // fix, because reconnecting just mints another mismatched id.
      const live = await resolveDeviceId(token)
      if (live && live !== deviceId.value) {
        deviceId.value = live
        try {
          await transferTo(live)
          await sleep(400)
          await playTo(live)
        } catch (err3) {
          if (isAuthError(err3)) authError.value = true
          else deviceError.value = true
          loadedUri = null
          return
        }
      } else {
        // The device genuinely isn't in Spotify's list (or is the same dead id) — give up and
        // let the caller fall back to YouTube and offer a reconnect.
        deviceError.value = true
        loadedUri = null
        return
      }
    }
  }
  if (!wantPlaying) setTimeout(() => player?.pause?.(), 300)
}

/** Point the shared player at the room's current track/position/state. */
async function reconcile(uri: string, trackId: string, targetPos: number, wantPlaying: boolean): Promise<void> {
  await ensure()
  if (!ready.value || !player || !deviceId.value) return

  if (loadedUri !== uri) {
    loadedUri = uri
    endedFor.delete(trackId)
    await start(uri, targetPos, wantPlaying)
    return
  }

  // Coarse reconcile off the last polled state (getCurrentState is async — see poll()).
  const cur = playbackState.value
  if (wantPlaying) {
    if (cur?.paused) player.resume()
    if (cur && Math.abs(cur.position - targetPos) > 2) player.seek(Math.round(targetPos * 1000))
  } else if (cur && !cur.paused) {
    player.pause()
  }
}

/** Pause the shared player; `hard` also forgets the loaded track so the next play reloads. */
function idle(hard = false): void {
  if (player && ready.value && loadedUri) {
    player.pause?.()
    if (hard) loadedUri = null
  }
}

/** Poll the live SDK state; also the freshest reading for the progress readout. */
async function poll(): Promise<{ position: number, duration: number, paused: boolean } | null> {
  const st = await player?.getCurrentState?.()
  if (st) playbackState.value = { position: st.position / 1000, duration: st.duration / 1000, paused: st.paused }
  return st ? playbackState.value : null
}

/**
 * Has `trackId` just ended? Detects the single-uri stop (near the end while playing, or paused
 * right after) and returns the id to advance the room — once. Null otherwise.
 */
function takeEndedTrack(trackId: string | undefined): string | null {
  const st = playbackState.value
  if (!trackId || !st || !st.duration || endedFor.has(trackId)) return null
  if (!st.paused) lastPos = st.position
  const nearEnd = st.duration - st.position < 1.2
  const stoppedAtEnd = st.paused && lastPos > 0 && st.duration - lastPos < 2.5
  if ((!st.paused && nearEnd) || stoppedAtEnd) {
    endedFor.add(trackId)
    return trackId
  }
  return null
}

function setVolume(v0to100: number): void {
  if (ready.value) player?.setVolume?.(v0to100 / 100)
}

/** Tear the player down for a fresh consent (see MusicPlayer.onReconnectSpotify). */
function teardown(): void {
  try { player?.disconnect?.() } catch { /* already gone */ }
  player = null
  ready.value = false
  deviceId.value = null
  loadedUri = null
  tokenCache = null
  authError.value = false
  deviceError.value = false
}

export function useSpotifyEngine() {
  const nuxt = useNuxtApp() as any
  const { canUseSpotify, getToken } = useSpotifyAuth()
  // Capture the per-tab dependencies once; useState/plugins hand back stable references.
  if (!$spotify) $spotify = nuxt.$spotify
  getTokenFn = getToken
  canUseRef = canUseSpotify

  return {
    ready,
    deviceId,
    accountError,
    authError,
    deviceError,
    ensure,
    reconcile,
    idle,
    poll,
    takeEndedTrack,
    setVolume,
    teardown,
  }
}
