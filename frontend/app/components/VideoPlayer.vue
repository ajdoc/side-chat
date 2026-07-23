<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core'
import { ArrowDown, ArrowUp, FastForward, Film, FolderOpen, Maximize2, Pause, Play, Repeat, Repeat1, Rewind, Search, Shuffle, SkipBack, SkipForward, Square, Trash2, TriangleAlert, Upload, Volume2, VolumeX, X } from 'lucide-vue-next'
import type { VideoSource, VideoState, Widget } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The watch-along video card — one screen a whole channel sits in front of.
 *
 * It's the picture half of the same bargain {@link MusicPlayer} strikes for sound: the server
 * owns the transport (playlist, what's on screen, playing/paused, speed, and the position as
 * of `updated_at`) and never a frame of video. This component's job is to make *a player*
 * obey that shared truth — load the right source and keep it where the room is. Nobody
 * streams to anyone; every viewer plays their own copy in lockstep. Volume and fullscreen are
 * the local things.
 *
 * Three players, chosen by the current source's `kind` (decided server-side — see
 * VideoResolver):
 *   - `youtube` — the IFrame Player API, driven exactly like the music player drives it.
 *   - `file`    — a plain <video>: a clip someone uploaded (served from a signed URL) or a
 *                 direct link to an .mp4/.webm. Seekable, so it stays in lockstep.
 *   - `embed`   — the provider's own iframe (Vimeo, Dailymotion, Twitch, Streamable). It gets
 *                 the room's offset when it loads and nothing after that: a third-party iframe
 *                 won't take a seek from us. The card labels these rather than quietly drifting
 *                 and letting people think they're together when they aren't.
 *
 * Playback only ever starts from a real click ("Watch along"), which is what keeps browsers
 * from blocking it. The transport buttons drive the shared state for everyone either way — you
 * can run the room's screening without watching it yourself.
 */
const props = defineProps<{ widget: Widget }>()

const { $youtube } = useNuxtApp() as any
const { action } = useWidgets()
const { upload: stageUpload } = useChunkedUpload()

const state = computed(() => props.widget.state as VideoState)
const playlist = computed<VideoSource[]>(() => state.value.playlist ?? [])
const currentIndex = computed(() => state.value.currentIndex)
const current = computed<VideoSource | null>(() =>
  currentIndex.value != null ? playlist.value[currentIndex.value] ?? null : null,
)
// Everything after what's on screen — or the whole list when nothing is seated yet.
const upcoming = computed(() =>
  currentIndex.value == null ? playlist.value : playlist.value.slice(currentIndex.value + 1),
)
const upcomingIndex = (i: number) => (currentIndex.value ?? -1) + 1 + i
const isPlaying = computed(() => state.value.status === 'playing')
const speed = computed(() => state.value.speed ?? 1)
const pending = computed(() => state.value.pendingSearch ?? null)
const kind = computed(() => current.value?.kind ?? null)
/** An iframe we can start but can't steer — the room shares a start time and nothing more. */
const unsteerable = computed(() => kind.value === 'embed')

const SPEED_OPTIONS = [0.5, 0.75, 1, 1.25, 1.5, 2]

const PROVIDER_LABEL: Record<string, string> = {
  youtube: 'YouTube', vimeo: 'Vimeo', dailymotion: 'Dailymotion',
  twitch: 'Twitch', streamable: 'Streamable', direct: 'Direct link',
  upload: 'Uploaded', attachment: 'From this chat',
}

// Has this viewer opted in to watching along? Nothing plays until they do — a browser won't
// let an unprompted iframe make noise, and a silently-muted screening is worse than an
// honest button.
const joined = ref(false)
const volume = useLocalStorage('video:volume', 100)
const muted = ref(false)

// --- players ------------------------------------------------------------
const stageEl = ref<HTMLElement | null>(null)
const ytMount = ref<HTMLElement | null>(null)
const videoEl = ref<HTMLVideoElement | null>(null)

let yt: any = null
let ytReady = false
/**
 * Which source each player currently holds — tracked by the *source's id*, never by its URL.
 *
 * An uploaded clip's URL is a signed one minted per response, so it's a different string every
 * time the state is re-fetched even though it points at the same bytes. Comparing URLs would
 * reload the video (and jump back to the start) on every unrelated widget update.
 */
let loadedYouTubeKey: string | null = null
let loadedFileId: string | null = null
/** Where to seek a <video> the moment it knows its own length. */
let pendingSeek: number | null = null
const reportedDuration = new Set<string>()

// The iframe src for an `embed` source, built once per source with the room's offset baked in.
// Deliberately not reactive to `position`: recomputing it would reload the iframe on every
// state change, which is a worse experience than the drift it would be trying to fix.
const embedSrc = ref('')

let ticker: ReturnType<typeof setInterval> | null = null
const displayTime = ref(0)
const duration = ref(0)

/** Where the current video should be *right now*, extrapolating from the server snapshot. */
function targetPosition(): number {
  const s = state.value
  const base = s.position ?? 0
  if (s.status !== 'playing') return base
  const elapsed = (Date.now() - Date.parse(s.updated_at)) / 1000
  return Math.max(0, base + elapsed * (s.speed ?? 1))
}

// ---- reconcile: point the *active* player at the shared state, idle the others ----
function sync() {
  if (!current.value) { idleYouTube(); idleFile(); return }
  if (kind.value === 'youtube') { idleFile(); syncYouTube() }
  else if (kind.value === 'file') { idleYouTube(); syncFile() }
  else { idleYouTube(); idleFile() } // an embed steers itself
}

function syncYouTube() {
  if (!ytReady || !yt || !current.value?.key) return

  if (loadedYouTubeKey !== current.value.key) {
    loadedYouTubeKey = current.value.key
    const start = targetPosition()
    if (joined.value && isPlaying.value) yt.loadVideoById({ videoId: current.value.key, startSeconds: start })
    else yt.cueVideoById({ videoId: current.value.key, startSeconds: start })
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
  }
  else {
    if (drift > 1) yt.seekTo(targetPosition(), true)
    yt.pauseVideo()
  }
}

function syncFile() {
  const el = videoEl.value
  const src = current.value?.url
  if (!el || !src) return

  if (loadedFileId !== current.value!.id) {
    loadedFileId = current.value!.id
    // Hold the seek until the element knows its duration — setting currentTime on a video
    // that hasn't loaded metadata is simply dropped, which would strand a late joiner at 0.
    pendingSeek = targetPosition()
    el.src = src
    el.load()
    return
  }

  el.playbackRate = speed.value
  if (!joined.value || el.readyState < 1) return

  const drift = Math.abs(el.currentTime - targetPosition())
  if (isPlaying.value) {
    if (drift > 1.5) el.currentTime = targetPosition()
    if (el.paused) el.play().catch(() => { /* still needs a gesture — the Watch along button gives it */ })
  }
  else {
    if (drift > 1) el.currentTime = targetPosition()
    if (!el.paused) el.pause()
  }
}

/** The <video> now knows its length: apply the held seek, and start if the room is playing. */
function onFileReady() {
  const el = videoEl.value
  if (!el) return
  if (pendingSeek !== null) { el.currentTime = pendingSeek; pendingSeek = null }
  el.playbackRate = speed.value
  applyVolume()
  if (joined.value && isPlaying.value) el.play().catch(() => { /* awaiting a gesture */ })
  maybeReportDuration(Math.round(el.duration))
}

function idleYouTube() {
  if (ytReady && yt && loadedYouTubeKey) yt.pauseVideo?.()
}

function idleFile() {
  const el = videoEl.value
  if (el && !el.paused) el.pause()
}

/** Build an `embed` source's iframe URL, with the room's current offset where it's supported. */
function buildEmbedSrc(source: VideoSource): string {
  const base = source.embedUrl ?? ''
  if (!base) return ''
  const at = Math.floor(targetPosition())
  const join = base.includes('?') ? '&' : '?'

  if (!at) return base
  switch (source.provider) {
    case 'vimeo': return `${base}#t=${at}s`
    case 'dailymotion': return `${base}${join}start=${at}`
    case 'streamable': return `${base}${join}t=${at}`
    // Twitch wants h/m/s rather than a bare count, and only honours it on VODs.
    case 'twitch': return `${base}&t=${Math.floor(at / 3600)}h${Math.floor((at % 3600) / 60)}m${at % 60}s`
    default: return base
  }
}

// ---- ticker: the progress readout, and end-of-video for the players we drive ----
function tick() {
  if (kind.value === 'youtube' && joined.value && ytReady && yt?.getCurrentTime) {
    displayTime.value = yt.getCurrentTime() || 0
    duration.value = yt.getDuration() || current.value?.duration || 0
    maybeReportDuration(Math.round(yt.getDuration?.() || 0))
    return
  }
  if (kind.value === 'file' && joined.value && videoEl.value?.readyState) {
    displayTime.value = videoEl.value.currentTime
    duration.value = videoEl.value.duration || current.value?.duration || 0
    return
  }
  // Not watching, or watching something we can't read a clock off — fall back to the room's
  // own extrapolated position, which is what the shared state says anyway.
  displayTime.value = targetPosition()
  duration.value = current.value?.duration || 0
}

/**
 * Tell the server a length it didn't know. Uploads and direct links have no metadata lookup
 * behind them, so this is the only way their duration is ever filled in — and it's worth
 * doing once, from whoever gets there first, rather than every viewer every tick.
 *
 * Reported through `action` rather than {@link send}: these two come from the *player*, not a
 * button, and send()'s in-flight guard would silently drop one that happened to land while a
 * click was resolving — which for `ended` would leave the screening stuck on a finished video.
 */
function maybeReportDuration(seconds: number) {
  const source = current.value
  if (!source || source.duration || reportedDuration.has(source.id)) return
  if (!Number.isFinite(seconds) || seconds <= 0) return
  reportedDuration.add(source.id)
  void action(props.widget.id, 'meta', { id: source.id, duration: seconds })
}

/** Our player ran off the end. The server ignores all but the first report. */
function onEnded() {
  if (current.value) void action(props.widget.id, 'ended', { id: current.value.id })
}

async function ensureYouTube() {
  if (yt || !ytMount.value) return
  const YT = await $youtube.ready()
  if (!ytMount.value) return
  yt = new YT.Player(ytMount.value, {
    height: '100%',
    width: '100%',
    // Our own controls drive the *shared* transport; YouTube's would only move this one
    // screen, which is exactly the thing a watch-along must not allow.
    playerVars: { autoplay: 0, controls: 0, disablekb: 1, playsinline: 1, modestbranding: 1, rel: 0 },
    events: {
      onReady: () => { ytReady = true; applyVolume(); sync() },
      onStateChange: (e: any) => {
        if (e.data === YT.PlayerState.ENDED && kind.value === 'youtube') onEnded()
      },
    },
  })
}

function applyVolume() {
  const v = muted.value ? 0 : volume.value
  if (ytReady) yt?.setVolume?.(v)
  if (videoEl.value) videoEl.value.volume = v / 100
}

/** Opt in to the screening. The click is the gesture that lets a player make noise. */
function watchAlong() {
  joined.value = true
  // Re-seat from scratch: the players were only ever cued, and an embed needs its offset
  // recomputed for *now* rather than whenever the card first rendered.
  loadedYouTubeKey = null
  loadedFileId = null
  if (current.value && unsteerable.value) embedSrc.value = buildEmbedSrc(current.value)
  sync()
  // Inside the click, while the gesture still counts.
  if (isPlaying.value) {
    try { yt?.playVideo?.() } catch { /* not ready — sync will */ }
    videoEl.value?.play().catch(() => { /* will retry on the next sync */ })
  }
}

// --- transport (drives the shared state for everyone) ---
const busy = ref(false)
// The last soft failure from an action (an unreadable link, a full playlist). Rendered inline:
// the card's own add field has no chat line to fall back to.
const feedback = ref<string | null>(null)

async function send(name: string, payload: Record<string, unknown> = {}): Promise<string | null> {
  if (busy.value) return null
  busy.value = true
  try {
    const reply = await action(props.widget.id, name, payload)
    feedback.value = reply
    return reply
  }
  finally { busy.value = false }
}

const togglePlay = () => send(isPlaying.value ? 'pause' : 'resume')
const next = () => send('next')
const prev = () => send('prev')
const stop = () => send('stop')
const shuffle = () => send('shuffle')
const cycleLoop = () => send('loop')
const jump = (i: number) => send('jump', { index: i })
const removeSource = (s: VideoSource) => send('remove', { id: s.id })
const moveSource = (pos: number, dir: -1 | 1) => send('move', { from: pos, to: pos + dir })
const seekBy = (secs: number) => send('seek', { position: Math.max(0, displayTime.value + secs) })
const onSpeed = (e: Event) => send('speed', { value: Number((e.target as HTMLSelectElement).value) })
const pick = (i: number) => send('pick', { index: i })
const cancelSearch = () => send('cancelSearch')
const clearAll = () => send('clear')

// Add from inside the card — `v!play` isn't reachable everywhere (the Open Canvas has no
// message box), so the player carries its own link/search field.
const addQuery = ref('')
async function addVideo() {
  const q = addQuery.value.trim()
  if (!q) return
  const reply = await send('add', { query: q })
  // Keep the text when it failed so they can tweak and retry; clear it on a clean add.
  if (!reply) addQuery.value = ''
}
watch(addQuery, () => { if (feedback.value) feedback.value = null })

// --- uploading ----------------------------------------------------------
// A file goes up through the ordinary chunked-upload staging area, and only its id is handed
// to the widget — the same two-step every large attachment takes. See useChunkedUpload.
const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref<{ name: string, progress: number } | null>(null)
const dragging = ref(false)

async function uploadFiles(files: FileList | File[] | null) {
  if (!files?.length || uploading.value) return

  for (const file of Array.from(files)) {
    if (!file.type.startsWith('video/') && !/\.(mp4|m4v|webm|ogv|mov|mkv)$/i.test(file.name)) {
      feedback.value = `“${file.name}” doesn't look like a video file.`
      continue
    }

    uploading.value = { name: file.name, progress: 0 }
    try {
      const id = await stageUpload(file, {
        onProgress: (fraction) => { if (uploading.value) uploading.value.progress = fraction },
      })
      await send('upload', { upload: id })
    }
    catch (e: any) {
      feedback.value = e?.message ?? `Couldn't upload “${file.name}”.`
    }
    finally {
      uploading.value = null
    }
  }

  if (fileInput.value) fileInput.value.value = ''
}

function onDrop(e: DragEvent) {
  dragging.value = false
  void uploadFiles(e.dataTransfer?.files ?? null)
}

// --- videos already in this chat ----------------------------------------
// Anything posted in this channel — timeline, threads, side chats — can go on the playlist
// without being uploaded again. The server adds it by reference, so picking a two-gigabyte
// film here costs nothing and doesn't touch the message it was posted in.
const { videos: libraryVideos, loading: libraryLoading, load: loadLibrary } = useChannelVideos()
const libraryOpen = ref(false)
const libraryQuery = ref('')

function toggleLibrary() {
  libraryOpen.value = !libraryOpen.value
  if (libraryOpen.value) void loadLibrary(props.widget.channel_id, libraryQuery.value)
}

// Debounced so typing doesn't fire a request per keystroke; the composable drops any response
// that arrives out of order behind it.
let librarySearchTimer: ReturnType<typeof setTimeout> | null = null
watch(libraryQuery, (q) => {
  if (!libraryOpen.value) return
  if (librarySearchTimer) clearTimeout(librarySearchTimer)
  librarySearchTimer = setTimeout(() => loadLibrary(props.widget.channel_id, q), 250)
})

async function addFromChat(file: { id: number }) {
  await send('addAttachment', { attachment: file.id })
}

function fmtSize(bytes: number): string {
  if (!bytes) return ''
  const mb = bytes / 1024 / 1024
  return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.max(1, Math.round(mb))} MB`
}

// --- local controls -----------------------------------------------------
function toggleMute() { muted.value = !muted.value; applyVolume() }
function onVolume(e: Event) {
  volume.value = Number((e.target as HTMLInputElement).value)
  muted.value = false
  applyVolume()
}

function onScrub(e: MouseEvent) {
  if (!duration.value || unsteerable.value) return
  const bar = e.currentTarget as HTMLElement
  const ratio = (e.clientX - bar.getBoundingClientRect().left) / bar.offsetWidth
  void send('seek', { position: Math.max(0, Math.min(1, ratio)) * duration.value })
}

function toggleFullscreen() {
  if (document.fullscreenElement) void document.exitFullscreen()
  else void stageEl.value?.requestFullscreen?.()
}

function fmt(secs: number | null | undefined): string {
  if (!secs || !isFinite(secs)) return '--:--'
  const s = Math.max(0, Math.floor(secs))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = (s % 60).toString().padStart(2, '0')
  return h > 0 ? `${h}:${m.toString().padStart(2, '0')}:${sec}` : `${m}:${sec}`
}

const loopIcon = computed(() => (state.value.loop === 'one' ? Repeat1 : Repeat))
const progress = computed(() => (duration.value ? Math.min(100, (displayTime.value / duration.value) * 100) : 0))

// One key covering everything a player has to react to, so a single watcher does the
// reconciling rather than a scatter of them racing each other.
const syncKey = computed(() =>
  [kind.value, current.value?.id, state.value.status, state.value.position, state.value.updated_at, state.value.speed, joined.value].join('|'),
)
watch(syncKey, () => sync())
watch([volume, muted], applyVolume)

// A new `embed` source needs a fresh iframe; anything else must not get one, or a passing
// state change would reload the screen mid-video.
watch(() => current.value?.id, () => {
  embedSrc.value = current.value && current.value.kind === 'embed' ? buildEmbedSrc(current.value) : ''
}, { immediate: true })

onMounted(() => {
  ensureYouTube()
  ticker = setInterval(tick, 500)
})
onBeforeUnmount(() => {
  if (ticker) clearInterval(ticker)
  if (librarySearchTimer) clearTimeout(librarySearchTimer)
  try { yt?.destroy?.() } catch { /* already gone */ }
  yt = null
})
</script>

<template>
  <div
    class="mt-1.5 w-full max-w-xl overflow-hidden rounded-xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-sm"
    :class="dragging && 'ring-2 ring-primary'"
    @dragover.prevent="dragging = true"
    @dragleave="dragging = false"
    @drop.prevent="onDrop"
  >
    <div class="flex items-center gap-1.5 border-b bg-background/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <Film class="h-3.5 w-3.5" /> Video
      <span v-if="current" class="ml-auto flex items-center gap-1.5">
        <span class="rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case text-primary">
          {{ PROVIDER_LABEL[current.provider] ?? current.provider }}
        </span>
      </span>
    </div>

    <!-- The screen. 16:9 whatever's in it, so the card doesn't jump as sources change. -->
    <div ref="stageEl" class="relative aspect-video w-full bg-black">
      <!-- YouTube's iframe is mounted once and kept, so switching back to a YouTube source
           doesn't rebuild a player the browser would then refuse to unmute. -->
      <div v-show="kind === 'youtube' && current" class="absolute inset-0">
        <div ref="ytMount" class="h-full w-full" />
      </div>

      <video
        v-show="kind === 'file' && current"
        ref="videoEl"
        class="absolute inset-0 h-full w-full bg-black"
        playsinline
        preload="metadata"
        @loadedmetadata="onFileReady"
        @ended="onEnded"
      />

      <iframe
        v-if="kind === 'embed' && current && embedSrc"
        :src="embedSrc"
        class="absolute inset-0 h-full w-full border-0"
        allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
        allowfullscreen
      />

      <!-- Nothing queued yet. -->
      <div v-if="!current" class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-center text-xs text-muted-foreground">
        <Film class="h-7 w-7 opacity-40" />
        <p class="px-6">Paste a link or upload a file to start watching together.</p>
      </div>

      <!-- The opt-in. Playback can't begin without a gesture, so ask for one plainly rather
           than starting muted and looking broken. -->
      <button
        v-else-if="!joined"
        class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/70 text-white transition hover:bg-black/60"
        @click="watchAlong"
      >
        <Play class="h-9 w-9" />
        <span class="text-sm font-medium">Watch along</span>
        <span class="max-w-xs px-6 text-[11px] text-white/70">
          {{ isPlaying ? 'The room is already playing — you’ll join where they are.' : 'You’ll be in sync with everyone else here.' }}
        </span>
      </button>
    </div>

    <div class="p-3">
      <!-- Title + who added it -->
      <div v-if="current" class="mb-2 min-w-0">
        <p class="truncate text-sm font-medium" :title="current.title">{{ current.title }}</p>
        <p class="truncate text-[11px] text-muted-foreground">
          <span v-if="current.author">{{ current.author }} · </span>added by {{ current.addedBy }}
        </p>
      </div>

      <!-- A borrowed attachment whose message has since been deleted. The playlist entry
           outlives the file, so say what happened rather than showing a dead player. -->
      <p v-if="current?.missing" class="mb-2 flex items-start gap-1 text-[11px] text-amber-600 dark:text-amber-400">
        <TriangleAlert class="mt-px h-3 w-3 flex-none" />
        <span>This file was deleted from the chat, so it can’t be played. Skip it or remove it from the playlist.</span>
      </p>

      <!-- An iframe we can start but not steer. Say so — a viewer who thinks they're synced
           and isn't will assume the widget is broken. -->
      <p v-else-if="current && unsteerable" class="mb-2 flex items-start gap-1 text-[11px] text-muted-foreground">
        <TriangleAlert class="mt-px h-3 w-3 flex-none" />
        <span>{{ PROVIDER_LABEL[current.provider] ?? 'This player' }} runs in its own player — everyone starts together, but pause and seek only affect your screen.</span>
      </p>

      <!-- Progress. Scrubbing moves the *room*, which is why it's disabled for an embed. -->
      <div v-if="current" class="mb-2">
        <div
          class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
          :class="unsteerable ? 'opacity-50' : 'cursor-pointer'"
          @click="onScrub"
        >
          <div class="h-full rounded-full bg-primary transition-[width] duration-300" :style="{ width: `${progress}%` }" />
        </div>
        <div class="mt-1 flex justify-between text-[10px] tabular-nums text-muted-foreground">
          <span>{{ fmt(displayTime) }}</span>
          <span>{{ fmt(duration || current.duration) }}</span>
        </div>
      </div>

      <!-- Transport -->
      <div v-if="current" class="mb-3 flex flex-wrap items-center gap-1">
        <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Previous" @click="prev"><SkipBack class="h-4 w-4" /></button>
        <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Back 10s" :disabled="unsteerable" @click="seekBy(-10)"><Rewind class="h-4 w-4" /></button>
        <button class="rounded-full bg-primary p-2 text-primary-foreground hover:opacity-90" :title="isPlaying ? 'Pause for everyone' : 'Play for everyone'" @click="togglePlay">
          <component :is="isPlaying ? Pause : Play" class="h-4 w-4" />
        </button>
        <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Forward 10s" :disabled="unsteerable" @click="seekBy(10)"><FastForward class="h-4 w-4" /></button>
        <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Next" @click="next"><SkipForward class="h-4 w-4" /></button>
        <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Stop (keeps the playlist)" @click="stop"><Square class="h-4 w-4" /></button>

        <span class="mx-1 h-4 w-px bg-border" />

        <button
          class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
          :class="state.loop !== 'off' && 'text-primary'"
          :title="`Loop: ${state.loop}`"
          @click="cycleLoop"
        >
          <component :is="loopIcon" class="h-4 w-4" />
        </button>
        <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Shuffle what's next" @click="shuffle"><Shuffle class="h-4 w-4" /></button>

        <select
          class="ml-1 rounded border bg-background/60 px-1 py-0.5 text-[11px] outline-none"
          :value="speed"
          title="Playback speed (shared)"
          @change="onSpeed"
        >
          <option v-for="s in SPEED_OPTIONS" :key="s" :value="s">{{ s }}×</option>
        </select>

        <span class="ml-auto flex items-center gap-1">
          <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" :title="muted ? 'Unmute' : 'Mute'" @click="toggleMute">
            <component :is="muted || volume === 0 ? VolumeX : Volume2" class="h-4 w-4" />
          </button>
          <input type="range" min="0" max="100" :value="muted ? 0 : volume" class="h-1 w-16 accent-primary" title="Volume (just yours)" @input="onVolume">
          <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Fullscreen" @click="toggleFullscreen"><Maximize2 class="h-4 w-4" /></button>
        </span>
      </div>

      <!-- Add: a link, a search, or a file. -->
      <form class="flex items-center gap-1.5" @submit.prevent="addVideo">
        <div class="flex min-w-0 flex-1 items-center gap-1.5 rounded-lg border bg-background/60 px-2">
          <Search class="h-3.5 w-3.5 flex-none text-muted-foreground" />
          <input
            v-model="addQuery"
            placeholder="Paste a link, or search YouTube…"
            class="min-w-0 flex-1 bg-transparent py-1.5 text-xs outline-none placeholder:text-muted-foreground"
          >
        </div>
        <Button type="submit" size="sm" class="h-8 flex-none" :disabled="busy || !addQuery.trim()">Add</Button>
        <button
          type="button"
          class="flex h-8 flex-none items-center gap-1 rounded-lg border px-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          :class="libraryOpen && 'bg-muted text-foreground'"
          title="Videos already posted in this chat"
          @click="toggleLibrary"
        >
          <FolderOpen class="h-3.5 w-3.5" />
        </button>
        <button
          type="button"
          class="flex h-8 flex-none items-center gap-1 rounded-lg border px-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          title="Upload a video file"
          :disabled="!!uploading"
          @click="fileInput?.click()"
        >
          <Upload class="h-3.5 w-3.5" />
        </button>
        <input ref="fileInput" type="file" accept="video/*" class="hidden" multiple @change="uploadFiles(($event.target as HTMLInputElement).files)">
      </form>

      <!-- Videos already posted in this chat. Adding one is a reference, not a copy — it
           leaves the message it came from untouched. -->
      <div v-if="libraryOpen" class="mt-2 rounded-lg border bg-background/60 p-2">
        <div class="mb-1.5 flex items-center gap-1.5">
          <FolderOpen class="h-3.5 w-3.5 flex-none text-muted-foreground" />
          <input
            v-model="libraryQuery"
            placeholder="Search videos in this chat…"
            class="min-w-0 flex-1 bg-transparent text-xs outline-none placeholder:text-muted-foreground"
          >
          <button class="flex-none text-muted-foreground hover:text-foreground" title="Close" @click="libraryOpen = false">
            <X class="h-3.5 w-3.5" />
          </button>
        </div>

        <p v-if="libraryLoading" class="px-1 py-2 text-[11px] text-muted-foreground">Looking…</p>
        <p v-else-if="!libraryVideos.length" class="px-1 py-2 text-[11px] text-muted-foreground">
          {{ libraryQuery.trim() ? 'No video files here match that.' : 'No video files have been posted in this chat yet.' }}
        </p>
        <div v-else class="max-h-44 space-y-px overflow-y-auto">
          <button
            v-for="f in libraryVideos"
            :key="f.id"
            class="flex w-full items-center gap-2 rounded px-1 py-1 text-left hover:bg-muted"
            :disabled="busy"
            @click="addFromChat(f)"
          >
            <Film class="h-3.5 w-3.5 flex-none text-muted-foreground" />
            <span class="min-w-0 flex-1">
              <span class="block truncate text-xs">{{ f.name }}</span>
              <span class="block truncate text-[10px] text-muted-foreground">
                <span v-if="f.uploaded_by">{{ f.uploaded_by }}</span>
                <span v-if="f.uploaded_by && f.size"> · </span>{{ fmtSize(f.size) }}
              </span>
            </span>
          </button>
        </div>
      </div>

      <!-- Upload progress. Big files go up in chunks, so this is a real fraction, not a spinner. -->
      <div v-if="uploading" class="mt-2">
        <p class="mb-1 truncate text-[11px] text-muted-foreground">Uploading {{ uploading.name }} — {{ Math.round(uploading.progress * 100) }}%</p>
        <div class="h-1 w-full overflow-hidden rounded-full bg-muted">
          <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${uploading.progress * 100}%` }" />
        </div>
      </div>

      <p v-if="feedback" class="mt-2 flex items-start gap-1 text-[11px] text-amber-600 dark:text-amber-400">
        <TriangleAlert class="mt-px h-3 w-3 flex-none" /> <span>{{ feedback }}</span>
      </p>

      <!-- Search picker -->
      <div v-if="pending" class="mt-3 rounded-lg border bg-background/60 p-2">
        <div class="mb-1.5 flex items-center gap-1.5 text-xs font-medium">
          <Search class="h-3.5 w-3.5 text-muted-foreground" />
          Results for “{{ pending.query }}”
          <button class="ml-auto text-muted-foreground hover:text-foreground" title="Cancel" @click="cancelSearch"><X class="h-3.5 w-3.5" /></button>
        </div>
        <button
          v-for="(r, i) in pending.results"
          :key="r.key ?? i"
          class="flex w-full items-center gap-2 rounded px-1 py-1 text-left hover:bg-muted"
          @click="pick(i)"
        >
          <img v-if="r.thumbnail" :src="r.thumbnail" alt="" class="h-8 w-14 flex-none rounded object-cover">
          <span class="min-w-0 flex-1">
            <span class="block truncate text-xs">{{ r.title }}</span>
            <span class="block truncate text-[10px] text-muted-foreground">{{ r.author }}</span>
          </span>
          <span class="flex-none text-[10px] tabular-nums text-muted-foreground">{{ fmt(r.duration) }}</span>
        </button>
      </div>

      <!-- Up next -->
      <div v-if="upcoming.length" class="mt-3">
        <div class="mb-1 flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
          Up next ({{ upcoming.length }})
          <button class="ml-auto hover:text-foreground" title="Empty the playlist — deletes uploaded clips" @click="clearAll">Clear</button>
        </div>
        <div class="max-h-44 space-y-px overflow-y-auto">
          <div
            v-for="(s, i) in upcoming"
            :key="s.id"
            class="group flex items-center gap-1.5 rounded px-1 py-1 hover:bg-muted"
          >
            <button
              class="min-w-0 flex-1 text-left"
              :class="s.missing && 'opacity-50'"
              :title="s.missing ? 'This file was deleted from the chat' : `Play “${s.title}” now`"
              :disabled="s.missing"
              @click="jump(upcomingIndex(i))"
            >
              <span class="block truncate text-xs">{{ s.title }}</span>
              <span class="block truncate text-[10px] text-muted-foreground">
                <span v-if="s.missing" class="text-amber-600 dark:text-amber-400">Deleted from chat</span>
                <span v-else>{{ PROVIDER_LABEL[s.provider] ?? s.provider }}</span> · {{ s.addedBy }}
              </span>
            </button>
            <span class="flex-none text-[10px] tabular-nums text-muted-foreground">{{ fmt(s.duration) }}</span>
            <span class="flex flex-none items-center opacity-0 transition group-hover:opacity-100">
              <button class="rounded p-0.5 hover:text-foreground" title="Move up" :disabled="i === 0" @click="moveSource(i + 1, -1)"><ArrowUp class="h-3 w-3" /></button>
              <button class="rounded p-0.5 hover:text-foreground" title="Move down" :disabled="i === upcoming.length - 1" @click="moveSource(i + 1, 1)"><ArrowDown class="h-3 w-3" /></button>
              <button class="rounded p-0.5 hover:text-destructive" title="Remove" @click="removeSource(s)"><Trash2 class="h-3 w-3" /></button>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
