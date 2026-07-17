<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core'
import { ArrowDown, ArrowUp, FastForward, ListMusic, Pause, Play, Radio, Repeat, Repeat1, Rewind, Search, Shuffle, SkipBack, SkipForward, Square, Volume2, VolumeX, X } from 'lucide-vue-next'
import type { MusicState, MusicTrack, Widget } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The listen-along music player card — a bot-style player in the spirit of Jockie.
 *
 * The server owns the transport (queue, current track, playing/paused, speed, and the
 * position as of `updated_at`). This component's job is to make one hidden YouTube player
 * obey that shared truth: load the right video and keep it seeked to where the room is now
 * (`position` + elapsed × `speed`). Nobody streams audio to anyone; each viewer plays their
 * own copy in lockstep. Volume is the one thing that's local — everyone sets their own.
 *
 * Playback only ever starts from a real click ("Listen along"), which sidesteps browser
 * autoplay blocking entirely; the transport buttons drive the shared state for everyone
 * whether or not this viewer has opted in to hearing it.
 */
const props = defineProps<{ widget: Widget }>()

const { $youtube } = useNuxtApp() as any
const { action } = useWidgets()

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

// Has this viewer opted in to actually hearing the room? Playback never begins without it,
// so we never trip autoplay policy.
const joined = ref(false)
// Per-listener, remembered across sessions.
const volume = useLocalStorage('music:volume', 100)
const muted = ref(false)

// A hidden YT player. `any` throughout — the IFrame API ships no types.
const mountEl = ref<HTMLElement | null>(null)
let player: any = null
let ready = false
let loadedVideoId: string | null = null
let ticker: ReturnType<typeof setInterval> | null = null
const reportedDuration = new Set<string>() // track ids we've already backfilled

// Where the current track should be *right now*, extrapolating from the server snapshot.
function targetPosition(): number {
  const s = state.value
  const base = s.position ?? 0
  if (s.status !== 'playing') return base
  const elapsed = (Date.now() - Date.parse(s.updated_at)) / 1000
  return Math.max(0, base + elapsed * (s.speed ?? 1))
}

const displayTime = ref(0)
const duration = ref(0)

function tick() {
  if (joined.value && ready && player?.getCurrentTime) {
    displayTime.value = player.getCurrentTime() || 0
    duration.value = player.getDuration() || current.value?.duration || 0
    maybeReportDuration()
  } else {
    displayTime.value = targetPosition()
    duration.value = current.value?.duration || 0
  }
}

// Teach the server a track's real length the first time we learn it (keyless tracks arrive
// without one). The server no-ops if it already knows, but we also guard locally.
function maybeReportDuration() {
  const t = current.value
  if (!t || t.duration || reportedDuration.has(t.id)) return
  const d = Math.round(player?.getDuration?.() || 0)
  if (d > 0) {
    reportedDuration.add(t.id)
    action(props.widget.id, 'meta', { id: t.id, duration: d })
  }
}

async function ensurePlayer() {
  if (player || !mountEl.value) return
  const YT = await $youtube.ready()
  if (!mountEl.value) return
  player = new YT.Player(mountEl.value, {
    height: '0',
    width: '0',
    playerVars: { autoplay: 0, controls: 0, disablekb: 1, playsinline: 1 },
    events: {
      onReady: () => {
        ready = true
        applyVolume()
        sync()
      },
      onStateChange: (e: any) => {
        if (e.data === YT.PlayerState.ENDED && current.value) {
          action(props.widget.id, 'ended', { id: current.value.id })
        }
      },
    },
  })
}

// Reconcile the real player with the shared state. Idempotent — safe to call on every
// change and on a timer.
function sync() {
  if (!ready || !player) return

  if (!current.value || !current.value.videoId) {
    // Nothing playing, or the current track is a shell still being resolved server-side.
    if (!current.value) { player.stopVideo?.(); loadedVideoId = null }
    return
  }

  if (loadedVideoId !== current.value.videoId) {
    loadedVideoId = current.value.videoId
    const start = targetPosition()
    if (joined.value && isPlaying.value) {
      player.loadVideoById({ videoId: current.value.videoId, startSeconds: start })
    } else {
      player.cueVideoById({ videoId: current.value.videoId, startSeconds: start })
    }
    player.setPlaybackRate?.(speed.value)
    return
  }

  if (player.getPlaybackRate?.() !== speed.value) player.setPlaybackRate?.(speed.value)

  if (!joined.value) return // watching only — don't touch playback

  const drift = Math.abs((player.getCurrentTime?.() ?? 0) - targetPosition())
  if (isPlaying.value) {
    if (drift > 1.5) player.seekTo(targetPosition(), true)
    const st = player.getPlayerState?.()
    if (st !== 1 /* PLAYING */ && st !== 3 /* BUFFERING */) player.playVideo()
  } else {
    if (drift > 1) player.seekTo(targetPosition(), true)
    player.pauseVideo()
  }
}

function applyVolume() {
  if (!ready) return
  player?.setVolume(muted.value ? 0 : volume.value)
}

function joinListening() {
  joined.value = true
  loadedVideoId = null // force a fresh load so playback starts within this user gesture
  sync()
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

function toggleMute() {
  muted.value = !muted.value
  applyVolume()
}
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

// One key that changes whenever anything the player must react to changes.
const syncKey = computed(() =>
  `${current.value?.videoId ?? ''}|${state.value.status}|${state.value.position}|${state.value.updated_at}|${state.value.speed}|${joined.value}`,
)
watch(syncKey, () => sync())
watch([volume, muted], applyVolume)

onMounted(() => {
  ensurePlayer()
  ticker = setInterval(tick, 500)
})
onBeforeUnmount(() => {
  if (ticker) clearInterval(ticker)
  try { player?.destroy?.() } catch { /* already gone */ }
  player = null
})
</script>

<template>
  <div class="mt-1.5 w-full max-w-md overflow-hidden rounded-xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-sm">
    <div class="flex items-center gap-1.5 border-b bg-background/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <ListMusic class="h-3.5 w-3.5" /> Music
      <span v-if="state.autoplay" class="ml-auto inline-flex items-center gap-1 rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case text-primary" title="Autoplay on">
        <Radio class="h-3 w-3" /> radio
      </span>
    </div>

    <div class="p-3">
      <!-- Search picker -->
      <div v-if="pending" class="mb-3 rounded-lg border bg-background/60 p-2">
        <div class="mb-1.5 flex items-center gap-1.5 text-xs font-medium">
          <Search class="h-3.5 w-3.5 text-muted-foreground" />
          Results for “{{ pending.query }}”
          <button class="ml-auto text-muted-foreground hover:text-foreground" title="Cancel" @click="cancelSearch"><X class="h-3.5 w-3.5" /></button>
        </div>
        <ul class="space-y-0.5">
          <li v-for="(r, i) in pending.results" :key="r.videoId">
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
        Nothing playing. Try <code class="rounded bg-muted px-1">m!p &lt;link or search&gt;</code>.
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

          <select
            class="h-7 rounded border bg-background px-1 text-[11px] tabular-nums"
            :value="speed"
            title="Playback speed"
            @change="onSpeed"
          >
            <option v-for="opt in SPEED_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>

          <div class="ml-auto flex items-center gap-1.5">
            <button :title="muted ? 'Unmute' : 'Mute'" @click="toggleMute">
              <component :is="muted || volume === 0 ? VolumeX : Volume2" class="h-4 w-4" />
            </button>
            <input
              type="range" min="0" max="100" :value="muted ? 0 : volume"
              class="h-1 w-16 cursor-pointer accent-primary"
              :disabled="!joined"
              @input="onVolume"
            >
          </div>
        </div>
      </template>

      <!-- Listen-along opt-in: playback only starts from this click, so autoplay never blocks. -->
      <Button v-if="current && !joined" size="sm" class="mt-2.5 w-full gap-1.5" @click="joinListening">
        <Play class="h-3.5 w-3.5" /> Listen along
      </Button>

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

    <!-- The hidden player element. -->
    <div class="pointer-events-none absolute h-0 w-0 overflow-hidden opacity-0"><div ref="mountEl" /></div>
  </div>
</template>
