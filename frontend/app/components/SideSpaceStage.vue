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
  Gamepad2,
  Shirt,
  Sofa,
  Users,
  Video,
  VideoOff,
} from 'lucide-vue-next'
import type { AmongUsState, Channel, VoiceParticipant, Widget } from '~/types'
import type { Camera, MapTheme, Occupant } from '~/lib/spaceMapEngine'
import type { SpaceObject } from '~/lib/spaceDecor'
import {
  FAR_TILES,
  TILE,
  audibility,
  drawEarshot,
  drawMap,
  drawPet,
  drawTrainer,
  spriteHue,
  toScreen,
  zoneAt,
} from '~/lib/spaceMapEngine'
import { decorInFront, decorKind } from '~/lib/spaceDecor'
import { normaliseLook } from '~/lib/spaceAvatar'
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
 * One `requestAnimationFrame` loop does two things in order, and the order matters:
 *
 *   1. **Move** — advance your own avatar and ease everyone else towards their last whispered
 *      position ({@link useSpacePresence}).
 *   2. **Draw** — the map, the earshot ring, then everybody.
 *
 * That's why it's a loop rather than a watcher: movement is a continuous function of time, and
 * the picture changes every frame.
 *
 * What is conspicuously *not* here is proximity — who you can hear, and who is dialled. That
 * used to be step two of this loop, and it doesn't belong to a canvas: a hidden tab or a stage
 * you clicked away from stops drawing while the call carries on, which froze the room's audio
 * exactly where it stood. It lives in {@link useSpaceProximity} now, on its own clock at module
 * scope. Distance still fades the *sprites* here, per frame, because that really is only
 * interesting while somebody is looking.
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
  restyle,
  warp,
  seed,
  tick,
  isWalking,
  subscribe: subscribeMoves,
  unsubscribe: unsubscribeMoves,
  bindKeys,
  unbindKeys,
} = useSpacePresence(props.channel.id, map)

const {
  game,
  catalogue: gameCatalogue,
  load: loadGame,
  loadCatalogue: loadGameCatalogue,
  propose: proposeGame,
  vote: voteGame,
  act: actGame,
  cancel: cancelGame,
  subscribe: subscribeGame,
  unsubscribe: unsubscribeGame,
} = useSpaceGame(props.channel.id)

const { audibleIds, startProximity, stopProximity } = useSpaceProximity()

const canvas = ref<HTMLCanvasElement | null>(null)
const wrap = ref<HTMLElement | null>(null)
const joining = ref(false)
/**
 * The room editor, and in which of its two guises.
 *
 *   - `full` — the owner rebuilding the room: ground, furniture, the lot.
 *   - `decor` — anybody rearranging the furniture, which is open to every member.
 *
 * Null when the editor is closed. Kept as one piece of state rather than two booleans because
 * the two are exclusive — you are editing in one mode or not at all.
 */
const editing = ref<'full' | 'decor' | null>(null)
const dressing = ref(false)

/**
 * The piece of furniture you're standing at, if any, and whether we're mid-way through opening
 * what it points at.
 *
 * Recomputed in the frame loop rather than watched, because "what am I next to" is a continuous
 * function of position exactly as audibility is — and like audibility, it only writes to a ref
 * when the answer actually changes, so the prompt doesn't re-render sixty times a second.
 */
const facingObject = ref<SpaceObject | null>(null)
const using = ref(false)
const { open: openWindow } = useFloatingWindows()
const api = useApi()

// --- the game ---

/**
 * The room can become a game. When one is running, the same map, the same walking and the same
 * proximity voice are the game — you do tasks by walking to them, you accuse people by talking to
 * them, and a meeting is the moment that voice opens to the whole room. The rules are the
 * server's ({@link useSpaceGame}); the stage's job is to draw the game's things on the map, turn
 * standing-near-something into the right move, and hand a meeting its full-volume voice.
 */
const showPropose = ref(false)

/** How near you have to be to do a task, report a body, or make a kill. Tiles. */
const TASK_RANGE = 0.9
const BODY_RANGE = 1.2
const KILL_RANGE = 1.7

/**
 * The running game's state, narrowed to the game it actually is. Only one is ever non-null.
 * Among Us and a pet battle share the framework but nothing about their state, so the stage keeps
 * them apart here rather than reaching into a union everywhere.
 */
const amState = computed<AmongUsState | null>(() =>
  game.value?.type === 'amongus' ? (game.value.state as AmongUsState | null) : null)

const gameRunning = computed(() => game.value?.status === 'running' && !!game.value.state)
const gameMeeting = computed(() => gameRunning.value && amState.value?.phase === 'meeting')
/** The panel shows for a proposal, a running game, or an ending worth reading — never a bare cancel. */
const showGamePanel = computed(() => !!game.value && inThisRoom.value
  && (game.value.status !== 'ended' || !!(game.value.state as { winner?: unknown } | null)?.winner))
/** Offer the way in only when there's no game already on the table. */
const canProposeGame = computed(() => inThisRoom.value && (!game.value || game.value.status === 'ended'))

/** Everyone in the room by id → name, for the panels to resolve players and challengers. */
const roomNames = computed<Record<number, string>>(() => {
  const names: Record<number, string> = {}
  if (user.value) names[user.value.id] = user.value.name
  for (const [id, o] of Object.entries(others.value)) names[Number(id)] = o.name

  return names
})

/**
 * Everyone in the Among Us game, named for the meeting and the reveal. Names come from the room's
 * roster; a player who's since walked out keeps their id and a stand-in name rather than vanishing.
 */
const gamePlayers = computed(() => {
  const s = amState.value
  if (!s) return []
  const myId = user.value?.id

  return Object.entries(s.players).map(([idStr, p]) => {
    const id = Number(idStr)
    const name = id === myId ? (user.value?.name ?? 'You') : (others.value[id]?.name ?? `Player ${id}`)

    return { id, name, alive: p.alive, role: p.role, isMe: id === myId }
  })
})

// Affordances, recomputed each frame from positions (like the furniture prompt), so they track
// movement rather than only the rare moments the game state changes.
const gameNearTaskId = ref<string | null>(null)
const gameNearBody = ref(false)
const gameKillTarget = ref<number | null>(null)
const gameCooldownLeft = ref(0)

function dist(a: { x: number, y: number }, b: { x: number, y: number }) {
  return Math.hypot(a.x - b.x, a.y - b.y)
}

/** Work out what you could do right now — the game's version of {@link checkFurniture}. */
function checkGame() {
  const s = amState.value
  const self = me.value

  if (!s || !self || !gameRunning.value || s.phase !== 'play') {
    if (gameNearTaskId.value !== null) gameNearTaskId.value = null
    if (gameNearBody.value) gameNearBody.value = false
    if (gameKillTarget.value !== null) gameKillTarget.value = null
    if (gameCooldownLeft.value !== 0) gameCooldownLeft.value = 0

    return
  }

  const myId = user.value?.id
  const meAlive = myId != null && !!s.players[myId]?.alive

  // A task at your feet you haven't done.
  let task: string | null = null
  if (meAlive) {
    for (const t of s.my_tasks) {
      if (!t.done && dist(self, t) <= TASK_RANGE) { task = t.id; break }
    }
  }
  if (gameNearTaskId.value !== task) gameNearTaskId.value = task

  // A body you could report.
  let body = false
  if (meAlive) {
    for (const b of s.bodies) {
      if (dist(self, b) <= BODY_RANGE) { body = true; break }
    }
  }
  if (gameNearBody.value !== body) gameNearBody.value = body

  // Someone you could kill: impostor, alive, off cooldown, a living non-impostor in reach.
  const now = Date.now()
  const cooldownEnds = s.my_cooldown ?? 0
  let kill: number | null = null

  if (s.my_role === 'impostor' && meAlive && cooldownEnds <= now) {
    let best = KILL_RANGE
    for (const [idStr, p] of Object.entries(s.players)) {
      const id = Number(idStr)
      // Co-impostors show their role (see the server's view), so we can spare them.
      if (id === myId || !p.alive || p.role === 'impostor') continue
      const pos = others.value[id]
      if (!pos) continue
      const d = dist(self, pos)
      if (d <= best) { best = d; kill = id }
    }
  }
  if (gameKillTarget.value !== kill) gameKillTarget.value = kill

  const cd = s.my_role === 'impostor' && cooldownEnds > now ? Math.ceil((cooldownEnds - now) / 1000) : 0
  if (gameCooldownLeft.value !== cd) gameCooldownLeft.value = cd
}

/** Every game move the panel asks for, fulfilled with the positions only the stage has. */
async function onDoTask() {
  if (gameNearTaskId.value) await actGame('complete_task', { task: gameNearTaskId.value }).catch(() => {})
}
async function onReportOrMeeting() {
  await actGame('call_meeting').catch(() => {})
}
async function onKill() {
  const target = gameKillTarget.value
  const victim = target != null ? others.value[target] : null
  if (target == null || !victim) return
  // The body is left where the victim was standing.
  await actGame('kill', { target, x: Math.round(victim.x), y: Math.round(victim.y) }).catch(() => {})
}
async function onGameVote(target: number | 'skip') {
  await actGame('vote', { target }).catch(() => {})
}

// --- pet battle moves ---

async function onBattleMove(move: string) {
  await actGame('move', { move }).catch(() => {})
}
async function onForfeit() {
  await actGame('forfeit').catch(() => {})
}
/** Accept / decline a challenge is just a yes/no vote, which the framework already understands. */
async function onAccept() {
  await voteGame(true).catch(() => {})
}
async function onDecline() {
  await voteGame(false).catch(() => {})
}

// --- proposing / challenging ---

/**
 * A challenge-mode game (a pet battle) can't be proposed to the room — it's aimed at a person, so
 * picking it switches the menu to a list of who's here to challenge. `null` means "showing games".
 */
const challengeType = ref<string | null>(null)

/** Who you could challenge: everyone else standing in the room. */
const challengeTargets = computed(() =>
  Object.values(others.value).map(o => ({ id: o.id, name: o.name })))

async function onProposeGame(type: string) {
  const info = gameCatalogue.value.find(g => g.type === type)

  // A duel needs a target first; a room game goes straight to the vote.
  if (info?.mode === 'challenge') {
    challengeType.value = type

    return
  }

  showPropose.value = false
  await proposeGame(type).catch(() => {})
}

async function onChallenge(opponentId: number) {
  const type = challengeType.value
  challengeType.value = null
  showPropose.value = false
  if (type) await proposeGame(type, opponentId).catch(() => {})
}

function togglePropose() {
  showPropose.value = !showPropose.value
  challengeType.value = null
  if (showPropose.value) void loadGameCatalogue()
}
async function onGameDismiss() {
  // An ended game is just cleared away locally; anything still live is called off for the room.
  if (game.value?.status === 'ended') game.value = null
  else await cancelGame().catch(() => {})
}

/**
 * A meeting takes the room over: everyone is whisked to the entrance and, for its duration, hears
 * everyone else at full volume rather than by distance. The teleport is ours to perform (the
 * server only records that it happened); the full-volume voice is {@link useSpaceProximity}'s doing.
 */
watch(gameMeeting, (on, was) => {
  if (on && !was) {
    if (map.value) warp(map.value.spawn.x, map.value.spawn.y)
    unbindKeys()
  } else if (!on && was && inThisRoom.value && !editing.value && !dressing.value) {
    bindKeys()
  }
})

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
/** Are we in *this* room's call, as opposed to some other channel's? */
const inThisRoom = computed(() => inCall.value && activeCallChannel.value === props.channel.id)

/** The zone you're standing in, named in a banner so the audio rules are never a surprise. */
const currentZone = computed(() => (map.value && me.value ? zoneAt(map.value, me.value.x, me.value.y) : null))

const occupantCount = computed(() => Object.keys(others.value).length + (me.value ? 1 : 0))

let frame: number | undefined
let lastAt = 0
/**
 * When this stage started drawing. Every animated tile is a function of the seconds since, so
 * water ripples and grass sways at the same rate no matter how long the tab has been open —
 * `performance.now()` alone is a number large enough that the sine of it starts to lose
 * resolution after a few days.
 */
let openedAt = performance.now()
let ro: ResizeObserver | undefined
let cssW = 0
let cssH = 0
const camera = reactive<Camera>({ x: 0, y: 0, zoom: 1, width: 0, height: 0 })

/** Behind everything: what's beyond the walls of a room that doesn't fill the canvas. */
const OUTSIDE = '#20242c'

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
      look: p.user.space_avatar,
      pet: p.user.space_pet,
    })))
    // …and put yourself back where you were standing, if the room still allows it.
    const mine = roster.find(p => p.user.id === user.value?.id)
    place(mine && mine.x !== null && mine.y !== null ? { x: mine.x, y: mine.y, facing: mine.facing ?? null } : null)

    // Last, because it needs somewhere to measure from: it runs the instant it's registered.
    watchProximity()
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

  // Re-registered rather than left alone, even though the proximity clock has been ticking away
  // happily without us: the accessors it holds close over *this* mount's map and game state, and
  // the ones from the mount that went away are reading refs nothing updates any more.
  watchProximity()

  // If the position state somehow didn't survive (a hard reload lands here with the call
  // restored from the server but nothing placed), fall back to the entrance.
  if (!me.value) place(null)
}

async function leave() {
  unsubscribeMoves()
  stopProximity()
  facingObject.value = null
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
    // A meeting freezes the room — nobody walks, so movement isn't ticked; everything else runs
    // so the countdown and the voice keep going.
    if (!gameMeeting.value) tick(dt)

    // Our *own* movement, which nothing whispers to us. Proximity has its own clock (and its own
    // reasons — see useSpaceProximity), and this only ever brings the next evaluation forward:
    // it throttles itself, so a 144Hz monitor doesn't decide the room's audio 144 times a second.
    proximityMoved()

    checkFurniture()
    checkGame()
  }

  draw()
}

/**
 * Everything the proximity clock needs, handed over as accessors.
 *
 * Registered on the way in and torn down on the way out — see {@link useSpaceProximity} for why
 * it runs on its own interval instead of in the frame loop above.
 */
function watchProximity() {
  startProximity({
    map,
    me: () => me.value,
    others: () => others.value,
    knownMembers,
    setPeerProximity,
    setPeerInRange,
    fireEffect,
    meeting: () => gameMeeting.value,
    quiet: () => gameRunning.value,
  })
}

// --- using the furniture ---

/**
 * What pressing E would open, from where you're standing.
 *
 * Only interactive pieces count — a plant is scenery, and prompting you to press E on it would
 * make the prompt worthless everywhere it matters.
 */
function checkFurniture() {
  const m = map.value
  const self = me.value

  // While a game is running, E belongs to the game (a task, a report), so the furniture prompt
  // stands down — you can't put a record on in the middle of a round.
  const found = m && self && !gameRunning.value ? decorInFront(m.objects, self) : null

  // Only when it changes: this runs sixty times a second.
  if (found?.id !== facingObject.value?.id) facingObject.value = found
}

/** The label on the prompt — "Press E to put something on". */
const interactHint = computed(() => {
  const kind = facingObject.value ? decorKind(facingObject.value.kind) : null

  return kind ? `${kind.verb ?? 'Use'} the ${kind.label.toLowerCase()}` : ''
})

/**
 * Use it.
 *
 * The server decides what a given object opens, from its own copy of the map — we send an id
 * and get back a widget. What comes back is the *channel's* widget of that type: the same music
 * player `m!` would have made, the same one everybody else at the speaker is looking at. So
 * this is a doorway to something the app already has rather than a new thing that happens to
 * live in a room, and listening along, the queue and the floating shelf all work already.
 */
async function useFurniture() {
  const object = facingObject.value
  if (!object || using.value || !inThisRoom.value) return

  using.value = true

  try {
    const res = await api<{ data: Widget }>(`/api/channels/${props.channel.id}/space/interact`, {
      method: 'POST',
      body: { object_id: object.id },
    })

    const kind = decorKind(object.kind)

    // Music is the one widget that doesn't go on the shelf directly. It has a dedicated brain
    // (useMusicPin) that owns the pinned widget, the listen-along opt-in and the Spotify
    // hand-off, and the floating window it opens renders *that* rather than the generic card —
    // so a window opened around it without pinning first would come up empty and close itself.
    if (res.data.type === 'music') {
      useMusicPin().pin(res.data)

      return
    }

    openWindow({
      kind: 'widget',
      widgetId: res.data.id,
      channelId: props.channel.id,
      widgetType: res.data.type,
      title: kind?.label ?? 'Widget',
    })
  }
  catch {
    // The room may have been rebuilt out from under the prompt. Nothing to say — the prompt
    // will vanish on the next frame along with the object it was pointing at.
  }
  finally {
    using.value = false
  }
}

/**
 * E, or Enter, to use what you're standing at.
 *
 * Bound here rather than in useSpacePresence because it belongs to the *stage* — the movement
 * keys are unbound when the room leaves the screen and so is this, for the same reason: E should
 * type an E when you're reading another channel.
 */
function onInteractKey(e: KeyboardEvent) {
  if (e.key !== 'e' && e.key !== 'E' && e.key !== 'Enter') return
  if (!inThisRoom.value) return
  // A sheet is open over the room — the editor, or the picker. Neither is a place where a
  // letter should reach past it and switch the telly on.
  if (editing.value || dressing.value) return

  const el = e.target as HTMLElement | null
  if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)) return

  // During a game, E does the game: a task at your feet, or a body to report. Nothing else, so
  // that "press E" always means the one thing worth doing where you're standing.
  if (gameRunning.value) {
    if (gameNearTaskId.value) { e.preventDefault(); void onDoTask() }
    else if (gameNearBody.value) { e.preventDefault(); void onReportOrMeeting() }

    return
  }

  if (!facingObject.value) return

  e.preventDefault()
  void useFurniture()
}

/**
 * A new look, saved by the dialog and now worn.
 *
 * The dialog has already written it to the server; this is the part that puts it on the sprite
 * standing in the room, which would otherwise keep the old one until the next time the roster
 * was rebuilt — that is, until you walked out and back in.
 */
function onDressed(look: Parameters<typeof restyle>[0], pet: Parameters<typeof restyle>[1]) {
  dressing.value = false
  restyle(look, pet)
}

// --- drawing ---

/**
 * The two colours the room still borrows from the app's theme.
 *
 * The ground isn't one of them: grass, water and floorboards are drawn from a fixed overworld
 * palette (see lib/spaceTiles), because no amount of somebody's chosen indigo makes a pond.
 * What's left here is *annotation* — the tint on a private zone and the colour its name is
 * written in — which should match the app, since it's the app talking rather than the room.
 *
 * Reading the custom properties directly does *not* work: they're declared as
 * `oklch(0.955 calc(0.016 * var(--cs)) var(--h))`, and `getPropertyValue` hands back that
 * string with the `var()`s unresolved. Canvas can't parse it, silently ignores the assignment,
 * and paints everything in whatever colour happened to be set last.
 *
 * So we resolve them the only way the platform offers: park the value on a real element's
 * `color` and read the *computed* style back, which the browser has by then flattened to an
 * actual colour. Cached, because that's a layout read — and re-resolved once a second so
 * flipping the theme catches up without anything watching it.
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
    zone: 'rgb(99 102 241 / 0.10)',
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
  // Everything outside the room, so a map with void round it reads as a place rather than as a
  // hole in the page.
  ctx.fillStyle = OUTSIDE
  ctx.fillRect(0, 0, camera.width, camera.height)

  drawMap(ctx, m, camera, palette, (performance.now() - openedAt) / 1000)

  // The game's things on the floor — tasks to walk to, bodies to find — under everyone.
  if (gameRunning.value) drawGame(ctx)

  // How far your own voice carries. Only your own — six overlapping rings would be a fog.
  // Hidden during a game, where earshot is a giveaway rather than an affordance.
  if (me.value && !gameRunning.value) drawEarshot(ctx, camera, me.value, 'rgb(99 102 241 / 0.13)', 'rgb(99 102 241 / 0)')

  // Painter's algorithm: whoever is further down the map is nearer the viewer, so they're
  // drawn last and overlap correctly. Without it two people on adjacent tiles overlap
  // according to object key order, which changes as people come and go. Pets are sorted into
  // the same list rather than drawn after their owner, or a pet standing a tile north of you
  // would be painted over your head.
  const cast: Array<{ y: number, paint: () => void }> = []

  for (const o of Object.values(others.value)) {
    cast.push({ y: o.y, paint: () => drawPerson(ctx, o, false, isWalking(o)) })
    if (o.pet && o.petAt) cast.push({ y: o.petAt.y, paint: () => drawCompanion(ctx, o) })
  }

  if (me.value) {
    const self = me.value
    cast.push({ y: self.y, paint: () => drawPerson(ctx, self, true, moving.value) })
    if (self.pet && self.petAt) cast.push({ y: self.petAt.y, paint: () => drawCompanion(ctx, self) })
  }

  cast.sort((a, b) => a.y - b.y)
  for (const item of cast) item.paint()

  // The "press E" prompt is drawn over the room rather than in the DOM, because it belongs to a
  // *tile* — pinning an HTML bubble to a moving world-space position means a transform update
  // every frame, which is the one thing canvas is already doing.
  drawPrompt(ctx)
}

/**
 * A pet, at its own position rather than its owner's.
 *
 * `moving` is measured from the gap to its owner rather than from its actual velocity: a pet
 * that has just been blocked by a couch is still trying, and should still be bobbing.
 */
function drawCompanion(ctx: CanvasRenderingContext2D, owner: Occupant) {
  if (!owner.pet || !owner.petAt) return

  const size = TILE * camera.zoom
  const p = toScreen(camera, owner.petAt.x, owner.petAt.y)
  const chasing = Math.hypot(owner.x - owner.petAt.x, owner.y - owner.petAt.y) > 1.05

  ctx.save()
  // Faded with earshot exactly as its owner is, so a distant pair reads as one distant pair.
  ctx.globalAlpha = owner.id === user.value?.id
    ? 1
    : 0.4 + (map.value && me.value ? audibility(map.value, me.value, owner) : 0) * 0.6

  drawPet(ctx, owner.pet, owner.petAt.facing, p.x, p.y, size, chasing, performance.now() / 1000)
  ctx.restore()
}

/** "Press E to watch something", floating over whatever you're standing at. */
function drawPrompt(ctx: CanvasRenderingContext2D) {
  const object = facingObject.value
  const kind = object ? decorKind(object.kind) : null
  if (!object || !kind || !inThisRoom.value) return

  const size = TILE * camera.zoom
  const p = toScreen(camera, object.x + (kind.w - 1) / 2, object.y - 0.6)
  const label = `E · ${kind.verb ?? 'Use'}`

  ctx.save()
  ctx.font = `600 ${Math.max(10, size * 0.3)}px system-ui, sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'

  const w = ctx.measureText(label).width + size * 0.5
  const h = size * 0.5

  ctx.fillStyle = 'rgb(23 23 30 / 0.88)'
  ctx.beginPath()
  ctx.roundRect(p.x - w / 2, p.y - h / 2, w, h, h / 2)
  ctx.fill()

  ctx.fillStyle = '#ffffff'
  ctx.fillText(label, p.x, p.y + 1)
  ctx.restore()
}

/**
 * The game's things on the floor: your tasks, and any bodies.
 *
 * Tasks are drawn only for the player they belong to — the state already hides everyone else's,
 * so there's nothing here to leak — as a bobbing marker over the tile you have to reach. Bodies
 * are drawn for everyone, in the fallen colour of whoever they were, because finding one is how a
 * round turns into a meeting.
 */
function drawGame(ctx: CanvasRenderingContext2D) {
  const s = amState.value
  if (!s) return

  const size = TILE * camera.zoom
  const t = performance.now() / 1000

  if (s.phase === 'play') {
    for (const task of s.my_tasks) {
      if (task.done) continue

      const p = toScreen(camera, task.x, task.y)
      const bob = Math.sin(t * 3 + task.x + task.y) * size * 0.08
      const cy = p.y - size * 0.6 + bob

      // A little amber diamond, haloed so it reads over any floor.
      ctx.save()
      ctx.globalAlpha = 0.9
      ctx.translate(p.x, cy)
      ctx.rotate(Math.PI / 4)
      const r = size * 0.16
      ctx.fillStyle = 'rgb(250 204 21)'
      ctx.strokeStyle = 'rgb(120 80 0 / 0.6)'
      ctx.lineWidth = 2
      ctx.fillRect(-r, -r, r * 2, r * 2)
      ctx.strokeRect(-r, -r, r * 2, r * 2)
      ctx.restore()
    }
  }

  for (const body of s.bodies) {
    const p = toScreen(camera, body.x, body.y)
    const hue = spriteHue(body.user)

    ctx.save()
    // A dark pool under a slumped figure.
    ctx.fillStyle = 'rgb(120 0 0 / 0.35)'
    ctx.beginPath()
    ctx.ellipse(p.x, p.y + size * 0.32, size * 0.4, size * 0.16, 0, 0, Math.PI * 2)
    ctx.fill()

    ctx.fillStyle = `hsl(${hue} 45% 42%)`
    ctx.beginPath()
    ctx.ellipse(p.x, p.y + size * 0.24, size * 0.28, size * 0.16, 0, 0, Math.PI * 2)
    ctx.fill()

    // A cross for the eyes, so it reads as "down" rather than "lying down having a nap".
    ctx.strokeStyle = '#fff'
    ctx.lineWidth = Math.max(1.5, size * 0.04)
    const ex = size * 0.08
    for (const dx of [-size * 0.1, size * 0.1]) {
      ctx.beginPath()
      ctx.moveTo(p.x + dx - ex, p.y + size * 0.18 - ex)
      ctx.lineTo(p.x + dx + ex, p.y + size * 0.18 + ex)
      ctx.moveTo(p.x + dx + ex, p.y + size * 0.18 - ex)
      ctx.lineTo(p.x + dx - ex, p.y + size * 0.18 + ex)
      ctx.stroke()
    }
    ctx.restore()
  }
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

  drawTrainer(ctx, o, p.x, p.y, size, {
    look: normaliseLook(o.look),
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
  editing.value = null
  await loadMap()
}

onMounted(async () => {
  openedAt = performance.now()
  await loadMap()
  subscribeMap()

  // The room's game, if one's afoot. Loaded and listened to alongside the map, on the same
  // channel stream — a game you walk in on should already be on screen, not a beat behind.
  await loadGame()
  subscribeGame()

  resize()
  ro = new ResizeObserver(resize)
  if (wrap.value) ro.observe(wrap.value)

  // Walked in earlier and only now come back to look at it. The map has to be loaded first —
  // `place` needs to know which tiles you're allowed to stand on.
  reattach()

  window.addEventListener('keydown', onInteractKey)

  frame = requestAnimationFrame(loop)
})

onBeforeUnmount(() => {
  if (frame) cancelAnimationFrame(frame)
  window.removeEventListener('keydown', onInteractKey)
  ro?.disconnect()
  probe?.remove()
  probe = null
  palette = null
  unsubscribeMap()
  unsubscribeGame()

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

  stopProximity()
  facingObject.value = null
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

        <!-- What you look like walking around. Yours, not the room's, so it isn't owner-gated. -->
        <button
          type="button"
          class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          title="Change how you look, and pick a companion"
          @click="dressing = true"
        >
          <Shirt class="h-4 w-4" />
        </button>

        <!-- Start a game. Only when you're in the room and none is already on the table; a game in
             progress is driven by the panel over the map, not from up here. -->
        <div v-if="canProposeGame" class="relative">
          <button
            type="button"
            class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Start a game in the room"
            @click="togglePropose"
          >
            <Gamepad2 class="h-4 w-4" />
          </button>

          <div
            v-if="showPropose"
            class="absolute right-0 top-full z-30 mt-1 w-60 space-y-1 rounded-lg border bg-background p-1.5 shadow-xl"
          >
            <!-- The games to choose from. A duel switches this list to a list of people. -->
            <template v-if="!challengeType">
              <p class="px-2 py-1 text-[11px] font-medium text-muted-foreground">Start a game</p>
              <button
                v-for="g in gameCatalogue"
                :key="g.type"
                type="button"
                class="w-full rounded-md px-2 py-1.5 text-left transition-colors hover:bg-muted"
                @click="onProposeGame(g.type)"
              >
                <span class="block text-sm font-medium">
                  {{ g.label }}
                  <span v-if="g.mode === 'challenge'" class="text-[10px] font-normal text-muted-foreground">· challenge</span>
                </span>
                <span class="block text-[11px] leading-snug text-muted-foreground">{{ g.blurb }}</span>
              </button>
              <p v-if="!gameCatalogue.length" class="px-2 py-1 text-xs text-muted-foreground">Loading…</p>
            </template>

            <!-- Who to challenge. -->
            <template v-else>
              <button
                class="flex w-full items-center gap-1 px-2 py-1 text-[11px] font-medium text-muted-foreground hover:text-foreground"
                @click="challengeType = null"
              >
                ‹ Pick who to battle
              </button>
              <button
                v-for="t in challengeTargets"
                :key="t.id"
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-muted"
                @click="onChallenge(t.id)"
              >
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: `hsl(${spriteHue(t.id)} 62% 52%)` }" />
                <span class="min-w-0 flex-1 truncate">{{ t.name }}</span>
              </button>
              <p v-if="!challengeTargets.length" class="px-2 py-1 text-xs text-muted-foreground">Nobody else is here to challenge.</p>
            </template>
          </div>
        </div>

        <!-- Decorating is open to anyone; it only ever touches the furniture. Hidden from the
             owner, who reaches the same furniture (and everything else) through the full editor. -->
        <button
          v-if="!canEdit && !editing"
          type="button"
          class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          title="Rearrange the furniture"
          @click="editing = 'decor'"
        >
          <Sofa class="h-4 w-4" />
        </button>

        <button
          v-if="canEdit && !editing"
          type="button"
          class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          title="Edit this room"
          @click="editing = 'full'"
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
            <p class="text-xs text-muted-foreground">
              Walk up to the speaker, the TV or an arcade cabinet and press <kbd class="rounded border px-1">E</kbd> to
              put something on for whoever's nearby.
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

        <!--
          Standing at something you can use. The canvas already draws a marker over the object
          itself; this is the half that says what the key does, and gives a pointer a target —
          not everybody will think to press a letter.
        -->
        <button
          v-if="inThisRoom && facingObject"
          type="button"
          class="absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full bg-foreground px-3 py-1.5 text-xs font-medium text-background shadow-lg transition hover:opacity-90 disabled:opacity-60"
          :disabled="using"
          @click="useFurniture"
        >
          <Loader2 v-if="using" class="h-3.5 w-3.5 animate-spin" />
          <span v-else class="rounded border border-background/40 px-1 text-[10px] leading-4">E</span>
          {{ interactHint }}
        </button>

        <!-- Who can hear you right now. The rule is invisible otherwise. Stood down during a
             game, where the HUD owns the bottom of the screen and earshot is a tell. -->
        <div
          v-if="inThisRoom && !gameRunning"
          class="pointer-events-none absolute bottom-2 left-2 max-w-[60%] rounded-md bg-background/85 px-2 py-1 text-[11px] text-muted-foreground shadow-sm"
        >
          <template v-if="audiblePeople.length">
            In earshot: <span class="font-medium text-foreground">{{ audiblePeople.map(p => p.name).join(', ') }}</span>
          </template>
          <template v-else>
            Nobody's in earshot — walk over to somebody to talk.
          </template>
        </div>

        <!-- The game, when there is one. Which panel is a question of which game: Among Us draws
             its HUD and meetings over the map, a pet battle its arena. Both draw their own overlays
             and manage their own pointer events; the stage just feeds them what they can't know
             (who's where) and carries out what their buttons ask for. -->
        <template v-if="showGamePanel && game">
          <SpaceGamePanel
            v-if="game.type === 'amongus'"
            :game="game"
            :players="gamePlayers"
            :near-task-id="gameNearTaskId"
            :near-body="gameNearBody"
            :kill-target="gameKillTarget"
            :cooldown-left="gameCooldownLeft"
            @vote="voteGame"
            @dismiss="onGameDismiss"
            @do-task="onDoTask"
            @report="onReportOrMeeting"
            @meeting="onReportOrMeeting"
            @kill="onKill"
            @game-vote="onGameVote"
          />
          <PetBattlePanel
            v-else-if="game.type === 'petbattle'"
            :game="game"
            :names="roomNames"
            :my-id="user?.id ?? null"
            @accept="onAccept"
            @decline="onDecline"
            @dismiss="onGameDismiss"
            @move="onBattleMove"
            @forfeit="onForfeit"
          />
        </template>
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
      :mode="editing"
      @close="editing = null"
      @saved="onMapSaved"
    />

    <SpaceAppearanceDialog
      v-if="dressing"
      @close="dressing = false"
      @saved="onDressed"
    />
  </div>
</template>
