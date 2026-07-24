<script setup lang="ts">
import {
  AudioLines,
  Headphones,
  HeadphoneOff,
  Loader2,
  Map as MapIcon,
  MessageSquare,
  Mic,
  MicOff,
  Pencil,
  PhoneOff,
  ScreenShare,
  ScreenShareOff,
  Users,
  Video,
  VideoOff,
} from 'lucide-vue-next'
import type { Channel, VoiceParticipant } from '~/types'
import type { Camera, MapTheme, Occupant } from '~/lib/spaceMapEngine'
import {
  FAR_TILES,
  TILE,
  audibility,
  drawEarshot,
  drawMap,
  drawTrainer,
  inConnectRange,
  spriteHue,
  toScreen,
  zoneAt,
} from '~/lib/spaceMapEngine'
import { Button } from '~/components/ui/button'

/**
 * A Side Space: the room, drawn.
 *
 * ## What this is, structurally
 *
 * It sits in {@link ChannelView}'s `#call` slot — the very same slot a voice channel's call
 * stage uses. That is the whole reason this feature is as small as it is: the timeline,
 * composer, threads, side chats, Info and the Side Desk are all *below* this line and none of
 * them know a map exists. A Side Space is a text channel with a room on top, exactly as a
 * voice channel is a text channel with a call on top.
 *
 * ## The frame loop
 *
 * One `requestAnimationFrame` loop does three things in order, and the order matters:
 *
 *   1. **Move** — advance your own avatar and ease everyone else towards their last whispered
 *      position ({@link useSpacePresence}).
 *   2. **Listen** — for every occupant, work out how loudly you should hear them
 *      ({@link audibility}) and whether they should be connected at all
 *      ({@link inConnectRange}), and tell {@link useVoice}. This is the step that makes the
 *      room audible; it is also the step that keeps the WebRTC mesh small, because somebody
 *      across the map is never dialled in the first place.
 *   3. **Draw** — the map, the earshot ring, then everybody.
 *
 * Steps 1 and 2 are why this is a loop rather than a watcher: both are continuous functions of
 * position, and position changes every frame.
 */
const props = defineProps<{ channel: Channel, canEdit: boolean }>()

/**
 * Whether the conversation is folded away, leaving the room the whole window.
 *
 * Owned by the page (it's the page that has to tell {@link ChannelView} to collapse) and
 * toggled from here, because the button belongs on the room's own header. Defaults to hidden:
 * a Side Space is somewhere you go to be, not to read.
 */
const chatHidden = defineModel<boolean>('chatHidden', { default: true })

const { user } = useAuth()
const {
  status,
  error,
  peers,
  selfMuted,
  selfDeafened,
  selfSpeaking,
  micOpen,
  isSharing,
  isCameraOn,
  inCall,
  channelId: activeCallChannel,
  connect,
  disconnect,
  toggleMute,
  toggleDeafen,
  toggleScreenShare,
  toggleAudioShare,
  toggleCamera,
  isAudioSharing,
  setProximityMode,
  setPeerProximity,
  setPeerInRange,
  knownMembers,
  fireEffect,
} = useVoice()

const { map, loading, error: mapError, load: loadMap, subscribe: subscribeMap, unsubscribe: unsubscribeMap } = useSpaceMap(props.channel.id)
const {
  me,
  others,
  moving,
  attached,
  place,
  seed,
  tick,
  isWalking,
  subscribe: subscribeMoves,
  unsubscribe: unsubscribeMoves,
  bindKeys,
  unbindKeys,
} = useSpacePresence(props.channel.id, map)

const canvas = ref<HTMLCanvasElement | null>(null)
const wrap = ref<HTMLElement | null>(null)
const joining = ref(false)
const editing = ref(false)

/**
 * How tall the room is when the conversation is showing — dragged by the band's bottom edge
 * and remembered, so how you like to split a room against its chat survives a reload.
 *
 * Ignored when the chat is hidden, where the room simply takes everything that's left.
 */
const { width: stageHeight, startResize } = useResizable('space-stage', 420, {
  min: 220,
  max: 900,
  edge: 'bottom',
})

/** Only a server's owner may move somebody else's microphone or remove them from the room. */
const { server } = useServer()
const canModerate = computed(() =>
  server.value?.id === props.channel.server_id && !!server.value?.is_owner)
/** Bumped whenever the audible set changes, so the roster below re-renders without watching positions. */
const audibleIds = ref<number[]>([])

/** Are we in *this* room's call, as opposed to some other channel's? */
const inThisRoom = computed(() => inCall.value && activeCallChannel.value === props.channel.id)

/** The zone you're standing in, named in a banner so the audio rules are never a surprise. */
const currentZone = computed(() => (map.value && me.value ? zoneAt(map.value, me.value.x, me.value.y) : null))

const occupantCount = computed(() => Object.keys(others.value).length + (me.value ? 1 : 0))

let frame: number | undefined
let lastAt = 0
let ro: ResizeObserver | undefined
let cssW = 0
let cssH = 0
/** Who was in earshot last frame, so arrivals and departures can be noticed rather than polled. */
const wasAudible = new Set<number>()

const camera = reactive<Camera>({ x: 0, y: 0, zoom: 1, width: 0, height: 0 })

// --- joining ---

/**
 * Walk in.
 *
 * Explicit rather than automatic, and muted rather than live. Navigating to a channel should
 * never make a browser ask for the microphone on your behalf, and walking into a room full of
 * people with your mic already open is how you say something you didn't mean to broadcast.
 *
 * Proximity mode goes on *before* connecting, because it changes what arriving on the presence
 * channel means: with it on, `here` records the roster without dialling anybody, and the frame
 * loop dials your neighbours instead.
 */
async function enter() {
  if (joining.value || inThisRoom.value) return
  joining.value = true

  try {
    setProximityMode(true)

    const joined = await connect(props.channel.id)

    // Mic refused, or the room was full. `connect` has already put the reason in `error`;
    // what matters here is not going on to act as though we're standing in the room.
    if (!joined) {
      setProximityMode(false)

      return
    }

    // Muted *after* joining, not before: this way the mute is published to the roster and
    // whispered to the room, where doing it first would have been a local-only flag on a call
    // that didn't exist yet — leaving everyone else's sidebar showing an open mic. Nothing
    // leaks in the gap, because in a Side Space nobody is dialled until a frame has worked out
    // who is near enough.
    if (!selfMuted.value) toggleMute()

    subscribeMoves()
    bindKeys()

    // Everyone's last known position, so the room is populated the instant you arrive rather
    // than filling in as people happen to take a step.
    const roster = (joined?.data ?? []) as VoiceParticipant[]
    seed(roster.map(p => ({
      id: p.user.id,
      name: p.user.name,
      avatar: p.user.avatar,
      x: p.x ?? null,
      y: p.y ?? null,
      facing: p.facing ?? null,
    })))
    // …and put yourself back where you were standing, if the room still allows it.
    const mine = roster.find(p => p.user.id === user.value?.id)
    place(mine && mine.x !== null && mine.y !== null ? { x: mine.x, y: mine.y, facing: mine.facing ?? null } : null)
  }
  catch {
    setProximityMode(false)
  }
  finally {
    joining.value = false
  }
}

/**
 * Pick the room back up after the stage has been away.
 *
 * Clicking into another channel unmounts this component while the call — and therefore your
 * place in the room — carries on without it. Coming back used to land here with no avatar and
 * dead arrow keys, because setting those up only ever happened inside {@link enter}, and
 * `enter` correctly declines to run when you're already in the call.
 *
 * So: mounting into a room you are already standing in re-attaches instead of re-entering.
 * `subscribeMoves` is a no-op when the subscription survived; `bindKeys` is the part that
 * genuinely has to happen again, since keys are unbound whenever the room leaves the screen.
 */
function reattach() {
  // `enter` is mid-flight and owns all of this, including putting you back on your remembered
  // tile. Stepping in here would place you at the entrance first and, because placing is
  // once-only, make that stick — losing the position `enter` was about to restore.
  if (!inThisRoom.value || joining.value) return

  subscribeMoves()
  bindKeys()

  // If the position state somehow didn't survive (a hard reload lands here with the call
  // restored from the server but nothing placed), fall back to the entrance.
  if (!me.value) place(null)
}

async function leave() {
  unsubscribeMoves()
  wasAudible.clear()
  audibleIds.value = []
  await disconnect()
}

// --- the frame loop ---

function loop(now: number) {
  frame = requestAnimationFrame(loop)

  // Clamped: a backgrounded tab resumes with a huge gap, and without this everybody would
  // teleport the width of however long you were away.
  const dt = Math.min(0.1, lastAt ? (now - lastAt) / 1000 : 0)
  lastAt = now

  if (inThisRoom.value) {
    tick(dt)
    applyProximity()
  }

  draw()
}

/**
 * Tell the call how near everybody is — the step that makes the room audible.
 *
 * Runs over `knownMembers()` (everyone on the presence channel) rather than `peers` (everyone
 * we have a connection to), and that distinction is the feature: somebody we have *no*
 * connection to is precisely who we might need to dial, and asking `peers` would mean never
 * noticing them walk up.
 */
function applyProximity() {
  const m = map.value
  const self = me.value
  if (!m || !self) return

  const audible: number[] = []

  for (const member of knownMembers()) {
    const them = others.value[member.id]
    // On the channel but never yet heard from — no position, so nothing to measure. They'll
    // be picked up on their first whisper, which is at most a twelfth of a second away.
    if (!them) continue

    const gain = audibility(m, self, them)
    setPeerProximity(member.id, gain)
    setPeerInRange(member.id, inConnectRange(m, self, them))

    if (gain > 0) {
      audible.push(member.id)

      // An arrival worth a fanfare is one you can actually hear — not one somewhere in the
      // building. This is why useVoice suppresses its own join effects in proximity mode.
      if (!wasAudible.has(member.id)) fireEffect('join', member.id, them.name)
    }
    else if (wasAudible.has(member.id)) {
      fireEffect('leave', member.id, them.name)
    }
  }

  wasAudible.clear()
  for (const id of audible) wasAudible.add(id)

  // Only when the set actually changes — this runs sixty times a second.
  if (audible.length !== audibleIds.value.length || audible.some(id => !audibleIds.value.includes(id))) {
    audibleIds.value = audible
  }
}

// --- drawing ---

/**
 * The room's palette, taken from the app's own theme so it follows the accent and the
 * light/dark switch.
 *
 * Reading the custom properties directly does *not* work: they're declared as
 * `oklch(0.955 calc(0.016 * var(--cs)) var(--h))`, and `getPropertyValue` hands back that
 * string with the `var()`s unresolved. Canvas can't parse it, silently ignores the assignment,
 * and paints everything in whatever colour happened to be set last.
 *
 * So we resolve them the only way the platform offers: park the value on a real element's
 * `color` and read the *computed* style back, which the browser has by then flattened to an
 * actual colour. Cached, because that's a layout read and this runs every frame — and
 * re-resolved once a second so flipping the theme catches up without anything watching it.
 */
let palette: MapTheme | null = null
let paletteAt = 0
let probe: HTMLElement | null = null

function theme(): MapTheme {
  const now = performance.now()
  if (palette && now - paletteAt < 1000) return palette

  paletteAt = now

  if (!probe) {
    probe = document.createElement('span')
    probe.style.display = 'none'
    document.body.appendChild(probe)
  }

  const resolve = (expr: string, fallback: string) => {
    probe!.style.color = ''
    probe!.style.color = expr
    const resolved = getComputedStyle(probe!).color

    return resolved || fallback
  }

  palette = {
    floor: resolve('var(--muted)', '#f1f5f9'),
    floorAlt: resolve('var(--background)', '#ffffff'),
    wall: resolve('var(--border)', '#cbd5e1'),
    wallTop: resolve('var(--accent)', '#e2e8f0'),
    zone: 'rgb(99 102 241 / 0.08)',
    zoneBorder: 'rgb(99 102 241 / 0.45)',
    text: resolve('var(--foreground)', '#0f172a'),
    muted: resolve('var(--muted-foreground)', '#64748b'),
  }

  return palette
}

function resize() {
  const el = canvas.value
  const box = wrap.value
  if (!el || !box) return

  const dpr = window.devicePixelRatio || 1
  const w = box.clientWidth
  const h = box.clientHeight
  if (w === cssW && h === cssH) return

  cssW = w
  cssH = h
  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  el.style.width = `${w}px`
  el.style.height = `${h}px`
  el.getContext('2d')?.setTransform(dpr, 0, 0, dpr, 0, 0)

  camera.width = w
  camera.height = h
}

function draw() {
  const ctx = canvas.value?.getContext('2d')
  const m = map.value
  if (!ctx || !m) return

  const palette = theme()

  // Follow your avatar; before you've walked in, sit on the entrance so there's a room to look
  // at rather than an empty rectangle.
  const lookAt = me.value ?? { x: m.spawn.x, y: m.spawn.y }
  camera.x += (lookAt.x - camera.x) * 0.2
  camera.y += (lookAt.y - camera.y) * 0.2
  // Zoom out a little on a short stage so a squat panel still shows a useful slice of room.
  camera.zoom = Math.max(0.6, Math.min(1.4, camera.height / (TILE * 16)))

  ctx.clearRect(0, 0, camera.width, camera.height)
  drawMap(ctx, m, camera, palette)

  // How far your own voice carries. Only your own — six overlapping rings would be a fog.
  if (me.value) drawEarshot(ctx, camera, me.value, 'rgb(99 102 241 / 0.13)', 'rgb(99 102 241 / 0)')

  // Painter's algorithm: whoever is further down the map is nearer the viewer, so they're
  // drawn last and overlap correctly. Without it two people on adjacent tiles overlap
  // according to object key order, which changes as people come and go.
  const cast = [
    ...Object.values(others.value).map(o => ({ who: o, self: false, walking: isWalking(o) })),
    ...(me.value ? [{ who: me.value, self: true, walking: moving.value }] : []),
  ].sort((a, b) => a.who.y - b.who.y)

  for (const member of cast) drawPerson(ctx, member.who, member.self, member.walking, palette)
}

/** Which walk frame the whole room is on. One clock, so everybody's stride is in step. */
function walkPhase() {
  return Math.floor(performance.now() / 160)
}

/**
 * One person: their sprite, their name under it, and a ring when they're talking.
 *
 * Somebody you can't hear is drawn faded rather than hidden — knowing there are people over
 * there, and that walking over would let you talk to them, is most of what makes the room feel
 * like a place instead of a call with extra steps.
 */
function drawPerson(
  ctx: CanvasRenderingContext2D,
  o: Occupant,
  self: boolean,
  walking: boolean,
  palette: MapTheme,
) {
  const size = TILE * camera.zoom
  const p = toScreen(camera, o.x, o.y)

  const peer = peers.value.find(x => x.id === o.id)
  const speaking = self ? selfSpeaking.value && micOpen.value : !!peer?.speaking
  const gain = self ? 1 : (map.value && me.value ? audibility(map.value, me.value, o) : 0)

  ctx.save()
  // Fade with earshot, floored so a distant figure is still visibly a person.
  ctx.globalAlpha = self ? 1 : 0.4 + gain * 0.6

  // A shadow, because a sprite anchored at its feet needs something to stand on or it reads
  // as pasted over the floor rather than standing on it.
  ctx.beginPath()
  ctx.ellipse(p.x, p.y + size * 0.34, size * 0.3, size * 0.12, 0, 0, Math.PI * 2)
  ctx.fillStyle = 'rgb(0 0 0 / 0.18)'
  ctx.fill()

  // Talking: a ring on the ground rather than around the head, so it never hides the sprite.
  if (speaking) {
    ctx.beginPath()
    ctx.ellipse(p.x, p.y + size * 0.34, size * 0.42, size * 0.18, 0, 0, Math.PI * 2)
    ctx.strokeStyle = 'rgb(34 197 94)'
    ctx.lineWidth = 2.5
    ctx.stroke()
  }

  drawTrainer(ctx, camera, o, {
    hue: spriteHue(o.id),
    self,
    walking,
    phase: walkPhase(),
  })

  // A muted mic is worth showing on the map: it's the difference between "they're ignoring me"
  // and "they can't answer".
  const quiet = self ? !micOpen.value : !!peer?.muted
  if (quiet) {
    const bx = p.x + size * 0.42
    const by = p.y - size * 0.55
    ctx.beginPath()
    ctx.arc(bx, by, size * 0.2, 0, Math.PI * 2)
    ctx.fillStyle = 'rgb(239 68 68)'
    ctx.fill()
    // A slash, so it reads as "off" at sizes where a tiny microphone glyph would be a smudge.
    ctx.beginPath()
    ctx.moveTo(bx - size * 0.1, by - size * 0.1)
    ctx.lineTo(bx + size * 0.1, by + size * 0.1)
    ctx.strokeStyle = '#ffffff'
    ctx.lineWidth = Math.max(1.5, size * 0.05)
    ctx.stroke()
  }

  // The name goes *under* the feet, on a plate — over a patterned floor, plain text at this
  // size is unreadable about half the time.
  const label = self ? 'You' : o.name
  ctx.font = `500 ${Math.max(9, size * 0.28)}px system-ui, sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'top'

  const textWidth = ctx.measureText(label).width
  const padX = size * 0.12
  const labelY = p.y + size * 0.44

  ctx.fillStyle = 'rgb(0 0 0 / 0.45)'
  ctx.beginPath()
  ctx.roundRect(p.x - textWidth / 2 - padX, labelY - 2, textWidth + padX * 2, size * 0.36, 4)
  ctx.fill()

  ctx.fillStyle = '#ffffff'
  ctx.fillText(label, p.x, labelY, size * 5)

  ctx.restore()
}

// --- lifecycle ---

const audiblePeople = computed(() =>
  audibleIds.value.map(id => others.value[id]).filter((o): o is NonNullable<typeof o> => !!o))

async function onMapSaved() {
  editing.value = false
  await loadMap()
}

onMounted(async () => {
  await loadMap()
  subscribeMap()

  resize()
  ro = new ResizeObserver(resize)
  if (wrap.value) ro.observe(wrap.value)

  // Walked in earlier and only now come back to look at it. The map has to be loaded first —
  // `place` needs to know which tiles you're allowed to stand on.
  reattach()

  frame = requestAnimationFrame(loop)
})

onBeforeUnmount(() => {
  if (frame) cancelAnimationFrame(frame)
  ro?.disconnect()
  probe?.remove()
  probe = null
  palette = null
  unsubscribeMap()

  /*
   * Note what is *not* torn down: the call, and your place in the room.
   *
   * Wandering off to read another channel shouldn't throw you out of a room any more than it
   * hangs up a voice call — so the whisper subscription and the idle heartbeat stay running
   * (they live at module scope for exactly this reason), and everybody else keeps seeing you
   * standing where you left off. Only the keys are given back, because arrow keys belong to
   * whatever is actually on screen. Leaving for real is the button in the header.
   */
  unbindKeys()
})

// Walking out of the room from anywhere — the app-wide call bar, a moderator disconnecting
// you, another tab taking the seat — has to stop us pretending we're still standing in it.
// Without this the avatar would linger on a room we're no longer in.
watch(inThisRoom, (now) => {
  if (now) {
    reattach()

    return
  }

  wasAudible.clear()
  audibleIds.value = []
  if (attached.value) unsubscribeMoves()
})
</script>

<template>
  <!--
    A band above the timeline, whose height you drag — or, with the chat hidden, the whole of what's
    left of the window with the conversation folded away entirely. Hence `shrink-0` + an
    explicit height in one case and `min-h-0 flex-1` in the other.
  -->
  <div
    class="relative flex flex-col border-b bg-muted/20"
    :class="chatHidden ? 'min-h-0 flex-1' : 'shrink-0'"
    :style="chatHidden ? undefined : { height: `${stageHeight}px` }"
  >
    <!-- Header: what room this is, who's in it, and the way in or out. -->
    <div class="flex h-11 shrink-0 items-center justify-between gap-2 px-3">
      <div class="flex min-w-0 items-center gap-2 text-sm">
        <MapIcon class="h-4 w-4 shrink-0 text-muted-foreground" />
        <span class="truncate font-medium">{{ map?.name ?? channel.name }}</span>
        <AlphaBadge hint="Proximity audio and the room editor are still settling — expect rough edges." />
        <span class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
          <Users class="h-3.5 w-3.5" /> {{ occupantCount }}
        </span>
        <span
          v-if="currentZone"
          class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
          :title="`Everyone in ${currentZone.name} hears each other, and nobody outside it can`"
        >{{ currentZone.name }}</span>
      </div>

      <div class="flex shrink-0 items-center gap-1">
        <template v-if="inThisRoom">
          <button
            type="button"
            class="rounded p-1.5 transition-colors hover:bg-muted"
            :class="micOpen ? 'text-foreground' : 'text-destructive'"
            :title="selfMuted ? 'Unmute' : 'Mute'"
            @click="toggleMute"
          >
            <component :is="micOpen ? Mic : MicOff" class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="rounded p-1.5 transition-colors hover:bg-muted"
            :class="selfDeafened ? 'text-destructive' : 'text-foreground'"
            :title="selfDeafened ? 'Undeafen' : 'Deafen'"
            @click="toggleDeafen"
          >
            <component :is="selfDeafened ? HeadphoneOff : Headphones" class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            :title="isCameraOn ? 'Turn camera off' : 'Turn camera on'"
            @click="toggleCamera"
          >
            <component :is="isCameraOn ? Video : VideoOff" class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            :title="isSharing ? 'Stop sharing' : 'Share your screen'"
            @click="toggleScreenShare"
          >
            <component :is="isSharing ? ScreenShareOff : ScreenShare" class="h-4 w-4" />
          </button>
          <!-- Sound with no picture: a track, or a video everyone's listening to rather than
               watching. Rides the same slot a screen's audio does. -->
          <button
            type="button"
            class="rounded p-1.5 transition-colors hover:bg-muted"
            :class="isAudioSharing ? 'text-primary' : 'text-muted-foreground hover:text-foreground'"
            :title="isAudioSharing ? 'Stop sharing sound' : 'Share sound from a tab'"
            @click="toggleAudioShare"
          >
            <AudioLines class="h-4 w-4" />
          </button>
          <Button variant="ghost" size="sm" class="gap-1.5 text-destructive" @click="leave">
            <PhoneOff class="h-4 w-4" /> Leave
          </Button>
        </template>

        <Button v-else size="sm" class="gap-1.5" :disabled="joining || loading" @click="enter">
          <Loader2 v-if="joining" class="h-4 w-4 animate-spin" />
          <MapIcon v-else class="h-4 w-4" />
          {{ joining ? 'Entering…' : 'Enter the space' }}
        </Button>

        <!-- Show or hide the conversation. Hidden by default, and remembered. It stays mounted
             either way and keeps its scroll, draft and subscription — see ChannelView's
             `collapseTimeline` — so this is free to flip as often as you like. -->
        <button
          type="button"
          class="flex items-center gap-1.5 rounded px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          :title="chatHidden ? 'Show the channel chat below the room' : 'Hide the chat and give the room the whole window'"
          @click="chatHidden = !chatHidden"
        >
          <MessageSquare class="h-3.5 w-3.5" />
          {{ chatHidden ? 'Show chat' : 'Hide chat' }}
        </button>

        <button
          v-if="canEdit && !editing"
          type="button"
          class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          title="Edit this room"
          @click="editing = true"
        >
          <Pencil class="h-4 w-4" />
        </button>
      </div>
    </div>

    <div class="flex min-h-0 flex-1">
      <!-- The room. -->
      <div ref="wrap" class="relative min-w-0 flex-1 overflow-hidden">
        <canvas ref="canvas" class="block h-full w-full" />

        <p v-if="loading" class="absolute inset-0 grid place-items-center text-sm text-muted-foreground">
          Loading the room…
        </p>
        <p v-else-if="mapError" class="absolute inset-0 grid place-items-center text-sm text-destructive">
          {{ mapError }}
        </p>

        <!-- Before you walk in: what this place is, and how it works. -->
        <div
          v-else-if="!inThisRoom"
          class="absolute inset-0 grid place-items-center bg-background/70 p-4 text-center backdrop-blur-[1px]"
        >
          <div class="max-w-xs space-y-1">
            <p class="text-sm font-medium">Walk around and talk to whoever's near you</p>
            <p class="text-xs text-muted-foreground">
              Move with the arrow keys or WASD. You'll hear people within about {{ FAR_TILES }} tiles,
              louder the closer you get — and everyone inside a room hears each other, and nobody outside it.
            </p>
          </div>
        </div>

        <p v-if="error" class="absolute inset-x-0 bottom-0 bg-destructive/90 px-3 py-1 text-xs text-destructive-foreground">
          {{ error }}
        </p>

        <!--
          You walk in muted, so that navigating to a channel can never open a hot mic. The
          trade is that being muted has to be impossible to miss — a silent room where you
          assumed you were audible is the single worst way this feature can fail.
        -->
        <button
          v-if="inThisRoom && !micOpen"
          type="button"
          class="absolute left-1/2 top-3 flex -translate-x-1/2 items-center gap-2 rounded-full bg-destructive px-3 py-1.5 text-xs font-medium text-destructive-foreground shadow-lg transition hover:opacity-90"
          @click="toggleMute"
        >
          <MicOff class="h-3.5 w-3.5" /> You're muted — click to talk
        </button>

        <!-- Who can hear you right now. The rule is invisible otherwise. -->
        <div
          v-if="inThisRoom"
          class="pointer-events-none absolute bottom-2 left-2 max-w-[60%] rounded-md bg-background/85 px-2 py-1 text-[11px] text-muted-foreground shadow-sm"
        >
          <template v-if="audiblePeople.length">
            In earshot: <span class="font-medium text-foreground">{{ audiblePeople.map(p => p.name).join(', ') }}</span>
          </template>
          <template v-else>
            Nobody's in earshot — walk over to somebody to talk.
          </template>
        </div>
      </div>

      <!-- Cameras, screens and the volume of everyone near you. -->
      <SideSpaceCallDock v-if="inThisRoom" class="w-56 shrink-0" :can-moderate="canModerate" />
    </div>

    <!-- Drag the room's bottom edge to trade height with the conversation. Pointless when the
         chat is hidden, since there is nothing to trade against. -->
    <ResizeHandle v-if="!chatHidden" edge="bottom" @resize="startResize" />

    <SideSpaceMapEditor
      v-if="editing && map"
      :channel-id="channel.id"
      :map="map"
      @close="editing = false"
      @saved="onMapSaved"
    />
  </div>
</template>
