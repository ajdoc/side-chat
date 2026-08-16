<script setup lang="ts">
import {
  AudioLines,
  ChevronLeft,
  DoorOpen,
  Headphones,
  HeadphoneOff,
  Loader2,
  Map as MapIcon,
  Megaphone,
  MessageCircle,
  MessageCircleOff,
  MoreHorizontal,
  Mic,
  MicOff,
  Minus,
  Pencil,
  PhoneOff,
  Plus,
  ScreenShare,
  ScreenShareOff,
  Gamepad2,
  Hand,
  Lock,
  Shirt,
  Smile,
  Sofa,
  SwitchCamera,
  Users,
  Video,
  VideoOff,
} from 'lucide-vue-next'
import { useLocalStorage } from '@vueuse/core'
import type { AmongUsState, Channel, SpaceInteraction, VoiceParticipant } from '~/types'
import type { Camera, ExhibitPiece, MapTheme, Occupant, SpaceExhibit, SpaceMap, SpacePortal, SpaceScreen } from '~/lib/spaceMapEngine'
import type { SpaceObject } from '~/lib/spaceDecor'
import type { RoomEffectInstance } from '~/lib/spaceEffects'
import type { RoomEvent } from '~/composables/useSpacePresence'
import {
  FAR_TILES,
  MAX_ZOOM,
  MIN_ZOOM,
  STAGE_SPEAKERS,
  TILE,
  ZOOM_STEP,
  audibility,
  clampZoom,
  drawEarshot,
  drawMap,
  drawPet,
  drawTrainer,
  isWalkable,
  activationOf,
  doorwayInto,
  exhibitNear,
  pieceIn,
  portalAt,
  screenNear,
  spawnPoint,
  standableIn,
  spriteHue,
  toScreen,
  toWorld,
  zoneAt,
} from '~/lib/spaceMapEngine'
import { decorInFront, decorKind, decorSize, seatInFront, seatOn } from '~/lib/spaceDecor'
import { canTryPassword, doorInFront, lockMap, mayPass, passesExpireAt, syncDoors } from '~/lib/spaceDoors'
import { EFFECT_MS, drawRoomEffect, drawRoomEffectLabel } from '~/lib/spaceEffects'
import { normaliseLook } from '~/lib/spaceAvatar'
import { EMOTES, EMOTE_MS, emojiSprite } from '~/lib/spaceEmotes'
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
  screenStream,
  sharingPeer,
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
  switchCamera,
  canSwitchCamera,
  cameraFacing,
  isAudioSharing,
  setProximityMode,
  setPeerProximity,
  setPeerInRange,
  knownMembers,
  fireEffect,
} = useVoice()

const {
  map,
  // Which room of the building we're standing in, and how to walk into another one. A Side
  // Space is several maps behind one channel — see useSpaceMap.
  slug: mapSlug,
  openMap,
  createMap,
  deleteMap,
  loading,
  error: mapError,
  load: loadMap,
  refresh: refreshMap,
  subscribe: subscribeMap,
  unsubscribe: unsubscribeMap,
} = useSpaceMap(props.channel.id)
const {
  me,
  others,
  moving,
  attached,
  place,
  restyle,
  reshout,
  warp,
  seed,
  tick,
  walkTo,
  stopWalking,
  sit,
  stand,
  seated,
  isWalking,
  approach,
  approachingId,
  handPartnerOf,
  handOffer,
  offerHand,
  acceptHand,
  declineHand,
  letGo,
  following,
  summon,
  release,
  emote,
  subscribe: subscribeMoves,
  unsubscribe: unsubscribeMoves,
  onRoomEvent,
  bindKeys,
  unbindKeys,
} = useSpacePresence(props.channel.id, map)

/** Undoes the room-event subscription. Held for as long as the room is on screen. */
let stopRoomEvents: (() => void) | undefined

const {
  game,
  catalogue: gameCatalogue,
  load: loadGame,
  loadCatalogue: loadGameCatalogue,
  propose: proposeGame,
  vote: voteGame,
  join: joinGame,
  act: actGame,
  cancel: cancelGame,
  subscribe: subscribeGame,
  unsubscribe: unsubscribeGame,
} = useSpaceGame(props.channel.id)

const { audibleIds, liveIds, startProximity, stopProximity } = useSpaceProximity()

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
/** The shout dialog. A sheet over the room like the others, so it takes the keys with it. */
const shouting = ref(false)
/**
 * What you're shouting right now, for the toolbar.
 *
 * Read off the sprite rather than off the user record, so it changes the instant `reshout` puts
 * the bubble up — the save that follows is what makes it survive a reload, not what makes it true.
 */
const myShout = computed(() => me.value?.shout ?? null)

/**
 * Chat over people's heads: whether you want it, and what to draw on anyone in particular.
 *
 * Only read here — ChannelView, which owns the timeline and the typing whispers this room sits
 * inside, is what fills it. See useSpaceChatBubbles.
 */
const { enabled: bubblesOn, bubbleFor } = useSpaceChatBubbles()
/** The rooms-and-locks panel. Only ever opened by somebody with something to manage. */
const managingLocks = ref(false)

/**
 * The door whose password is being asked for, if any — a sheet over the room like the others,
 * which is why the key handler treats it as one.
 */
const askingDoor = ref<string | null>(null)

/**
 * Whether this person administers anything in here.
 *
 * Read off the *map*, not off a permission call: the map already carries who owns which room,
 * because the doors need it. So the server owner sees the button, a room owner sees the button,
 * and a member with no room never learns there is one — with no extra request to find out.
 */
const managesRooms = computed(() =>
  canModerate.value || (map.value?.rooms ?? []).some(r => r.owner_id === user.value?.id))

/**
 * Everybody in the channel — who a room can be handed to, and who can be given a key.
 *
 * The *channel's* roster rather than the room's: putting somebody in charge of a room, or giving
 * them a key to it, is most useful precisely for the people who aren't standing in it at the
 * moment. Loaded lazily, when the panel is first opened, since most visits never need it.
 */
const { members: channelMembers, load: loadChannelMembers } = useChannelMembers()
const roomMembers = computed(() => channelMembers.value.map(m => ({ id: m.id, name: m.name })))

watch(managingLocks, (open) => { if (open) void loadChannelMembers(props.channel.id) })

/**
 * The piece of furniture you're standing at, if any, and whether we're mid-way through opening
 * what it points at.
 *
 * Recomputed in the frame loop rather than watched, because "what am I next to" is a continuous
 * function of position exactly as audibility is — and like audibility, it only writes to a ref
 * when the answer actually changes, so the prompt doesn't re-render sixty times a second.
 */
const facingObject = ref<SpaceObject | null>(null)
/**
 * The seat within reach, tracked exactly as the usable piece above it is and for the same
 * reason: it's a question about where you're standing, asked every frame, whose answer only
 * changes when you walk. Kept apart from `facingObject` because nothing is both — a couch opens
 * no app, and pressing E at a TV should watch it rather than sit on it.
 */
const facingSeat = ref<SpaceObject | null>(null)

/**
 * How close somebody has to be standing before you can reach for their hand.
 *
 * Deliberately a plain radius rather than the "in front of you" reach the furniture uses. A
 * chair is a thing at a *facing*, and a person is a person: turning to face somebody before you
 * can offer them your hand is a rule nobody would guess and everybody would trip over.
 */
const HOLD_REACH = 1.9

/**
 * The nearest person you could take by the hand, if any.
 *
 * Tracked in the frame loop beside {@link facingObject} and written only when it changes, for
 * the same reason: "who am I standing next to" is a continuous function of where everybody is,
 * and a ref rewritten sixty times a second would re-render the prompt sixty times a second.
 */
const nearestPerson = ref<{ id: number, name: string } | null>(null)

/** Whether the emote grid is unfolded. Shut by default — see the bar in the template. */
const emoteBarOpen = ref(false)

/** Whether the "who's here" list is open. */
const rosterOpen = ref(false)

/**
 * Everybody in the room but you, nearest first, with what it takes to decide to walk over.
 *
 * The room's answer to a problem a map has and a list of participants doesn't: you can see six
 * people, and you have no idea *which* six, because from across a 30-tile room a sprite is a
 * hat. The call dock beside it answers a different question — it lists who you can currently
 * hear, which is deliberately only the people near you.
 *
 * Recomputed off `others`, which is a `shallowRef` mutated in place during the frame loop, so
 * this only refreshes when the roster genuinely changes (somebody arrives, leaves, is pruned).
 * The distances are therefore a beat behind at worst, which is the right trade for a list you
 * read rather than a thing you aim at.
 */
const roster = computed(() => {
  const self = me.value

  return Object.values(others.value)
    .map(o => ({
      id: o.id,
      name: o.name,
      // Where they are, said in the way that's useful: the room they're in if the map names
      // one, else how far off they are.
      zone: map.value ? zoneAt(map.value, o.x, o.y)?.name ?? null : null,
      distance: self ? Math.hypot(o.x - self.x, o.y - self.y) : 0,
      audible: audibleIds.value.includes(o.id),
      live: liveIds.value.includes(o.id),
      sitting: !!o.seatedOn,
    }))
    .sort((a, b) => a.distance - b.distance)
})

/** Walk over to somebody from the list, and get the list out of the way while you do. */
function goTo(id: number) {
  approach(id)
  rosterOpen.value = false
}

/**
 * Whoever we've summoned by name, as opposed to summoning the room.
 *
 * A set rather than a flag because these are independent: fetching one person out of a corner
 * and then fetching another is two summons, and letting the first go shouldn't let go of the
 * second. Local for the same reason {@link leading} is — your own client drops your own summon.
 */
const summonedIds = ref<number[]>([])

/** Call one person over, or let that one person go. See {@link toggleSummon} for the room. */
async function toggleSummonOne(id: number) {
  const held = summonedIds.value.includes(id)

  try {
    if (held) await release([id])
    else await summon([id])

    summonedIds.value = held ? summonedIds.value.filter(i => i !== id) : [...summonedIds.value, id]
  }
  catch {
    // Nothing to say: the room carried on as it was, which is visible on screen already.
  }
}

/** Whose hand you're holding, or null. Read from your own half of the link — see handPartnerOf. */
const holdingWith = computed(() => (me.value ? handPartnerOf(me.value) : null))

/**
 * Everyone standing in the room, me included — what the doors are computed against.
 *
 * A door opens for *anybody* who may pass, not just for the person at this keyboard; see
 * spaceDoors. Rebuilt per frame from two refs that are already reactive, which is cheap next to
 * the drawing that follows it.
 */
const occupants = computed(() => (me.value ? [me.value, ...Object.values(others.value)] : Object.values(others.value)))

/** Which doors are locked and who holds a key, in the shape the frame loop wants to ask. */
const locks = computed(() => lockMap(map.value?.locks))

/**
 * Whether the room is big enough to be worth an overview.
 *
 * A threshold rather than a setting. The stage frames about sixteen tiles of height, so anything
 * near that is already entirely on screen and a minimap of it would be a smaller copy of what you
 * are looking at — clutter that costs a repaint. A city twice that in both directions is mostly
 * off-screen at any moment, and then it earns its corner.
 */
const showMiniMap = computed(() => (map.value?.width ?? 0) > 34 || (map.value?.height ?? 0) > 24)

/**
 * The clock the *prompt* runs on, as distinct from the door itself.
 *
 * A password pass lapses on its own, with no map and no event behind it — see spaceDoors. The
 * doors handle that by themselves, because the frame loop re-asks every frame anyway. The label
 * over your head doesn't: it's a computed, and nothing it reads changes at the moment a pass
 * runs out, so without this you would stand in a doorway that had quietly shut with no
 * explanation and no way to ask for one.
 *
 * So the loop nudges this ref at the deadline, and only at the deadline — one write per expired
 * pass rather than a timestamp ticking sixty times a second through half the room's computeds.
 */
/**
 * Somebody at this keyboard just said a door's password.
 *
 * Fetches the map rather than waiting for the broadcast to come back around: the pass is already
 * granted, and the only thing standing between them and the door is knowing about it. If the
 * fetch fails the ping is still coming, so there is nothing to report and nothing to undo.
 */
function onEnteredDoor() {
  void refreshMap().catch(() => {})
}

const passClock = ref(0)
const nextPassExpiry = computed(() => {
  void passClock.value

  return passesExpireAt(locks.value)
})

/** The door you're standing at, if any — for the "this one's locked" prompt. */
const facingDoor = computed(() => (me.value ? doorInFront(map.value, me.value) : null))

/**
 * What the locked-door label says.
 *
 * Names whoever is responsible for the room where there is somebody, because "this is locked" is
 * a dead end and "this is Alice's room" is a thing you can do something about.
 */
const lockedDoorHint = computed(() => {
  const door = blockedDoor.value
  if (!door) return ''

  const zone = map.value?.locks?.find(l => l.object_id === door.id)?.zone_id
  const owners = (map.value?.rooms ?? []).filter(r => r.zone_id === zone).map(r => r.owner).filter(Boolean)
  const room = (map.value?.zones ?? []).find(z => z.id === zone)?.name

  // Name one of them and count the rest — a room can be several people's, and a label listing
  // four names is a label nobody reads.
  const who = owners.length > 1 ? `${owners[0]} or ${owners.length - 1} other${owners.length > 2 ? 's' : ''}` : owners[0]

  if (blockedDoorTakesPassword.value) return room ? `${room} is locked — enter the password` : 'Locked — enter the password'

  if (who && room) return `${room} is locked — ask ${who}`
  if (room) return `${room} is locked`

  return 'This door is locked'
})

/** The room a door guards, by name — for the password prompt's title. */
const blockedRoomName = computed(() => {
  const zone = map.value?.locks?.find(l => l.object_id === blockedDoor.value?.id)?.zone_id

  return (map.value?.zones ?? []).find(z => z.id === zone)?.name ?? null
})

/**
 * Whether the door in your way is one you could talk your way through.
 *
 * A padlock means two different things — "ask whoever owns this room" and "type the words" —
 * and the prompt has to say which, or somebody who was told the password stands in front of a
 * dead end that was never a dead end.
 */
const blockedDoorTakesPassword = computed(() =>
  !!blockedDoor.value && canTryPassword(locks.value, blockedDoor.value.id, me.value?.id))

/** A door you're at that will not open for you. The only reason a door needs a prompt at all. */
const blockedDoor = computed(() => {
  // Read so that a pass running out puts the prompt back — the one input to this that changes
  // without anything having happened. See passClock.
  void passClock.value

  const door = facingDoor.value
  if (!door || !me.value) return null

  return mayPass(locks.value, door.id, me.value.id) ? null : door
})
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
/**
 * The ending you've already read, remembered across reloads.
 *
 * An ended game's row outlives its result card — nothing clears it until somebody proposes the
 * next game — so "Back to the room" clearing `game` in memory lasted exactly until the next
 * fetch. Keyed by when the game ended, which names one particular ending: the next one has a
 * different stamp and shows normally.
 */
const dismissedEndingKey = `space-game-dismissed:${props.channel.id}`
const dismissedEnding = ref<number | null>(
  import.meta.client ? Number(localStorage.getItem(dismissedEndingKey)) || null : null)

/** The panel shows for a proposal, a running game, or an ending worth reading — never a bare cancel. */
const showGamePanel = computed(() => !!game.value && inThisRoom.value
  && (game.value.status !== 'ended' || (
    !!(game.value.state as { winner?: unknown } | null)?.winner
    && game.value.ended_at !== dismissedEnding.value)))
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

/**
 * Which hero you're taking down, asked before a portal opens — and before joining one, since
 * you're seated with whoever you last played either way.
 *
 * 'propose' opens a new run once you've picked; 'join' walks into the one already going.
 */
const heroPrompt = ref<'propose' | 'join' | null>(null)

async function onProposeGame(type: string) {
  const info = gameCatalogue.value.find(g => g.type === type)

  // A duel needs a target first; a room game goes straight to the vote.
  if (info?.mode === 'challenge') {
    challengeType.value = type

    return
  }

  showPropose.value = false

  // The crawl asks who's going before it opens the portal.
  if (type === 'arpg') {
    heroPrompt.value = 'propose'

    return
  }

  await proposeGame(type).catch(() => {})
}

/** The hero is chosen (and selected server-side); now actually open or enter the dungeon. */
async function onHeroChosen() {
  const intent = heroPrompt.value
  heroPrompt.value = null

  if (intent === 'propose') await proposeGame('arpg').catch(() => {})
  else if (intent === 'join') await joinGame().catch(() => {})
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
/** Walk into a game that's already going. Only ever offered when the server says you may. */
async function onJoinGame() {
  // Same question as opening one: which hero is walking through the portal.
  if (game.value?.type === 'arpg') {
    heroPrompt.value = 'join'

    return
  }

  await joinGame().catch(() => {})
}

async function onGameDismiss() {
  // An ended game is just cleared away locally; anything still live is called off for the room.
  if (game.value?.status === 'ended') {
    // Remembered, not merely hidden: the row stays on the server until the next game is
    // proposed, so a hide that lived in memory came straight back on the next load.
    const at = game.value.ended_at
    if (at) {
      dismissedEnding.value = at
      if (import.meta.client) localStorage.setItem(dismissedEndingKey, String(at))
    }
    game.value = null
  }
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
  } else if (!on && was && inThisRoom.value && !editing.value && !dressing.value && !shouting.value) {
    bindKeys()
  }
})

/**
 * A sheet over the room takes the keys with it.
 *
 * The pointer already works this way — a click lands on the editor, not on the floor behind it —
 * and the keyboard has to agree, now that the editor has keys of its own: an arrow that nudged
 * the couch you're holding *and* walked you into a wall behind the sheet is one key doing two
 * jobs for two different people. Given back on close, and only if you're still standing in the
 * room to use them.
 */
watch([editing, dressing, shouting], ([sheet, picker, shout]) => {
  if (sheet || picker || shout) return unbindKeys()
  if (inThisRoom.value && !gameMeeting.value) bindKeys()
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

/**
 * A phone-shaped window, and what the room does about it.
 *
 * The room itself needed nothing to work on a phone — walking has been a tap-and-drag on the
 * canvas since the web build, and "press E" has always had a button beside it. What didn't fit
 * was the furniture around it: nine icon buttons in one header row, and a 224px dock of faces
 * bolted to the side of a 390px screen. So on a narrow window the header keeps only the
 * controls you reach for mid-conversation (mic, and the way out) and folds the rest into a
 * menu, and the dock moves under the room instead of beside it.
 *
 * The question is asked of *this component's own width*, not the window's — which is the fix for
 * a room in a split view. The window there is as wide as it ever was, so the window-width answer
 * was "plenty of room" while the room itself had 40% of it, and the header laid out for a desktop
 * ran the mic, the people button and Leave straight over the room's name. A container measures
 * what's actually there, so a room folds its toolbar whether it's narrow because the phone is or
 * because you docked something beside it.
 *
 * The window's own answer is still honoured as a floor: a phone is narrow before this component
 * has been measured at all, and a first paint with nine buttons in the header is the flash we're
 * avoiding.
 */
const { narrow: windowNarrow } = useNavDrawer()

/** This component's width, watched — see `narrow` below. 0 until the first observation. */
const stageWidth = ref(0)
const root = ref<HTMLElement | null>(null)

/**
 * 640px, not the sidebar's 767: the header is the only thing being folded here, and it fits
 * comfortably in a pane that would still be too narrow to put a sidebar beside.
 */
const narrow = computed(() => windowNarrow.value || (stageWidth.value > 0 && stageWidth.value < 640))

/** The folded-away half of the toolbar, open. Narrow windows only — wide ones show it inline. */
const showMore = ref(false)

/**
 * The people panel, on a narrow window: a sheet over the room rather than a rail beside it.
 *
 * It was a strip under the room to begin with, collapsed so as not to eat the height. That was
 * wrong twice over — it was easy to miss entirely, and half-open it was too short to hold a
 * screen *and* the volume sliders and per-person mutes that are the whole reason the panel
 * exists. As a sheet it gets the room's full area for as long as you're using it, and the room
 * is still there underneath the moment you close it.
 */
const showPeople = ref(false)

/**
 * The same panel on a wide window, where it's a rail beside the room — open unless you've said
 * otherwise, and remembered, because whether you want faces beside the room is a standing
 * preference rather than a per-visit one. Kept apart from `showPeople` so closing the sheet on a
 * phone doesn't also decide the rail is unwanted on the desktop.
 */
const peopleOpen = useLocalStorage('space-people-open', true)

/**
 * How wide that rail is, dragged by its left border and remembered — the faces and their sliders
 * are worth more room on a big screen than the 224px it used to be fixed at.
 */
const { width: peopleWidth, startResize: startPeopleResize } = useResizable(
  'space-people', 224, { min: 180, max: 560, edge: 'left' },
)

/** Whichever of the two is in charge at this width — one thing for the template to ask. */
const peopleShowing = computed(() => (narrow.value ? showPeople.value : peopleOpen.value))

function togglePeople() {
  if (narrow.value) showPeople.value = !showPeople.value
  else peopleOpen.value = !peopleOpen.value
}

watch(narrow, () => {
  showMore.value = false
  showPeople.value = false
})

/** Somebody near you is sharing something — worth a dot on the button that opens the panel. */
const someoneSharing = computed(() => peers.value.some(p => p.screenSharing || p.audioSharing))

/** A menu row when folded away, a bare icon button when the header has room for it. */
const toolClass = computed(() => narrow.value
  ? 'flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm transition-colors hover:bg-muted'
  : 'rounded p-1.5 transition-colors hover:bg-muted')

function fromMenu(run: () => void) {
  showMore.value = false
  run()
}

/** Only a server's owner may move somebody else's microphone or remove them from the room. */
const { server } = useServer()
const canModerate = computed(() =>
  server.value?.id === props.channel.server_id && !!server.value?.is_owner)
/**
 * Whether *we* are currently leading the room, from this tab's point of view.
 *
 * Local, and deliberately not read back off the wire: useSpacePresence drops a summon addressed
 * to its own sender (following yourself is a loop), so the leader is the one person in the room
 * with no server-side answer to "am I doing this". A ref is honest about what it is — the state
 * of this button, on this screen — and the worst it can be wrong about is a second tab, where
 * pressing "follow me" again is harmless.
 */
const leading = ref(false)
const summoning = ref(false)

/** Call the room over, or let it go. One button, because it is one state. */
async function toggleSummon() {
  if (summoning.value) return
  summoning.value = true

  try {
    if (leading.value) await release()
    else await summon()

    leading.value = !leading.value
  }
  catch {
    // Nothing to say: the room carried on as it was, which is visible on screen already.
  }
  finally {
    summoning.value = false
  }
}

/** Are we in *this* room's call, as opposed to some other channel's? */
const inThisRoom = computed(() => inCall.value && activeCallChannel.value === props.channel.id)

// Nothing left to look at in the people sheet once you've walked out.
watch(inThisRoom, (still) => {
  if (still) return

  showPeople.value = false
  // Leading is a thing you do *in* a room. Walking out of the call ends it, and leaves the
  // button in the state somebody coming back would expect.
  leading.value = false
  summonedIds.value = []
})

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
let widthRo: ResizeObserver | undefined
let cssW = 0
let cssH = 0
const route = useRoute()
const camera = reactive<Camera>({ x: 0, y: 0, zoom: 1, width: 0, height: 0 })

/*
 * The room decides how it's drawn, not the viewer — see the note on `projection` in SpaceMap.
 * Watched rather than read in the draw loop because the pointer handlers unproject through the
 * camera too, and a click has to resolve against the view it was aimed at.
 *
 * This also picks up a live rebuild: somebody switching the room to isometric broadcasts a new
 * map, and everyone standing in it turns with it on the next frame.
 */
watchEffect(() => {
  camera.projection = map.value?.projection ?? 'flat'
})

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

    /*
     * You walk in with your microphone on.
     *
     * The opposite of a voice channel, and for the reason the room exists: nobody can hear you.
     * Joining a voice channel puts your voice in everybody's ears at once, so arriving muted is
     * the only polite default. Arriving in a Side Space puts you on a tile — audible to whoever
     * is standing within a couple of squares of it, which at the entrance is usually nobody, and
     * fading to silence a few tiles further out. Walking up to somebody *is* the act of choosing
     * to be heard by them, and having to find a mute button first turns every hello into a
     * fumble.
     *
     * Set after joining, not before, so the state is published to the roster and whispered to
     * the room; doing it first would be a local flag on a call that didn't exist yet, leaving
     * everyone else's sidebar showing the wrong thing. Nothing leaks in the gap either way,
     * because nobody is dialled until a frame has worked out who is near enough.
     *
     * `selfMuted` is global and survives across calls, so this is an explicit unmute rather than
     * an absence of muting: somebody who muted themselves in a meeting an hour ago should not
     * walk into a room silent and wonder why.
     */
    if (selfMuted.value) toggleMute()

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
      shout: p.user.space_shout,
      space_map: p.space_map ?? null,
    })))
    /*
     * …and put yourself back where you were standing, if the room still allows it.
     *
     * Unless you arrived through a doorway, in which case `?at=x,y` says where its far end is and
     * that wins. Coming out of a portal has to beat being restored to where you last stood in
     * this room, or walking through a door would drop you wherever you happened to be the last
     * time you visited — which is the one thing a door is supposed to decide.
     */
    const mine = roster.find(p => p.user.id === user.value?.id)

    /*
     * Back into the room you were actually in, not just onto the tile.
     *
     * A Side Space holds several maps now, and a remembered position is a pair of coordinates
     * *on one of them*. Restoring the tile without the room is how you reload inside the cinema
     * and reappear standing in a wall in the lobby — so the map is opened first, before anybody
     * is placed on it, and everything below then reasons about the right grid.
     *
     * A room that has since been deleted throws, and the catch leaves us in the way in, which
     * is exactly where somebody whose room no longer exists should be.
     */
    if (mine?.space_map && mine.space_map !== mapSlug.value) {
      await openMap(mine.space_map).catch(() => {})
    }

    // Read *after* the map is settled: it validates against the grid you are actually on.
    const arrival = arrivalPoint()

    place(arrival
      ?? (mine && mine.x !== null && mine.y !== null ? { x: mine.x, y: mine.y, facing: mine.facing ?? null } : null))

    /*
     * Whatever doorway you have just been put down in, you did not walk into.
     *
     * Restoring somebody onto the tile they logged out on is the one arrival that can land on a
     * *walked* doorway without any travelling having happened — and an interior's way home stands
     * on its entrance, so this is not a corner case. Without this, coming back to the room you
     * were in would fire the door under you on the first frame and eject you out of it.
     */
    if (map.value && me.value) {
      usedPortal = portalAt(map.value, me.value.x, me.value.y)?.id ?? null
    }

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
  stopWalking()
  stopProximity()
  facingObject.value = null
  facingSeat.value = null
  await disconnect()
}

// --- the room's screens ---

/**
 * The one `<video>` whose frames the map's screens are painted from.
 *
 * It lives in the template but is a pixel across and fully transparent, which is doing two jobs.
 * Painting: `drawImage` copies a video element's *current frame*, so the element only has to be
 * playing, not visible — the room paints the share at whatever rate it is already painting
 * itself, with no second pipeline and nothing to keep in sync. And fullscreen: an element the
 * browser is willing to blow up has to be a real element, and `requestFullscreen` does not care
 * how small it was.
 *
 * `muted` matters more than it looks. The share's *sound* already arrives through the call's
 * audio path, positioned by proximity like everything else, and letting this play it too would
 * play every share twice — once flat, once in the room. Muting is also what lets it autoplay
 * without a click.
 */
const screenEl = ref<HTMLVideoElement | null>(null)

/**
 * Whichever screen the room is watching: somebody else's share, or your own.
 *
 * `sharingPeer` is the call's own answer to "what is on the stage", so the cinema screen and the
 * band of tiles over the room can never disagree about what is playing. Your own share is the
 * fallback, because presenting to a room you are standing in should show you the thing you are
 * presenting — a cinema where the projectionist sees a blank screen is a bug.
 */
const roomScreen = computed(() => sharingPeer.value?.screen ?? screenStream.value ?? null)

/**
 * Does the room under your feet hang a screen?
 *
 * Declared before everything that reads it, because a good deal below is switched off entirely by
 * it: nearly every Side Space has no screen and none of them should be creating video players or
 * holding video elements. It follows the *current* map, so walking into an interior turns it on.
 */
const hasScreens = computed(() => !!map.value?.screens?.length)

/*
 * The room's watch-along, for the screens to paint when nobody is sharing.
 *
 * A separate element from the share, rather than one element with its source swapped: a share is
 * a `srcObject` MediaStream that is always live, and a film is a `src` URL that has to be sought
 * and kept in step with the room. Folding them into one would mean clearing whichever the other
 * left behind on every change, which is the kind of thing that works until somebody starts
 * sharing halfway through a film.
 */
const filmEl = ref<HTMLVideoElement | null>(null)
const {
  film,
  unpaintable: filmUnpaintable,
  playing: filmPlaying,
  speed: filmSpeed,
  targetPosition: filmTarget,
  load: loadFilm,
  subscribe: subscribeFilm,
  unsubscribe: unsubscribeFilm,
  forget: forgetFilm,
} = useRoomFilm(props.channel.id, () => hasScreens.value)

/*
 * Pick the room's player up when you walk into a room that has a screen, and put it down again
 * when you leave one.
 *
 * A watcher rather than a check on mount, and that distinction is the whole of it: a cinema is
 * normally an *interior*, so the map you arrive on has no screen and the one you walk into does.
 * Deciding this once, against whichever map happened to be loaded at mount, meant the screens in
 * every submap stayed dark — the player was never fetched and nothing was ever listening for it.
 *
 * `immediate` so a channel whose main map *does* hang a screen still works: at setup the map
 * isn't loaded yet, so this first run does nothing and the real one fires when it arrives.
 */
watch(hasScreens, async (yes) => {
  if (!yes) return forgetFilm()

  await loadFilm()
  subscribeFilm()
}, { immediate: true })

/**
 * Which picture the screens show, and which element it lives in.
 *
 * A live share wins over the film. Somebody presenting is a deliberate act happening *now*, and
 * a room where a film quietly outranked the person trying to show everyone something would be a
 * room where sharing appears broken.
 */
const activeScreenEl = computed(() => (roomScreen.value ? screenEl.value : filmEl.value))

/**
 * Keep the film where the room is.
 *
 * Called from the frame loop, and cheap: four comparisons and, almost always, no action. Seeking
 * is only forced past a threshold — a `<video>` nudged every frame never plays smoothly, and
 * being a third of a second out is invisible where the stutter would not be.
 */
function syncFilm() {
  const el = filmEl.value
  const source = film.value

  if (!el || !source?.url) return

  if (el.dataset.src !== source.url) {
    el.dataset.src = source.url
    el.src = source.url
  }

  if (el.playbackRate !== filmSpeed.value) el.playbackRate = filmSpeed.value

  const drift = Math.abs(el.currentTime - filmTarget())

  if (filmPlaying.value) {
    if (drift > 1.5) el.currentTime = filmTarget()
    // Autoplay can be refused until this tab has been interacted with. Nothing depends on it —
    // an unstarted film paints as a dark screen, exactly like an empty one.
    if (el.paused) void el.play().catch(() => {})
  }
  else {
    if (drift > 1) el.currentTime = filmTarget()
    if (!el.paused) el.pause()
  }
}

/** Point the element at the current share, and let go of it when there isn't one. */
watch([roomScreen, screenEl], ([stream, el]) => {
  if (!el) return

  el.srcObject = stream ?? null

  // Autoplay can still be refused. Nothing depends on the promise: a screen that never starts
  // paints its name, exactly as an empty one does.
  if (stream) void el.play().catch(() => {})
}, { immediate: true })

/** The screen you're standing in front of — what the "watch fullscreen" prompt hangs off. */
const screenAhead = ref<SpaceScreen | null>(null)

function checkScreen() {
  const m = map.value
  const here = me.value

  const found = m && here && !gameRunning.value ? screenNear(m, here.x, here.y) : null

  if (found?.id !== screenAhead.value?.id) screenAhead.value = found
}

/** Is there actually a picture on it, as opposed to a screen with nothing playing? */
const screenIsLive = computed(() => !!roomScreen.value || !!film.value)

/**
 * Watch what's on the room's screen, full size.
 *
 * Fullscreens the video element rather than the canvas, deliberately. The canvas is the *room*,
 * and blowing that up fills the display with a cinema seen from across a cinema. What you want is
 * the picture.
 *
 * The room carries on running behind it — walking, proximity and the call are all untouched, so
 * coming out of fullscreen puts you exactly where you were, which is the point of watching from
 * inside a room rather than instead of one.
 */
function watchFullscreen() {
  const el = activeScreenEl.value

  if (!el || !screenIsLive.value) return

  /*
   * A film gets its sound; a share does not.
   *
   * The two are genuinely different. A screen share's audio already arrives through the call,
   * positioned by proximity like everybody's voice, so unmuting the element would play it twice.
   * A watch-along's audio arrives with nothing — the widget plays it for whoever joined *that*,
   * and somebody watching from the cinema floor has joined nothing. Sitting down in front of the
   * screen and pressing E is as clear a "yes, play this to me" as the widget's own button.
   *
   * Only in fullscreen. The picture on the wall stays silent, or every room with a screen would
   * be a room playing a film at you from across it.
   */
  if (el === filmEl.value) el.muted = false

  void el.requestFullscreen?.().catch(() => {})
}

/*
 * Out of fullscreen, the film goes quiet again.
 *
 * Bound to the event rather than to the button that opened it, because fullscreen ends in ways
 * this component never hears about otherwise — Escape, the window manager, another element
 * claiming it. A film still audible after the overlay has gone is a room with a ghost in it.
 */
function onFullscreenChange() {
  if (!document.fullscreenElement && filmEl.value) filmEl.value.muted = true
}

// --- the gallery ---

/**
 * The frame you're standing at, and the painting open over the room.
 *
 * A frame is the museum equivalent of a screen: something across the room you look at rather than
 * something under your feet. So it is checked the same way, prompts the same way, and — like the
 * screen — only offers when there is actually something to see. An empty frame is a job somebody
 * hasn't finished, not an interaction.
 */
const exhibitAhead = ref<SpaceExhibit | null>(null)
const viewingPiece = ref<ExhibitPiece | null>(null)

function checkExhibit() {
  const m = map.value
  const here = me.value

  const found = m && here && !gameRunning.value ? exhibitNear(m, here.x, here.y) : null

  if (found?.id !== exhibitAhead.value?.id) exhibitAhead.value = found
}

/** What's hanging in the frame you're at, if anything has been. */
const pieceAhead = computed(() => {
  const m = map.value
  const frame = exhibitAhead.value

  return m && frame ? pieceIn(m, frame.id) : null
})

/**
 * Open the painting you're standing at.
 *
 * The room carries on behind it — the call, everyone's walking, proximity — so closing puts you
 * back exactly where you were standing. That is what makes a gallery worth walking around rather
 * than a list of pictures with a map attached.
 */
function viewExhibit() {
  if (pieceAhead.value) viewingPiece.value = pieceAhead.value
}

// --- doorways ---

/**
 * Where a `?at=x,y` in the URL says to arrive, if it is somewhere you can actually stand.
 *
 * Read once and then wiped from the address bar, because it describes *this arrival* and not the
 * page: left in place, a reload an hour later would pick you up and put you back at the door, and
 * the link would be a trap rather than a shortcut.
 *
 * Validated against the map like any other position. It arrives in a URL, so it is untrusted in
 * the ordinary way — a hand-typed `?at=9999,9999` should be ignored, not walk somebody into the
 * void.
 */
function arrivalPoint(): { x: number, y: number, facing: null } | null {
  const raw = route.query.at
  if (typeof raw !== 'string' || !map.value) return null

  const [x, y] = raw.split(',').map(Number)

  void navigateTo({ path: route.path, query: { ...route.query, at: undefined } }, { replace: true })

  if (!Number.isFinite(x) || !Number.isFinite(y) || !isWalkable(map.value, x!, y!)) return null

  return { x: x!, y: y!, facing: null }
}

/**
 * The wormhole you are standing in, if any, and what taking it would mean.
 *
 * ## Two ways in, chosen per doorway
 *
 * A doorway is either `walk` — it takes you the moment you are inside it — or `press`, where
 * standing in it only offers, and E accepts. See {@link activationOf}.
 *
 * Both exist because the right answer is genuinely different per door. Running between two
 * halves of one island wants walking: a key at every wormhole makes crossing the map a chore.
 * Leaving the room wants a press, because one careless step should not end the conversation you
 * were in.
 *
 * ## The two traps that only `walk` has
 *
 * A walked doorway fires on *being inside it*, which is a state, and this is checked every frame
 * — so without a memory it fires sixty times a second. And if two doorways face each other,
 * arriving at one puts you inside it and it sends you straight back.
 *
 * `usedPortal` answers both: it remembers the doorway you arrived in and clears only when you
 * step out of it. So a doorway you walk into takes you once, and the one you come out of stays
 * quiet until you leave and come back. A pressed doorway needs none of this — nothing fires from
 * a state — which is why arriving in one is simply standing at the way back.
 */
let usedPortal: string | null = null

/** The doorway under your feet that E would take. Null for a walked one — it needs no prompt. */
const portalHere = ref<SpacePortal | null>(null)
const travelling = ref(false)

/**
 * Why the last attempt to step through a wormhole did nothing.
 *
 * A doorway that fails has to *say* so. A keypress that silently does nothing is
 * indistinguishable from a key that isn't bound, and the cases that produce it — a door pointing
 * at a deleted room, or one whose exit was never set — are exactly the ones the person standing
 * there cannot diagnose.
 *
 * Cleared by stepping out of the doorway, so the message belongs to the door you are at.
 */
const travelProblem = ref('')

function checkPortal() {
  const m = map.value
  const here = me.value

  if (!m || !here || gameRunning.value || travelling.value) return

  const found = portalAt(m, here.x, here.y)

  if (!found) {
    usedPortal = null
    if (portalHere.value) portalHere.value = null
    travelProblem.value = ''

    return
  }

  // A doorway you press: it only ever offers. The prompt hangs off this, so it is written only
  // when it changes — this runs sixty times a second.
  if (activationOf(found) === 'press') {
    if (found.id !== portalHere.value?.id) {
      portalHere.value = found
      // Whatever went wrong belonged to the doorway you were stood in. Walking to another one is
      // the end of it.
      travelProblem.value = ''
    }

    return
  }

  // A doorway you walk into. Nothing to prompt — it is already happening.
  if (portalHere.value) portalHere.value = null

  if (found.id === usedPortal) return
  usedPortal = found.id

  void travelThrough(found)
}

/**
 * Go through a doorway.
 *
 * Three destinations, and they could hardly be less alike underneath — they differ by how much
 * is torn down on the way through:
 *
 *  - A **point** on this map is a `warp`. The map is already loaded; the only thing that changes
 *    is where you are standing.
 *  - An **interior** is a new grid and nothing else. Same channel, same call, same presence
 *    channel, same people on the other end of it — you do not leave, so there is no teardown to
 *    do and no silence to sit through. This is what makes a door feel like a door.
 *  - Another **room** is a navigation: a different channel, a different call. Walking out is done
 *    properly rather than by changing the URL underneath a live room — the arrival point rides in
 *    the query string, which is also what makes that doorway shareable as a link.
 */
async function travelThrough(portal: SpacePortal) {
  if (travelling.value) return

  if (portal.to.kind === 'point') {
    /*
     * A doorway whose exit is inside itself.
     *
     * This is what a same-map doorway looks like *before its exit has been chosen*: the editor
     * parks a new one on its own entrance, so that an interrupted edit leaves a map that still
     * saves, and the next click on the canvas is meant to set the real exit. Somebody who drags
     * one out and never makes that click saves a portal that leads to where it already is.
     *
     * Warping there does happen — it just moves you a fraction of a tile — which is the worst
     * possible outcome, because it is indistinguishable from the key not working at all. Saying
     * so is the whole fix; the editor is where it gets finished.
     */
    if (portalAt(map.value!, portal.to.x, portal.to.y)?.id === portal.id) {
      travelProblem.value = 'This doorway has no exit set yet — finish it in the editor.'

      return
    }

    warp(portal.to.x, portal.to.y)
    // Marked as used at the far end too, so a doorway whose exit sits inside another walked one
    // doesn't immediately fire that one as well and bounce you down a chain of them.
    usedPortal = portalAt(map.value!, portal.to.x, portal.to.y)?.id ?? null

    return
  }

  if (portal.to.kind === 'map') {
    await enterInterior(portal.to)

    return
  }

  travelling.value = true

  try {
    // Leave the room the way the Leave button does, rather than letting the page change under a
    // live call: the peer connections, the presence channel and the mic all have teardown, and
    // skipping it leaves a ghost of you standing in the room you walked out of.
    await leave()

    const at = portal.to.x != null && portal.to.y != null
      ? { at: `${portal.to.x},${portal.to.y}` }
      : {}

    await navigateTo({
      path: `/servers/${props.channel.server_id}/channels/${portal.to.channel_id}`,
      query: at,
    })
  }
  finally {
    travelling.value = false
  }
}

/**
 * Where you come out, on the far side of a doorway between two of this channel's maps.
 *
 * Three answers, in the order they are worth having:
 *
 * 1. **A tile the doorway names.** An author who set an exit meant it, so it wins — but it is
 *    checked against the map as it is *now*. The two maps are saved by separate requests, so an
 *    interior can be rebuilt under a door that points into it and the named tile can be a wall by
 *    the time somebody walks through.
 *
 * 2. **The doorway back the other way.** If the room we are arriving in has a door into the room
 *    we just left, we come out standing in it — which is what makes leaving a submap put you back
 *    at its entrance in the overworld rather than at the building's front door. This is the
 *    common case, because it is what the auto-created way home relies on: it deliberately stores
 *    *no* coordinates, so that moving the doorway in the overworld moves where you come back out,
 *    with nothing to keep in step.
 *
 * 3. **The room's own entrance**, when there is no door back — a room reached from two places, or
 *    one whose doorway hasn't been saved yet.
 *
 * Note that arriving *inside* a doorway is fine and expected here. The caller marks it used, so a
 * walked one doesn't fire on the frame you land and send you straight back where you came from.
 */
function arrivalIn(next: SpaceMap, from: string, to: { x?: number | null, y?: number | null }) {
  if (to.x != null && to.y != null && isWalkable(next, to.x, to.y)) {
    return { x: to.x, y: to.y }
  }

  const back = doorwayInto(next, from)
  const inDoorway = back ? standableIn(next, back) : null

  return inDoorway ?? spawnPoint(next)
}

/**
 * Step into one of this channel's other rooms.
 *
 * `travelling` is held for the fetch, which is the one thing here that can take a moment. It
 * stops the frame loop firing this doorway again while the new grid is in the air — and it also
 * stops you *walking* meanwhile, which matters more than it sounds: your keys are still bound
 * and a step taken against the old map, landing after the new one arrives, is a step into
 * whatever happens to be at those coordinates in the new room.
 *
 * The order at the far end is deliberate. The map is swapped first, then you are placed on it,
 * and only then is the doorway remembered — each of those reads the one before it.
 */
async function enterInterior(to: { map: string, x?: number | null, y?: number | null }) {
  travelling.value = true

  // Which room we are leaving, read before the swap — it is what the far side is searched for.
  const from = mapSlug.value

  try {
    const next = await openMap(to.map)

    const at = arrivalIn(next, from, to)

    /*
     * Arriving *on* the way back is the intended shape rather than a hazard: a new interior is
     * created with a wormhole home standing at its entrance, so you come out of one door already
     * stood in the other.
     *
     * Which is exactly why the far end has to be marked used. If that way home is a *walked*
     * doorway, landing on it would otherwise fire it on the very next frame and send you
     * straight back out — a door that bounces you off itself. Read against the new map, since
     * `usedPortal` means "on whichever grid I am now on".
     */
    warp(at.x, at.y)
    usedPortal = portalAt(next, at.x, at.y)?.id ?? null
  }
  catch {
    /*
     * The room isn't there — deleted since the door was hung — or the read failed.
     *
     * You stay exactly where you were, and the doorway says why. Nothing retries by itself,
     * because nothing fires by itself any more; pressing E again is the retry, which is what you
     * want if the failure was the network rather than the room.
     */
    travelProblem.value = 'That room isn\'t there any more.'
  }
  finally {
    travelling.value = false
  }
}

// --- the frame loop ---

function loop(now: number) {
  frame = requestAnimationFrame(loop)

  // Clamped: a backgrounded tab resumes with a huge gap, and without this everybody would
  // teleport the width of however long you were away.
  const dt = Math.min(0.1, lastAt ? (now - lastAt) / 1000 : 0)
  lastAt = now

  if (inThisRoom.value) {
    /*
     * Doors first, before anybody moves.
     *
     * `tick` is what walks people, and walking asks whether a tile is passable — so the doors
     * have to have already decided for this frame. Get the order wrong and you spend one frame
     * per approach walking into a door that was about to open, which reads as the door sticking.
     */
    syncDoors(map.value, occupants.value, locks.value)

    // And the moment a password pass runs out, tell the prompt. The doors have already acted on
    // it a line above; this is only so the label can explain why one just shut.
    if (nextPassExpiry.value !== null && Date.now() >= nextPassExpiry.value) passClock.value = Date.now()

    // A meeting freezes the room — nobody walks, so movement isn't ticked; everything else runs
    // so the countdown and the voice keep going.
    if (!gameMeeting.value) tick(dt)

    // Our *own* movement, which nothing whispers to us. Proximity has its own clock (and its own
    // reasons — see useSpaceProximity), and this only ever brings the next evaluation forward:
    // it throttles itself, so a 144Hz monitor doesn't decide the room's audio 144 times a second.
    proximityMoved()

    checkFurniture()
    checkGame()
    checkPortal()
    checkScreen()
    checkExhibit()
  }

  /*
   * Outside the in-the-room guard, like the effects below.
   *
   * What is on the room's screen has nothing to do with whether *you* have joined the call —
   * somebody looking at the space without joining it should still see the film playing on the
   * wall, and the element has to be kept in step for that to be true.
   */
  syncFilm()

  // Outside the `inThisRoom` guard on purpose: an effect that started while you were standing in
  // the room has to be able to finish (and be cleared) after you've left it.
  sweepEffects()

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
    fireEffect: announceOnce,
    meeting: () => gameMeeting.value,
    quiet: () => gameRunning.value,
  })
}

/**
 * The room's configured fanfare, minus the news it has already given.
 *
 * Two things now notice people coming and going, and they are *not* the same event: the room
 * draws a puff of light where somebody walked in, and proximity fires the channel's chosen
 * effect when somebody comes within earshot. Usually those are minutes apart — someone crosses
 * the room to talk to you — and both are worth having.
 *
 * But everybody arrives at the same entrance, so if you happen to be standing near it the two
 * land within a frame of each other: a puff at their feet and a screenful of fireworks about the
 * same person. So an arrival the room has *just* announced doesn't get announced again. Reading
 * it off the live effects list is exact rather than approximate — that list is precisely "who the
 * room has said something about lately", and it empties itself.
 */
function announceOnce(phase: 'join' | 'leave', id: number, name: string) {
  const kind = phase === 'join' ? 'arrive' : 'depart'
  if (effects.some(e => e.id === id && e.kind === kind)) return

  fireEffect(phase, id, name)
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

  // A seat is only worth offering when there isn't something more interesting under your nose:
  // stand at the arcade cabinet and E should play it, not sit you on the stool beside it.
  const seat = m && self && !gameRunning.value && !found && !seated.value
    ? seatInFront(m.objects, self)
    : null

  if (seat?.id !== facingSeat.value?.id) facingSeat.value = seat

  checkNeighbour()
}

/**
 * Who's standing close enough to reach, if anybody.
 *
 * Nearest wins, and nobody at all during a game — in a round of Among Us, "hold hands with the
 * person next to you" is a prompt that would either give somebody away or get them killed.
 */
function checkNeighbour() {
  const self = me.value
  let found: { id: number, name: string } | null = null

  if (self && !gameRunning.value) {
    let best = HOLD_REACH

    for (const o of Object.values(others.value)) {
      const d = Math.hypot(o.x - self.x, o.y - self.y)
      if (d < best) { best = d; found = { id: o.id, name: o.name } }
    }
  }

  if (found?.id !== nearestPerson.value?.id) nearestPerson.value = found
}

/**
 * Reach for the nearest hand, or let go of the one you're holding.
 *
 * One gesture for both, because in the room they are the same gesture: your hand is either in
 * somebody's or it isn't. Offering is a *request* — see `offerHand`, and the reason it has to
 * be one is that holding hands tows the other person about.
 */
function toggleHand() {
  if (holdingWith.value) return letGo()
  if (nearestPerson.value) offerHand(nearestPerson.value.id)
}

/** The label on the prompt — "Press E to put something on". */
const interactHint = computed(() => {
  if (seated.value) return 'Get up'

  // Named, because "step through" is only half an answer — a room full of wormholes is a room
  // where the useful question is which one, and the name is the only thing that can say.
  if (portalHere.value) return `Step through to ${portalHere.value.name}`

  // Only when something is actually playing. Offering to enlarge a blank screen is an offer that
  // makes the prompt worth less everywhere it means something.
  if (screenAhead.value && screenIsLive.value) return 'Watch fullscreen'

  /*
   * Standing at a screen showing something that cannot be painted.
   *
   * Not an offer — there is nothing E can do here — but the prompt is the only place the room
   * can explain itself. A dark screen while everybody's video widget plays a YouTube link reads
   * as broken, and "open the player" is the actual answer.
   */
  if (screenAhead.value && filmUnpaintable.value) return 'Playing in the video player — press E on the TV'

  // Named, because a wall of paintings is a wall of prompts otherwise, and which one you are
  // standing at is the only useful thing the prompt can tell you.
  if (pieceAhead.value) return `Look at ${pieceAhead.value.title}`

  const kind = facingObject.value ? decorKind(facingObject.value.kind) : null
  if (kind) return `${kind.verb ?? 'Use'} the ${kind.label.toLowerCase()}`

  const seat = facingSeat.value ? decorKind(facingSeat.value.kind) : null

  return seat ? `Sit on the ${seat.label.toLowerCase()}` : ''
})

/** Whether E has anything to do where you're standing — what the prompt hangs off. */
const hasPrompt = computed(() => seated.value
  || !!portalHere.value
  || (!!screenAhead.value && (screenIsLive.value || filmUnpaintable.value))
  || !!pieceAhead.value
  || !!facingObject.value
  || !!facingSeat.value)

/**
 * Sit down, or get up again.
 *
 * Nothing here reaches the server. A seat is a *local* posture — where you are was already being
 * whispered several times a second, and which couch you're on rides along with it — so sitting
 * is as cheap as walking and needs no more permission than walking does. Contrast
 * {@link useFurniture}, which asks the server precisely because opening the room's music player
 * is a claim about a thing the channel owns.
 */
function toggleSeat() {
  if (seated.value) return stand()

  const m = map.value
  const self = me.value
  const object = facingSeat.value
  const kind = object ? decorKind(object.kind) : null
  if (!m || !self || !object || !kind) return

  sit(object.id, seatOn(object, kind, self))
}

/**
 * Use it.
 *
 * The server decides what a given object opens, from its own copy of the map — we send an id and
 * get back a door to something the channel already has. Which door depends on the app behind the
 * furniture, and both kinds land on the same floating shelf:
 *
 *   - a **widget** app answers with the *channel's* widget of that type: the same music player
 *     `m!` would have made, the same one everybody else at the speaker is looking at.
 *   - a **surface** app (the whiteboard, the lectern, the wall planner, the filing cabinet)
 *     answers with a name, and the room floats the Side Desk panel itself — the very window
 *     `a!board` opens. Draw on the whiteboard in here and it's on the Board tab out there,
 *     because they were never two boards.
 *
 * So a room gains no state of its own, which is why listening along, the queue, permissions and
 * every app's own live syncing all work in here without knowing this exists.
 */
async function useFurniture() {
  const object = facingObject.value
  if (!object || using.value || !inThisRoom.value) return

  using.value = true

  try {
    /*
     * Named with the map you are standing on.
     *
     * The server looks the object up in the map it resolves, and a Side Space holds several — so
     * without this, pressing E on the cinema's screen would have the server hunt for that id in
     * the lobby's furniture and 404.
     */
    const res = await api<SpaceInteraction>(`/api/channels/${props.channel.id}/space/interact?map=${encodeURIComponent(mapSlug.value)}`, {
      method: 'POST',
      body: { object_id: object.id },
    })

    const kind = decorKind(object.kind)

    if (res.type === 'app') {
      openWindow({
        kind: 'surface',
        app: res.app,
        basePath: `/api/channels/${props.channel.id}`,
        streamName: `channel.${props.channel.id}`,
        // Anybody standing in the room is a member of the channel it belongs to, and a member
        // may author on its desk — the same reason the desk panel hard-codes this.
        canEdit: true,
        title: kind?.label ?? deskApp(res.app)?.label ?? 'App',
      })

      return
    }

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

// --- walking with a pointer ---

/**
 * Point at the floor to walk there. The room's other set of controls, and on a phone browser its
 * only one.
 *
 * There are no arrow keys on a touchscreen and no on-screen pad in the web build, which made a
 * Side Space in a mobile browser a room you could look at and not move in. So the canvas takes
 * pointers as well, in one gesture that reads as two:
 *
 *   - **Tap** somewhere and you walk to it, then stop. Nothing to hold.
 *   - **Press and drag** and you steer continuously, thumbstick-fashion, because the target is
 *     rewritten under your finger; letting go of a drag stops you where you are.
 *
 * Which of the two it was is decided on release by how far the pointer travelled ({@link DRAG_TILES}),
 * so neither gesture has to be declared up front. Deliberately *not* a click handler: a pointer
 * gives us the drag for free, works for mouse, pen and finger alike, and `setPointerCapture` keeps
 * a drag that wanders off the canvas (over the dock, out of the window) attached to the room.
 *
 * The keyboard still wins whenever a key is down — see moveSelf.
 */
const DRAG_TILES = 0.9
/** The pointer currently steering, where it went down, and how far it has been since. */
let steering: { id: number, from: { x: number, y: number }, moved: number, person: number | null } | null = null

/** Where in the room a pointer is, in tiles. */
function pointerWorld(e: PointerEvent) {
  const el = canvas.value
  if (!el) return null

  const box = el.getBoundingClientRect()

  return toWorld(camera, e.clientX - box.left, e.clientY - box.top)
}

function onPointerDown(e: PointerEvent) {
  // Every pointer on the canvas is tracked, walking or not, because two of them are a pinch and
  // the second one has to be recognised as such wherever it lands.
  touches.set(e.pointerId, { x: e.clientX, y: e.clientY })
  // Captured immediately, and for *every* pointer rather than only the one that's steering: a
  // finger lifted off the edge of the canvas would otherwise never deliver its pointerup, and
  // the phantom left behind in `touches` would make the next single tap look like a pinch.
  canvas.value?.setPointerCapture(e.pointerId)

  if (touches.size > 1) return startPinch()

  // Not standing in the room, or a sheet is over it: walking isn't ours to do. (A right-click or
  // a middle-click isn't a walk either.)
  if (!inThisRoom.value || editing.value || dressing.value || shouting.value || gameMeeting.value) return
  if (e.button !== 0 && e.pointerType === 'mouse') return

  const at = pointerWorld(e)
  if (!at) return

  // Who, if anyone, was under the pointer when it went down. Settled here rather than on release
  // because by then they may have taken a step, and a tap should land on the person you aimed at.
  steering = { id: e.pointerId, from: at, moved: 0, person: personAt(at) }
  walkTo(at.x, at.y)
}

/**
 * The person standing at a point, or null. Yourself never counts.
 *
 * Generous — nearly a whole tile — because a sprite is drawn taller than the tile it stands on
 * and people aim at the *figure*, which is mostly above their feet. Nearest wins, so two people
 * on adjacent tiles resolve to whichever you were closer to rather than to whoever the roster
 * happens to list first.
 */
function personAt(at: { x: number, y: number }): number | null {
  let best: { id: number, d: number } | null = null

  for (const o of Object.values(others.value)) {
    // Aimed a little above the feet, which is where the sprite actually is.
    const d = Math.hypot(o.x - at.x, o.y - 0.35 - at.y)
    if (d < 0.8 && (!best || d < best.d)) best = { id: o.id, d }
  }

  return best?.id ?? null
}

function onPointerMove(e: PointerEvent) {
  if (touches.has(e.pointerId)) touches.set(e.pointerId, { x: e.clientX, y: e.clientY })

  if (pinch) return trackPinch()
  if (!steering || e.pointerId !== steering.id) return

  const at = pointerWorld(e)
  if (!at) return

  steering.moved = Math.max(steering.moved, Math.hypot(at.x - steering.from.x, at.y - steering.from.y))
  walkTo(at.x, at.y)
}

function onPointerUp(e: PointerEvent) {
  touches.delete(e.pointerId)
  // Guarded: this also runs for `pointercancel`, where the browser has already taken the capture
  // back and releasing it again throws.
  if (canvas.value?.hasPointerCapture(e.pointerId)) canvas.value.releasePointerCapture(e.pointerId)

  // Lifting either finger ends the pinch. The other one is not promoted to a walk — a hand
  // half-way off the glass shouldn't send you marching across the room.
  if (pinch && touches.size < 2) {
    pinch = null
    steering = null

    return
  }

  if (!steering || e.pointerId !== steering.id) return

  const dragged = steering.moved > DRAG_TILES
  const person = steering.person
  steering = null

  // A drag was steering, so releasing means stop. A tap was an instruction, so it stands and we
  // carry on walking to it — which is the whole point of being able to tap the far side of a room.
  if (dragged) return stopWalking()

  // A tap that landed on somebody is a different instruction from a tap on the floor: go *to
  // them*, and keep going as they move. See `approach` — the target is a person, not a tile.
  if (person !== null) approach(person)
}

// --- how close you're standing to it ---

/**
 * How much of the room to show, as a multiple of the size the room picks for itself.
 *
 * The room already scales itself to the panel it's in ({@link draw}), and that stays true — this
 * only says how much you'd like to overrule it by, which is why it's a multiplier rather than a
 * zoom. Remembered per person because it's a preference about eyesight and screen size rather
 * than about this room: whatever you set is what every room opens at next time.
 */
const ZOOM_KEY = 'space:zoom'
const zoomLevel = ref(1)

/** Every pointer currently on the canvas — one is a walk, two are a pinch. */
const touches = new Map<number, { x: number, y: number }>()
/** A pinch in progress: the gap between the fingers, and the zoom when they went down. */
let pinch: { gap: number, from: number } | null = null

function zoomBy(factor: number) {
  zoomLevel.value = clampZoom(zoomLevel.value * factor)
  if (import.meta.client) localStorage.setItem(ZOOM_KEY, String(zoomLevel.value))
}

/**
 * The wheel zooms rather than scrolls.
 *
 * The room is a viewport, not a document — there is nothing under it to scroll to, and a wheel
 * that scrolled the page out from under a room you were walking in would be the worse of the two
 * behaviours. `passive: false` (see the listener) is what lets us say so.
 */
function onWheel(e: WheelEvent) {
  e.preventDefault()
  zoomBy(e.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP)
}

/** Two fingers went down: stop walking, and remember the gap to measure the rest against. */
function startPinch() {
  const [a, b] = [...touches.values()]
  if (!a || !b) return

  pinch = { gap: Math.hypot(a.x - b.x, a.y - b.y), from: zoomLevel.value }
  // A pinch is not a walk, and the finger that started as one has to stop being it — otherwise
  // you zoom and set off across the room at the same time.
  steering = null
  stopWalking()
}

function trackPinch() {
  const [a, b] = [...touches.values()]
  if (!pinch || !a || !b) return

  const gap = Math.hypot(a.x - b.x, a.y - b.y)
  if (pinch.gap < 20) return

  zoomLevel.value = clampZoom(pinch.from * (gap / pinch.gap))
  if (import.meta.client) localStorage.setItem(ZOOM_KEY, String(zoomLevel.value))
}

/**
 * E, or Enter, to use what you're standing at.
 *
 * Bound here rather than in useSpacePresence because it belongs to the *stage* — the movement
 * keys are unbound when the room leaves the screen and so is this, for the same reason: E should
 * type an E when you're reading another channel.
 */
function onInteractKey(e: KeyboardEvent) {
  const key = e.key.toLowerCase()
  if (key !== 'e' && key !== 'h' && e.key !== 'Enter') return
  if (!inThisRoom.value) return
  // A sheet is open over the room — the editor, or the picker. Neither is a place where a
  // letter should reach past it and switch the telly on.
  if (editing.value || dressing.value || shouting.value || askingDoor.value || viewingPiece.value) return

  const el = e.target as HTMLElement | null
  if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)) return

  /*
   * H is the other person's key, and it stays out of E's way entirely.
   *
   * Its own binding rather than another branch of the E ladder because E is already four things
   * deep — a door, a seat, a machine, a task — and each one of those is about a *place*. Who is
   * standing next to you is a different question and deserves a different key, or the answer to
   * "what does E do here" becomes genuinely unpredictable.
   */
  if (key === 'h') {
    // An offer waiting on you is what H means first: it's the one that would otherwise need
    // the mouse, and taking an offered hand is the same gesture as offering one.
    if (handOffer.value) { e.preventDefault(); acceptHand(); return }
    if (holdingWith.value || nearestPerson.value) { e.preventDefault(); toggleHand() }

    return
  }

  // During a game, E does the game: a task at your feet, or a body to report. Nothing else, so
  // that "press E" always means the one thing worth doing where you're standing.
  if (gameRunning.value) {
    if (gameNearTaskId.value) { e.preventDefault(); void onDoTask() }
    else if (gameNearBody.value) { e.preventDefault(); void onReportOrMeeting() }

    return
  }

  // A door you could say the words at answers E before anything else does: you're standing in
  // its doorway, and there is nothing else that press could sensibly mean there.
  if (blockedDoorTakesPassword.value && blockedDoor.value) {
    e.preventDefault()
    askingDoor.value = blockedDoor.value.id

    return
  }

  // Getting up comes first: while you're sitting, E is the way off the couch and nothing else.
  if (seated.value) { e.preventDefault(); toggleSeat(); return }

  /*
   * A wormhole under your feet beats the furniture in front of you.
   *
   * They can genuinely coincide — a doorway is a region and a couch can stand at the edge of one
   * — and when they do, the wormhole is what you walked onto and the couch is what happened to be
   * there. Ordering it this way also means the answer to "what does E do here" is the same
   * wherever you stand in a doorway, rather than changing with which way you happen to be facing.
   */
  if (portalHere.value) { e.preventDefault(); void travelThrough(portalHere.value); return }

  /*
   * A screen with something on it, in front of you.
   *
   * Below the doorway because a doorway is under your feet and this is across the room, and above
   * the furniture because sitting down in a cinema and then pressing E should make the film
   * bigger — the couch you are facing is not what you came in for. Only when something is
   * playing, so a dark screen leaves E to whatever else is around.
   */
  if (screenAhead.value && screenIsLive.value) { e.preventDefault(); watchFullscreen(); return }

  // A painting you're standing at. Below the screen because a room has one screen and a gallery
  // has eighty frames, so the screen is the more specific place to be standing.
  if (pieceAhead.value) { e.preventDefault(); viewExhibit(); return }

  if (facingObject.value) { e.preventDefault(); void useFurniture(); return }

  if (facingSeat.value) { e.preventDefault(); toggleSeat() }
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

/**
 * A new shout, or none — saved by the dialog and now worn, exactly like a new look.
 *
 * Same division of labour as {@link onDressed}: the dialog owns the write, the stage owns the
 * sprite standing in the room, which would otherwise keep the old bubble until the roster was
 * next rebuilt.
 */
function onShouted(shout: string | null) {
  shouting.value = false
  reshout(shout)
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
    // Amber against the room's indigo: a stage is the one rectangle you can step into by
    // accident and start broadcasting, so it doesn't share a colour with anything else.
    stage: 'rgb(245 158 11 / 0.14)',
    stageBorder: 'rgb(217 119 6 / 0.75)',
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
  // Zoom out a little on a short stage so a squat panel still shows a useful slice of room, then
  // apply however much the person watching has overruled that by. Recomputed every frame rather
  // than watched, because the first half depends on the panel's height and the second on a
  // pinch that may be in progress.
  camera.zoom = Math.max(0.6, Math.min(1.4, camera.height / (TILE * 16))) * zoomLevel.value

  ctx.clearRect(0, 0, camera.width, camera.height)
  // Everything outside the room, so a map with void round it reads as a place rather than as a
  // hole in the page.
  ctx.fillStyle = OUTSIDE
  ctx.fillRect(0, 0, camera.width, camera.height)

  // The share, if there is one — painted onto whatever screens this map hangs. See drawScreens.
  drawMap(ctx, m, camera, palette, (performance.now() - openedAt) / 1000, activeScreenEl.value)

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
  // Before the cast, so an arm passes behind both the people it joins rather than across them.
  // It's the one thing in the room that belongs to a *pair*, so it has nowhere in a list sorted
  // by one person's depth to go.
  drawHandLinks(ctx)

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

  // Arrivals and departures are cast members too, sorted by where they happened — an effect at
  // the top of the room has to go *behind* somebody standing at the bottom of it, exactly as a
  // person would. (A departure's own sprite is drawn by the effect, since by now they're gone
  // from the roster above.)
  for (const effect of effects) cast.push({ y: effect.y, paint: () => paintEffect(ctx, effect) })

  cast.sort((a, b) => a.y - b.y)
  for (const item of cast) item.paint()

  // The "press E" prompt is drawn over the room rather than in the DOM, because it belongs to a
  // *tile* — pinning an HTML bubble to a moving world-space position means a transform update
  // every frame, which is the one thing canvas is already doing.
  drawPrompt(ctx)
  drawHandPrompt(ctx)
  drawLocks(ctx)
}

/**
 * A padlock over every locked door.
 *
 * Drawn for locked doors you *can't* pass only. A door you hold the key to opens as you reach it
 * and needs no explaining; badging it too would put a padlock on half the room and teach people
 * to ignore padlocks. This way the symbol means exactly one thing: that one won't open for you.
 *
 * Above the cast rather than among it, because it's a label about the world rather than a thing
 * in it — a person standing in the doorway should not hide the reason they're in your way.
 */
function drawLocks(ctx: CanvasRenderingContext2D) {
  const m = map.value
  const self = me.value
  if (!m || !self || !inThisRoom.value) return

  const size = TILE * camera.zoom

  for (const object of m.objects ?? []) {
    const kind = decorKind(object.kind)
    if (!kind?.door || mayPass(locks.value, object.id, self.id)) continue

    const across = decorSize(object, kind).w
    const p = toScreen(camera, object.x + (across - 1) / 2, object.y - 0.45)
    const r = size * 0.2

    ctx.save()
    ctx.beginPath()
    ctx.arc(p.x, p.y, r, 0, Math.PI * 2)
    ctx.fillStyle = 'rgb(23 23 30 / 0.85)'
    ctx.fill()

    // A shackle and a body — a padlock reads at this size, where a key or a cross doesn't.
    ctx.strokeStyle = '#f4d06f'
    ctx.lineWidth = Math.max(1, size * 0.045)
    ctx.beginPath()
    ctx.arc(p.x, p.y - r * 0.18, r * 0.34, Math.PI, 0)
    ctx.stroke()

    ctx.fillStyle = '#f4d06f'
    ctx.fillRect(p.x - r * 0.46, p.y - r * 0.16, r * 0.92, r * 0.72)
    ctx.restore()
  }
}

// --- coming and going ---

/**
 * The arrivals and departures currently playing.
 *
 * A plain array, deliberately not reactive: it's written by a listener and read by the frame
 * loop, and nothing in the DOM depends on it — making it a `ref` would wake Vue several times a
 * second to tell it about something only the canvas can see.
 *
 * Bounded, because a room can empty all at once (a server restart drops everybody's socket) and
 * forty simultaneous effects is a dropped frame rate for the one person still standing there.
 * The oldest go first, which is also the ones nearest finishing.
 */
const effects: RoomEffectInstance[] = []
const MAX_EFFECTS = 8

function addEffect(event: RoomEvent) {
  // A game owns the room's attention, and a puff of light at the far end is a tell about who's
  // where — the same reason the proximity chime stands down during one.
  if (gameRunning.value) return

  effects.push({ ...event, born: performance.now() })
  if (effects.length > MAX_EFFECTS) effects.splice(0, effects.length - MAX_EFFECTS)
}

/** Draw one, and retire it when its time is up. Ages are read from one clock, in the frame. */
function paintEffect(ctx: CanvasRenderingContext2D, effect: RoomEffectInstance) {
  const size = TILE * camera.zoom
  const p = toScreen(camera, effect.x, effect.y)
  const t = Math.min(1, (performance.now() - effect.born) / EFFECT_MS)

  // Dimmed by earshot, exactly as the person it's about would have been: somebody arriving at
  // the far end of the room is a glimmer, somebody arriving beside you is an event. Your own is
  // always at full strength — you're the one it happened to.
  const strength = effect.self || !map.value || !me.value
    ? 1
    : 0.35 + audibility(map.value, me.value, effect) * 0.65

  drawRoomEffect(ctx, effect, p.x, p.y, size, t, strength)
  drawRoomEffectLabel(ctx, effect, p.x, p.y, size, t, strength)
}

/** Drop the finished ones. Done once a frame rather than on a timer per effect. */
function sweepEffects() {
  const now = performance.now()

  for (let i = effects.length - 1; i >= 0; i--) {
    if (now - effects[i]!.born >= EFFECT_MS) effects.splice(i, 1)
  }
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
  // Sitting has no object to hang a tag over — you are *on* the thing — so the seated prompt is
  // the button below the room and nothing here. A label floating over your own head would be
  // over the sprite it's about.
  if (seated.value) return

  // Same for a wormhole, and for the same reason twice over: you are standing *in* it, and it
  // already draws its own name above itself. Suppressed rather than merely skipped, because E
  // belongs to the wormhole here — a tag reading "E · Sit" over a couch you happen to be facing
  // would be advertising the one thing the key will not do.
  if (portalHere.value) return

  const object = facingObject.value ?? facingSeat.value
  const kind = object ? decorKind(object.kind) : null
  if (!object || !kind || !inThisRoom.value) return

  const size = TILE * camera.zoom
  // Centred on the footprint *as placed*: a whiteboard turned on its side is one tile wide and
  // two deep, and a prompt centred on its catalogue width would float off to one side of it.
  const across = decorSize(object, kind).w
  const p = toScreen(camera, object.x + (across - 1) / 2, object.y - 0.6)
  // No key to name on a phone — there, the tag is the verb alone and the button below is how
  // you do it.
  const verb = object === facingSeat.value ? 'Sit' : (kind.verb ?? 'Use')
  const label = narrow.value ? verb : `E · ${verb}`

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
 * "H · Hold hands", over whoever you're standing next to.
 *
 * Hung over the *person* rather than over your own head, which is what makes it obvious who it
 * means when three people are stood in a huddle. Once you're holding on it follows your partner
 * and reads "Let go", because that's the only thing the key does from there.
 */
function drawHandPrompt(ctx: CanvasRenderingContext2D) {
  if (!inThisRoom.value || gameRunning.value) return

  const partner = holdingWith.value
  const target = partner ?? (nearestPerson.value ? others.value[nearestPerson.value.id] : null)
  if (!target) return

  const size = TILE * camera.zoom
  const p = toScreen(camera, target.x, target.y)
  const verb = partner ? 'Let go' : 'Hold hands'
  // No key to name on a phone; the button under the room is how you do it there.
  const label = narrow.value ? verb : `H · ${verb}`

  ctx.save()
  ctx.font = `600 ${Math.max(10, size * 0.28)}px system-ui, sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'

  const w = ctx.measureText(label).width + size * 0.5
  const h = size * 0.46
  // Above the name plate under their feet, and clear of whatever bubble is over their head.
  const cy = p.y + size * 0.95

  ctx.fillStyle = partner ? 'rgb(190 24 93 / 0.9)' : 'rgb(23 23 30 / 0.85)'
  ctx.beginPath()
  ctx.roundRect(p.x - w / 2, cy - h / 2, w, h, h / 2)
  ctx.fill()

  ctx.fillStyle = '#ffffff'
  ctx.fillText(label, p.x, cy + 1)
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
  const live = liveIds.value.includes(o.id)
  const gain = self ? 1 : (map.value && me.value ? audibility(map.value, me.value, o, live) : 0)

  ctx.save()
  // Fade with earshot, floored so a distant figure is still visibly a person. A live speaker
  // never fades: `gain` is already 1 for them from anywhere on the map, which is the point.
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
    // A sitter isn't walking whatever their legs think — a couch's occupant bobbing along on
    // the spot would be the one thing worse than not being able to sit at all.
    walking: walking && !o.seatedOn,
    phase: walkPhase(),
    sitting: !!o.seatedOn,
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

  /*
   * What they're saying beats what they're wearing.
   *
   * A shout is a sign you hang over yourself and leave there; a chat bubble is this second.
   * They occupy the same few pixels above the head, and stacking them would put a permanent
   * placard on top of the thing somebody just typed, so the live one wins for as long as it
   * lasts and the shout comes back underneath when it goes.
   */
  const bubble = bubbleFor(o.id)
  if (bubble?.kind === 'typing') drawTyping(ctx, p.x, p.y, size)
  else if (bubble?.kind === 'emote') drawEmote(ctx, bubble.text, p.x, p.y, size, bubble.until)
  else if (bubble) drawSpeech(ctx, bubble.text, p.x, p.y, size, bubble.until)
  else if (o.shout) drawShout(ctx, o.shout, p.x, p.y, size)

  /*
   * Live on a stage: a badge over the head, in the stage's own amber.
   *
   * Drawn rather than left to the nameplate because being live is the one state where a person
   * across the map is *doing something to you* — they're in your ears — and the figure you can
   * hear from sixty tiles away should be visibly the reason. It sits opposite the mute dot so
   * the two never overlap on somebody who is live with their mic shut.
   */
  if (live) {
    const bx = p.x - size * 0.42
    const by = p.y - size * 0.55
    ctx.beginPath()
    ctx.arc(bx, by, size * 0.2, 0, Math.PI * 2)
    ctx.fillStyle = 'rgb(217 119 6)'
    ctx.fill()
    // A cone, not a letter: at this size a glyph is a smudge, and the shape reads as a speaker
    // pointed at the room.
    ctx.beginPath()
    ctx.moveTo(bx - size * 0.09, by)
    ctx.lineTo(bx + size * 0.02, by - size * 0.09)
    ctx.lineTo(bx + size * 0.02, by + size * 0.09)
    ctx.closePath()
    ctx.fillStyle = '#ffffff'
    ctx.fill()
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

/**
 * The bubble over somebody's head, and what they're shouting in it.
 *
 * Above the sprite rather than below it, where the nameplate lives: the two are different
 * claims — one is who you are, the other is what you're saying — and stacking them on the same
 * side of a 16-pixel figure makes both harder to read than either alone.
 *
 * Drawn light-on-dark like the nameplate, for the same reason: the floor underneath is a
 * pattern, and plain text over grass is unreadable about half the time. It inherits whatever
 * `globalAlpha` the caller set, so a shout fades out of earshot exactly as its owner does.
 *
 * Long shouts are cut with an ellipsis rather than wrapped. Forty characters is short enough
 * that this almost never fires, and a two-line bubble at this zoom is a poster.
 */
function drawShout(ctx: CanvasRenderingContext2D, shout: string, x: number, y: number, size: number) {
  // Bigger than the nameplate under the feet, and deliberately so: a name is a thing you look up
  // when you want it, and a shout is a thing meant to be read across the room without going
  // looking. The floor of 12px is what keeps it legible when somebody has zoomed all the way out.
  const font = Math.max(12, size * 0.42)
  ctx.font = `600 ${font}px system-ui, sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'

  const maxText = size * 6
  let text = shout
  if (ctx.measureText(text).width > maxText) {
    while (text.length > 1 && ctx.measureText(`${text}…`).width > maxText) text = text.slice(0, -1)
    text = `${text}…`
  }

  const w = ctx.measureText(text).width + size * 0.55
  const h = font * 1.75

  ctx.fillStyle = 'rgb(0 0 0 / 0.62)'
  const cy = bubbleBox(ctx, x, y, size, w, h)

  ctx.fillStyle = '#ffffff'
  ctx.fillText(text, x, cy)
}

/**
 * The bubble's outline, shared by everything that hangs over a head.
 *
 * Rounded pill plus a tail, drawn in whatever `fillStyle` the caller set, and returning the
 * middle of it so the caller can put its contents there. Factored out because the three
 * bubbles — a shout, a line of chat, somebody typing — differ in what's *inside* them and
 * agree on everything else, and three copies of the tail geometry drifted the first time one
 * of them changed.
 */
function bubbleBox(ctx: CanvasRenderingContext2D, x: number, y: number, size: number, w: number, h: number) {
  // Clear of the muted-mic badge, which sits at -0.55 with a radius of 0.2.
  const cy = y - size * 0.95 - h / 2
  const top = cy - h / 2

  ctx.beginPath()
  ctx.roundRect(x - w / 2, top, w, h, Math.min(h / 2, size * 0.26))
  ctx.fill()

  // A tail, so it reads as coming *from* the person rather than floating above them. Grown with
  // the bubble: a pinprick under a fat pill reads as a speck of dirt rather than as a tail.
  ctx.beginPath()
  ctx.moveTo(x - size * 0.14, top + h - 0.5)
  ctx.lineTo(x, top + h + size * 0.22)
  ctx.lineTo(x + size * 0.14, top + h - 0.5)
  ctx.closePath()
  ctx.fill()

  return cy
}

/**
 * Something somebody just said in the chat, over their head for a few seconds.
 *
 * Light on dark, where a shout is white on black — the inversion is the whole distinction
 * between the two, and it's readable at a glance from across the room without a legend. It's
 * also the shape a speech bubble has in every comic anybody has ever read, which is worth more
 * than any label.
 *
 * Fades out over its last moments rather than vanishing, so a room where four people are
 * talking doesn't blink. Multiplies the alpha the caller set instead of replacing it, so a
 * bubble still dims with its owner's earshot exactly as a shout does.
 */
function drawSpeech(ctx: CanvasRenderingContext2D, said: string, x: number, y: number, size: number, until: number) {
  const FADE = 700
  const left = until - Date.now()
  if (left <= 0) return

  ctx.save()
  ctx.globalAlpha *= Math.min(1, left / FADE)

  const font = Math.max(11, size * 0.36)
  ctx.font = `500 ${font}px system-ui, sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'

  // Wrapped rather than cut, unlike a shout: a shout is a phrase you chose to fit over your
  // head, and a sentence somebody typed into the chat is whatever length it is. Three lines is
  // the ceiling — past that the bubble is a wall of text hanging over a 16-pixel person, and
  // the message itself is in the timeline underneath.
  const maxText = size * 7
  const lines: string[] = []
  let line = ''
  for (const word of said.split(/\s+/)) {
    const next = line ? `${line} ${word}` : word
    if (line && ctx.measureText(next).width > maxText) {
      lines.push(line)
      line = word
      if (lines.length === 3) break
    } else {
      line = next
    }
  }
  if (lines.length < 3 && line) lines.push(line)

  // Whatever didn't fit is admitted to rather than dropped silently.
  if (lines.length === 3) {
    let last = lines[2]!
    const clipped = said.length > lines.join(' ').length
    if (clipped) {
      while (last.length > 1 && ctx.measureText(`${last}…`).width > maxText) last = last.slice(0, -1)
      lines[2] = `${last}…`
    }
  }

  if (!lines.length) return ctx.restore()

  const lineHeight = font * 1.3
  const w = Math.max(...lines.map(l => ctx.measureText(l).width)) + size * 0.55
  const h = lines.length * lineHeight + font * 0.55

  ctx.fillStyle = 'rgb(255 255 255 / 0.94)'
  const cy = bubbleBox(ctx, x, y, size, w, h)

  ctx.fillStyle = 'rgb(15 23 42)'
  const firstY = cy - ((lines.length - 1) * lineHeight) / 2
  lines.forEach((l, i) => ctx.fillText(l, x, firstY + i * lineHeight))

  ctx.restore()
}

/**
 * An emote: one glyph over somebody's head, popped and then let go.
 *
 * Bare, with no bubble round it, and that's the point — a speech bubble says "they typed this",
 * and an emoji floating over a head says "they did that". It also has to read from right across
 * the room at a tenth the size of a sentence, which a glyph can and a box of text can't.
 *
 * The pop is a short overshoot on the way in and a rise-and-fade on the way out, driven off the
 * expiry the bubble store already carries rather than a clock of its own — so an emote that
 * arrived a second late is a second further through its life, exactly as a said line is.
 */
function drawEmote(ctx: CanvasRenderingContext2D, glyph: string, x: number, y: number, size: number, until: number) {
  const left = until - Date.now()
  if (left <= 0) return

  // Rasterised once at 128px and scaled *down* here — drawing it as text at this size is what
  // made it blurry. See emojiSprite.
  const art = emojiSprite(glyph)
  if (!art) return

  const age = EMOTE_MS - left
  // Overshoot to 1.25 and settle: an emote that merely appeared would be missed by anybody not
  // already looking at that corner of the room, which is most of the room.
  const grow = Math.min(1, age / 160)
  const scale = grow < 1 ? grow * 1.25 : 1 + Math.max(0, 0.25 - (age - 160) / 700)
  // The last half second: drifting up as it goes, which is what makes it read as released.
  const fade = Math.min(1, left / 500)

  const drawn = size * 0.95 * scale
  const cy = y - size * 1.05 - (1 - fade) * size * 0.35

  ctx.save()
  ctx.globalAlpha *= fade
  ctx.drawImage(art, x - drawn / 2, cy - drawn / 2, drawn, drawn)
  ctx.restore()
}

/**
 * The arm between two people holding hands.
 *
 * Drawn once per pair rather than once per person — see {@link handPartnerOf}, which only
 * answers when both ends agree — and *under* the sprites, so it comes out of the hips rather
 * than across the faces. A gentle sag, because a straight line between two figures reads as a
 * rope and a curve reads as an arm.
 */
function drawHandLinks(ctx: CanvasRenderingContext2D) {
  const size = TILE * camera.zoom
  const drawn = new Set<number>()

  for (const o of occupants.value) {
    const partner = handPartnerOf(o)
    if (!partner || drawn.has(o.id)) continue

    drawn.add(o.id)
    drawn.add(partner.id)

    const a = toScreen(camera, o.x, o.y)
    const b = toScreen(camera, partner.x, partner.y)
    // Hands are at about hip height on a sprite anchored at its feet.
    const ay = a.y - size * 0.1
    const by = b.y - size * 0.1

    ctx.save()
    ctx.globalAlpha = 0.85
    ctx.strokeStyle = 'rgb(244 114 182)'
    ctx.lineWidth = Math.max(2, size * 0.09)
    ctx.lineCap = 'round'
    ctx.beginPath()
    ctx.moveTo(a.x, ay)
    ctx.quadraticCurveTo((a.x + b.x) / 2, (ay + by) / 2 + size * 0.22, b.x, by)
    ctx.stroke()
    ctx.restore()
  }
}

/**
 * Somebody is typing: three dots in a bubble, rising in turn.
 *
 * The same bubble their words will appear in, so the line that follows looks like it grew out
 * of this rather than replacing it. Off one shared clock like the walk cycle, so a room full of
 * people typing pulses together instead of shimmering.
 */
function drawTyping(ctx: CanvasRenderingContext2D, x: number, y: number, size: number) {
  const r = Math.max(1.6, size * 0.075)
  const gap = r * 3
  const w = gap * 2 + r * 2 + size * 0.5
  const h = r * 2 + size * 0.4

  ctx.save()
  ctx.fillStyle = 'rgb(255 255 255 / 0.94)'
  const cy = bubbleBox(ctx, x, y, size, w, h)

  ctx.fillStyle = 'rgb(100 116 139)'
  const t = performance.now() / 1000
  for (let i = 0; i < 3; i++) {
    // A third of a cycle apart, and only the top half of the sine — the dots hop, they don't
    // swing, which is what the three-dot indicator does everywhere else in the app.
    const lift = Math.max(0, Math.sin((t - i * 0.16) * 6)) * r * 1.1
    ctx.beginPath()
    ctx.arc(x + (i - 1) * gap, cy - lift, r, 0, Math.PI * 2)
    ctx.fill()
  }
  ctx.restore()
}

// --- lifecycle ---

const audiblePeople = computed(() =>
  audibleIds.value.map(id => others.value[id]).filter((o): o is NonNullable<typeof o> => !!o))

/**
 * Standing on a stage, and whether the room can actually hear you.
 *
 * Two facts rather than one, because the difference between them is the whole of what somebody
 * needs told: stepping onto a full platform *looks* exactly like stepping onto an empty one, and
 * the only honest moment to say "you are in the wings" is while they're stood there wondering
 * why nobody reacted. `live` is read from the same set the gains were computed from, so the
 * banner cannot disagree with what the room is hearing.
 */
const myStage = computed(() => {
  const m = map.value
  const self = me.value
  if (!m || !self) return null

  const zone = zoneAt(m, self.x, self.y)
  if (zone?.kind !== 'stage') return null

  return { name: zone.name, live: liveIds.value.includes(self.id) }
})

async function onMapSaved() {
  editing.value = null
  await loadMap()
}

/**
 * The editor asking to be pointed at another of this Side Space's maps.
 *
 * The same move as walking through a doorway into it, and done the same way — the editor stays
 * open, keyed on the new map, and you are put down at its entrance. Editing a room you are not
 * standing in would mean drawing a grid nobody's avatar is on, which is exactly the arrangement
 * that lets somebody paint a wall through a person.
 */
async function onEditorOpenMap(slug: string) {
  try {
    const next = await openMap(slug)
    const at = spawnPoint(next)

    warp(at.x, at.y)
    // Put down on the interior's entrance, which is where its way home stands. Same reason as
    // the doorway path: a walked one would otherwise fire the instant you landed on it.
    usedPortal = portalAt(next, at.x, at.y)?.id ?? null
  }
  catch {
    // Gone since the list was drawn. The editor stays on the map it has, which is still real.
  }
}

onMounted(async () => {
  openedAt = performance.now()

  // First, before anything that can put somebody in the room: `reattach` below places *you* if
  // the position state didn't survive a reload, and your own arrival is emitted the moment you're
  // placed. Subscribing after that would miss the one effect you're guaranteed to be looking at.
  stopRoomEvents = onRoomEvent(addEffect)

  await loadMap()
  subscribeMap()

  document.addEventListener('fullscreenchange', onFullscreenChange)

  // The room's game, if one's afoot. Loaded and listened to alongside the map, on the same
  // channel stream — a game you walk in on should already be on screen, not a beat behind.
  await loadGame()
  subscribeGame()

  resize()
  ro = new ResizeObserver(resize)
  if (wrap.value) ro.observe(wrap.value)

  // How much room the room itself has, which is what decides whether the header folds. Its own
  // observer rather than a branch inside `resize`: that one is about the canvas' backing store
  // and runs on the drawing element, and this is about the component's outer width.
  widthRo = new ResizeObserver(([entry]) => { stageWidth.value = entry?.contentRect.width ?? 0 })
  if (root.value) widthRo.observe(root.value)

  // Walked in earlier and only now come back to look at it. The map has to be loaded first —
  // `place` needs to know which tiles you're allowed to stand on.
  reattach()

  window.addEventListener('keydown', onInteractKey)
  // Not `@wheel` in the template: Vue attaches listeners passively where it can, and a passive
  // listener is one that has already promised not to call preventDefault — so the page would
  // scroll behind the room however loudly we objected afterwards.
  canvas.value?.addEventListener('wheel', onWheel, { passive: false })

  // Whatever you were last comfortable with. Read here rather than in the ref's initialiser
  // because the component is rendered on the server too, where there is no localStorage.
  const saved = Number(localStorage.getItem(ZOOM_KEY))
  if (saved) zoomLevel.value = clampZoom(saved)

  frame = requestAnimationFrame(loop)
})

onBeforeUnmount(() => {
  if (frame) cancelAnimationFrame(frame)
  window.removeEventListener('keydown', onInteractKey)
  canvas.value?.removeEventListener('wheel', onWheel)
  stopRoomEvents?.()
  stopRoomEvents = undefined
  effects.length = 0
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  unsubscribeFilm()
  ro?.disconnect()
  widthRo?.disconnect()
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
  facingSeat.value = null
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
    ref="root"
    class="relative flex flex-col border-b bg-muted/20"
    :class="chatHidden ? 'min-h-0 flex-1' : 'shrink-0'"
    :style="chatHidden ? undefined : { height: `${stageHeight}px` }"
  >
    <!-- Header: what room this is, who's in it, and the way in or out. -->
    <div class="flex h-11 shrink-0 items-center justify-between gap-2 px-3">
      <!--
        The name and its trimmings scroll sideways rather than push the controls off the
        header. Everything in here is `shrink-0` by nature (a badge, a count, a zone pill), so
        without somewhere to overflow *to* they simply ran over the mic and Leave buttons in a
        narrow pane. `min-w-0` is what lets this strip give up width in the first place.
      -->
      <div class="scroll-strip flex min-w-0 flex-1 items-center gap-2 text-sm [&>*]:shrink-0">
        <MapIcon class="h-4 w-4 shrink-0 text-muted-foreground" />
        <span class="truncate font-medium">{{ map?.name ?? channel.name }}</span>
        <span class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
          <Users class="h-3.5 w-3.5" /> {{ occupantCount }}
        </span>
        <span
          v-if="currentZone"
          class="max-w-[10rem] shrink-0 truncate rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
          :title="`Everyone in ${currentZone.name} hears each other, and nobody outside it can`"
        >{{ currentZone.name }}</span>
      </div>

      <div class="relative flex shrink-0 items-center gap-1">
        <!-- The two controls that never fold away: whether you're audible, and the way in or
             out. On a phone everything else is a tap further away, which is the right trade —
             these are the ones you reach for mid-sentence. -->
        <button
          v-if="inThisRoom"
          type="button"
          class="rounded p-1.5 transition-colors hover:bg-muted"
          :class="micOpen ? 'text-foreground' : 'text-destructive'"
          :title="selfMuted ? 'Unmute' : 'Mute'"
          @click="toggleMute"
        >
          <component :is="micOpen ? Mic : MicOff" class="h-4 w-4" />
        </button>

        <!-- The way to everyone's cameras, screens and volumes: a sheet on a narrow window, the
             rail beside the room on a wide one. Either way this is the switch, so a panel you've
             put away has somewhere obvious to come back from. -->
        <button
          v-if="inThisRoom"
          type="button"
          class="relative rounded p-1.5 transition-colors hover:bg-muted"
          :class="peopleShowing ? 'text-primary' : 'text-muted-foreground'"
          :aria-expanded="peopleShowing"
          title="Who's in earshot — cameras, screens and volumes"
          @click="togglePeople"
        >
          <Users class="h-4 w-4" />
          <!-- Somebody put a screen or a track on while you were looking at the room. -->
          <span
            v-if="someoneSharing && !peopleShowing"
            class="absolute right-0.5 top-0.5 h-2 w-2 rounded-full bg-primary ring-2 ring-background"
          />
        </button>

        <Button v-if="inThisRoom" variant="ghost" size="sm" class="gap-1.5 text-destructive" @click="leave">
          <PhoneOff class="h-4 w-4" /> <span :class="narrow ? 'sr-only' : undefined">Leave</span>
        </Button>

        <Button v-if="!inThisRoom" size="sm" class="gap-1.5" :disabled="joining || loading" @click="enter">
          <Loader2 v-if="joining" class="h-4 w-4 animate-spin" />
          <MapIcon v-else class="h-4 w-4" />
          {{ joining ? 'Entering…' : narrow ? 'Enter' : 'Enter the space' }}
        </Button>

        <button
          v-if="narrow"
          type="button"
          class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          :aria-expanded="showMore"
          title="More room controls"
          @click="showMore = !showMore"
        >
          <MoreHorizontal class="h-4 w-4" />
        </button>

        <!-- Everything else: inline on a wide header, a menu under the ⋯ on a narrow one. It's
             one list either way, wearing different clothes (see `toolClass`), so there is no
             second copy of these buttons to keep in step. -->
        <div
          v-if="!narrow || showMore"
          :class="narrow
            ? 'absolute right-0 top-full z-30 mt-1 w-56 space-y-0.5 rounded-lg border bg-background p-1.5 shadow-xl'
            : 'flex items-center gap-1'"
        >
          <template v-if="inThisRoom">
            <button
              type="button"
              :class="[toolClass, selfDeafened ? 'text-destructive' : 'text-foreground']"
              :title="selfDeafened ? 'Undeafen' : 'Deafen'"
              @click="fromMenu(toggleDeafen)"
            >
              <component :is="selfDeafened ? HeadphoneOff : Headphones" class="h-4 w-4 shrink-0" />
              <span v-if="narrow">{{ selfDeafened ? 'Undeafen' : 'Deafen' }}</span>
            </button>
            <button
              type="button"
              :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
              :title="isCameraOn ? 'Turn camera off' : 'Turn camera on'"
              @click="fromMenu(toggleCamera)"
            >
              <component :is="isCameraOn ? Video : VideoOff" class="h-4 w-4 shrink-0" />
              <span v-if="narrow">{{ isCameraOn ? 'Turn camera off' : 'Turn camera on' }}</span>
            </button>
            <!-- Front camera or back one — only while one is on, and only where there are two. -->
            <button
              v-if="isCameraOn && canSwitchCamera"
              type="button"
              :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
              :title="cameraFacing === 'user' ? 'Switch to the back camera' : 'Switch to the front camera'"
              @click="fromMenu(switchCamera)"
            >
              <SwitchCamera class="h-4 w-4 shrink-0" />
              <span v-if="narrow">{{ cameraFacing === 'user' ? 'Back camera' : 'Front camera' }}</span>
            </button>
            <button
              type="button"
              :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
              :title="isSharing ? 'Stop sharing' : 'Share your screen'"
              @click="fromMenu(toggleScreenShare)"
            >
              <component :is="isSharing ? ScreenShareOff : ScreenShare" class="h-4 w-4 shrink-0" />
              <span v-if="narrow">{{ isSharing ? 'Stop sharing' : 'Share your screen' }}</span>
            </button>
            <!-- Sound with no picture: a track, or a video everyone's listening to rather than
                 watching. Rides the same slot a screen's audio does. -->
            <button
              type="button"
              :class="[toolClass, isAudioSharing ? 'text-primary' : 'text-muted-foreground hover:text-foreground']"
              :title="isAudioSharing ? 'Stop sharing sound' : 'Share sound from a tab'"
              @click="fromMenu(toggleAudioShare)"
            >
              <AudioLines class="h-4 w-4 shrink-0" />
              <span v-if="narrow">{{ isAudioSharing ? 'Stop sharing sound' : 'Share sound' }}</span>
            </button>
          </template>

          <!-- Showing and hiding the conversation used to live here, as a toolbar button that
               folded into this `⋯` menu on a phone — two taps for the control people reach for
               most. It's a floating pill over the room now (see ChatTogglePill), which is also
               what an app channel uses, so it sits in the same place in both. -->

          <!-- What you look like walking around. Yours, not the room's, so it isn't owner-gated. -->
          <button
            type="button"
            :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
            title="Change how you look, and pick a companion"
            @click="fromMenu(() => (dressing = true))"
          >
            <Shirt class="h-4 w-4 shrink-0" />
            <span v-if="narrow">Change how you look</span>
          </button>

          <!-- A word over your head that stays there. Lit while one is up, because a thing that
               everybody else can see and you can't is the one state worth showing in the bar —
               and it doubles as the way back to turning it off. Only in the room: shouting into
               a place you aren't standing in is nothing. -->
          <button
            v-if="inThisRoom"
            type="button"
            :class="[toolClass, myShout ? 'text-primary' : 'text-muted-foreground hover:text-foreground']"
            :title="myShout ? `You're shouting “${myShout}” — click to change it or turn it off` : 'Shout something over your head'"
            @click="fromMenu(() => (shouting = true))"
          >
            <Megaphone class="h-4 w-4 shrink-0" />
            <span v-if="narrow">{{ myShout ? `Shouting “${myShout}”` : 'Shout something' }}</span>
          </button>

          <!--
            Whether the chat is also drawn over people's heads.

            Yours alone, remembered, and nobody else is told — a room where you can see what's
            being said is a livelier place, and a room where you can't is a quieter one, and
            which of those you want is a matter of taste rather than something the channel
            should decide for everybody. Turning a single person's bubbles off lives beside
            their volume in the people rail, where the rest of the per-person mutes are.
          -->
          <button
            type="button"
            :class="[toolClass, bubblesOn ? 'text-primary' : 'text-muted-foreground hover:text-foreground']"
            :title="bubblesOn
              ? 'Chat bubbles are on — click to stop showing what people say over their heads'
              : 'Show what people say in the chat as bubbles over their heads'"
            @click="fromMenu(() => (bubblesOn = !bubblesOn))"
          >
            <component :is="bubblesOn ? MessageCircle : MessageCircleOff" class="h-4 w-4 shrink-0" />
            <span v-if="narrow">{{ bubblesOn ? 'Chat bubbles on' : 'Chat bubbles off' }}</span>
          </button>

          <!--
            What the room does when somebody comes into earshot. The very same settings a voice
            channel has — and until now the room was the one place they could be *fired* but not
            *chosen*, because the dialog only ever existed on the voice channel's header. The
            room always draws its own small arrival at the tile it happened on; this is the
            louder, rarer fanfare on top of that, and "None" is a perfectly good answer.

            Owner-only, like rebuilding the room: an entrance is set for everybody who walks in.
            The wrapper closes the ⋯ menu, since the component owns its own trigger.
          -->
          <div v-if="canModerate" :class="narrow ? 'w-full' : undefined" @click="showMore = false">
            <VoiceEffectSettings :channel="channel" />
          </div>

          <!--
            "Everyone follow me."

            Staff only, and only while you're standing in the room — summoning people to a place
            you aren't is summoning them to nowhere. Deliberately not a per-person menu: the
            thing this is for is gathering a room, and picking people off a list one at a time is
            a different, rarer job that the endpoint still allows.

            The label carries the whole state, because there's no other indication on the
            leader's own screen that it's happening — everybody else's avatar walking towards
            them is the indication, and that only reads as deliberate if the button says so.
          -->
          <button
            v-if="canModerate && inThisRoom"
            type="button"
            :class="[toolClass, leading ? 'text-foreground' : 'text-muted-foreground hover:text-foreground']"
            :disabled="summoning"
            :title="leading ? 'Let everyone go' : 'Make everyone follow you'"
            @click="fromMenu(toggleSummon)"
          >
            <Users class="h-4 w-4 shrink-0" />
            <span v-if="narrow">{{ leading ? 'Let everyone go' : 'Everyone follow me' }}</span>
          </button>

          <!-- Rooms and their doors. Hidden from anybody who administers neither: a panel whose
               every list would be empty is worse than no panel. -->
          <button
            v-if="managesRooms"
            type="button"
            :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
            title="Rooms and locks"
            @click="fromMenu(() => (managingLocks = true))"
          >
            <Lock class="h-4 w-4 shrink-0" />
            <span v-if="narrow">Rooms and locks</span>
          </button>

          <!-- Start a game. Only when you're in the room and none is already on the table; a game
               in progress is driven by the panel over the map, not from up here. -->
          <button
            v-if="canProposeGame"
            type="button"
            :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
            title="Start a game in the room"
            @click="fromMenu(togglePropose)"
          >
            <Gamepad2 class="h-4 w-4 shrink-0" />
            <span v-if="narrow">Start a game</span>
          </button>

          <!-- Decorating is open to anyone; it only ever touches the furniture. Hidden from the
               owner, who reaches the same furniture (and everything else) through the full editor. -->
          <button
            v-if="!canEdit && !editing"
            type="button"
            :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
            title="Rearrange the furniture"
            @click="fromMenu(() => (editing = 'decor'))"
          >
            <Sofa class="h-4 w-4 shrink-0" />
            <span v-if="narrow">Rearrange the furniture</span>
          </button>

          <button
            v-if="canEdit && !editing"
            type="button"
            :class="[toolClass, 'text-muted-foreground hover:text-foreground']"
            title="Edit this room"
            @click="fromMenu(() => (editing = 'full'))"
          >
            <Pencil class="h-4 w-4 shrink-0" />
            <span v-if="narrow">Edit this room</span>
          </button>
        </div>

        <!-- The game menu hangs off the whole cluster rather than off its button, so it lands in
             the same place whether that button is in the header or in the ⋯ menu (which has by
             then closed behind it). -->
        <div
          v-if="showPropose"
          class="absolute right-0 top-full z-40 mt-1 w-60 space-y-1 rounded-lg border bg-background p-1.5 shadow-xl"
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
    </div>

    <!-- Room and dock: side by side when there's width for it. A 224px rail against a 390px
         screen leaves the room too thin to walk in, so on a phone the dock becomes a sheet over
         the room instead — see the Users button above. -->
    <div class="relative flex min-h-0 flex-1">
      <!-- The room. -->
      <div ref="wrap" class="relative min-w-0 flex-1 overflow-hidden">
        <!-- `touch-none` is load-bearing: without it a drag across the room is a scroll gesture
             and the browser takes the pointer stream away mid-walk. -->
        <!--
          The share, kept a pixel across and invisible.

          Two jobs, and it has to be a real element for both: drawScreens copies its current frame
          onto the map's screens, and `requestFullscreen` needs something to enlarge. Not
          `display:none` — a hidden video is one some browsers stop decoding, and a screen painted
          from a stalled element is a frozen frame nobody can explain.
        -->
        <video
          v-if="hasScreens"
          ref="screenEl"
          muted
          playsinline
          autoplay
          class="space-share pointer-events-none absolute left-0 top-0 h-px w-px opacity-0"
        />

        <!--
          The room's watch-along. Same trick as the share above and the same reasons; muted here
          too, and unmuted only for the length of a fullscreen — see watchFullscreen.

          `preload="auto"` is load-bearing rather than an optimisation. Left to the default the
          browser fetches metadata only, which leaves `readyState` at HAVE_METADATA — dimensions
          known, no frame decoded — and `drawScreens` has nothing to copy. A *paused* film would
          therefore paint as a black screen, which is exactly what somebody walking into a room
          mid-pause would see. Buffering costs a download in rooms that hang a screen, which is
          the one place a room has asked for it.
        -->
        <video
          v-if="hasScreens"
          ref="filmEl"
          muted
          playsinline
          preload="auto"
          class="space-share pointer-events-none absolute left-0 top-0 h-px w-px opacity-0"
        />

        <canvas
          ref="canvas"
          class="block h-full w-full touch-none"
          :class="inThisRoom ? 'cursor-pointer' : undefined"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointercancel="onPointerUp"
        />

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
            <!-- Lead with the control you actually have. On a phone the arrow keys are noise;
                 on a desktop the tap is the afterthought. -->
            <p class="text-xs text-muted-foreground">
              <template v-if="narrow">Tap where you want to go, and drag to steer.</template>
              <template v-else>Move with the arrow keys or WASD — or tap where you want to go, and drag to steer.</template>
              You'll hear people within about {{ FAR_TILES }} tiles,
              louder the closer you get — and everyone inside a room hears each other, and nobody outside it.
            </p>
            <p class="text-xs text-muted-foreground">
              Walk up to the speaker, the TV or an arcade cabinet
              <template v-if="narrow">and tap the button that appears to put something on for whoever's nearby.</template>
              <template v-else>and press <kbd class="rounded border px-1">E</kbd> to put something on for whoever's nearby.</template>
            </p>
            <p class="text-xs text-muted-foreground">
              <template v-if="narrow">Tap somebody to walk over to them, and tap the button to offer them your hand.</template>
              <template v-else>Click somebody to walk over to them, then <kbd class="rounded border px-1">H</kbd> to hold hands — you'll pull each other along.</template>
              Pull a face with the emote bar in the corner; reacting to a message pops the same emoji over your head.
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
        <!--
          Why the door isn't moving.

          A door needs no pressing, so this is an explanation rather than an affordance — which
          is why it's a label and not a button. Without it a locked door is indistinguishable
          from a broken one: you walk at it, nothing happens, and there is nothing on screen
          that says anybody meant that.
        -->
        <!-- Unless a password would open it, in which case it is an affordance after all, and
             the same press (E) that uses furniture asks for the words. -->
        <component
          :is="blockedDoorTakesPassword ? 'button' : 'p'"
          v-if="inThisRoom && blockedDoor"
          :type="blockedDoorTakesPassword ? 'button' : undefined"
          class="absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full bg-foreground/90 px-3 py-1.5 text-xs font-medium text-background shadow-lg"
          :class="blockedDoorTakesPassword ? 'transition hover:bg-foreground' : ''"
          @click="blockedDoorTakesPassword && blockedDoor ? askingDoor = blockedDoor.id : undefined"
        >
          <Lock class="h-3.5 w-3.5" />
          {{ lockedDoorHint }}
          <span v-if="blockedDoorTakesPassword && !narrow" class="rounded border border-background/40 px-1 text-[10px] leading-4">E</span>
        </component>

        <!-- A doorway that led nowhere. Sits where the prompt would be, because it is the answer
             to the press that was just made there. -->
        <div
          v-if="inThisRoom && travelProblem"
          class="absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full bg-destructive px-3 py-1.5 text-xs font-medium text-destructive-foreground shadow-lg"
        >
          {{ travelProblem }}
        </div>

        <button
          v-if="inThisRoom && hasPrompt && !travelProblem && !blockedDoor && !handOffer && !following"
          type="button"
          class="absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full bg-foreground px-3 py-1.5 text-xs font-medium text-background shadow-lg transition hover:opacity-90 disabled:opacity-60"
          :disabled="using || travelling"
          @click="portalHere ? travelThrough(portalHere)
            : (screenAhead && screenIsLive) ? watchFullscreen()
              : (screenAhead && filmUnpaintable) ? undefined
              : pieceAhead ? viewExhibit()
                : facingObject ? useFurniture() : toggleSeat()"
        >
          <Loader2 v-if="using || travelling" class="h-3.5 w-3.5 animate-spin" />
          <span v-else-if="!narrow" class="rounded border border-background/40 px-1 text-[10px] leading-4">E</span>
          {{ interactHint }}
        </button>

        <!--
          You are being walked somewhere.

          The counterweight to the whole feature. A summon doesn't ask, so the one thing it owes
          the person it moves is that it is never a mystery: your avatar setting off on its own
          with nothing on screen to explain it reads as your game breaking, not as somebody
          calling you over. Named, so you know whose doing it is.

          No way out of it here on purpose — being let go is the leader's to decide, which is
          what "staff only, no consent" means when it's honest about itself. It ends when they
          end it, or when you walk out of the room.
        -->
        <div
          v-if="inThisRoom && following"
          class="absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full bg-amber-600 px-3 py-1.5 text-xs font-medium text-white shadow-lg"
        >
          <Users class="h-3.5 w-3.5" />
          <span>Following {{ following.name }}</span>
        </div>

        <!--
          Somebody has offered you their hand.

          Sits where the "press E" pill would be and outranks it, because it's the one thing on
          screen with somebody waiting at the other end of it. Two buttons rather than one: a
          request that could only be accepted is not a request, and holding hands drags you
          around the room, so declining has to be as easy as agreeing.
        -->
        <div
          v-if="inThisRoom && handOffer"
          class="absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full bg-pink-600 px-3 py-1.5 text-xs font-medium text-white shadow-lg"
        >
          <Hand class="h-3.5 w-3.5" />
          <span>{{ handOffer.name }} wants to hold hands</span>
          <button type="button" class="rounded-full bg-white/20 px-2 py-0.5 transition hover:bg-white/30" @click="acceptHand()">
            Take it<span v-if="!narrow" class="ml-1 rounded border border-white/40 px-1 text-[10px] leading-4">H</span>
          </button>
          <button type="button" class="rounded-full px-2 py-0.5 text-white/70 transition hover:text-white" @click="declineHand()">
            No
          </button>
        </div>

        <!--
          Reaching for the person next to you, on a screen with no keyboard to press H on.

          Only when there is somebody to reach for, and it steps aside for the offer above and
          for the furniture pill below it — three pills stacked in the same corner would be a
          menu, and none of these is worth one.
        -->
        <button
          v-if="inThisRoom && !handOffer && !following && !hasPrompt && !blockedDoor && (holdingWith || nearestPerson)"
          type="button"
          class="absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium shadow-lg transition hover:opacity-90"
          :class="holdingWith ? 'bg-pink-600 text-white' : 'bg-foreground text-background'"
          @click="toggleHand()"
        >
          <Hand class="h-3.5 w-3.5" />
          <span v-if="!narrow" class="rounded border border-current/40 px-1 text-[10px] leading-4 opacity-70">H</span>
          {{ holdingWith ? `Let go of ${holdingWith.name}` : `Hold hands with ${nearestPerson?.name}` }}
        </button>

        <!--
          The emote bar.

          Down the left edge, opposite the zoom buttons and out of the way of both the pills
          along the bottom and the people rail on the right. Collapsed to a single face until
          you want it, because a permanent grid of twelve emoji over a room you're trying to
          walk in is a menu bar, not a room.
        -->
        <div v-if="inThisRoom && !gameRunning && !gameMeeting" class="absolute left-2 top-2 flex flex-col items-start gap-1">
          <!--
            Who's actually in here, and a way to get to them.

            The one thing a map can't tell you by being a map: from across the room every sprite
            is a hat. Distinct from the call dock on the right, which lists who you can *hear* —
            that's the near half of this list by definition, and the people worth walking to are
            precisely the ones missing from it.
          -->
          <button
            type="button"
            class="flex h-8 items-center gap-1.5 rounded-lg border bg-background/90 px-2 text-muted-foreground shadow-sm backdrop-blur transition-colors hover:bg-muted hover:text-foreground"
            :class="rosterOpen ? 'bg-muted text-foreground' : undefined"
            :aria-expanded="rosterOpen"
            title="Who's in this room"
            @click="rosterOpen = !rosterOpen"
          >
            <Users class="h-4 w-4" />
            <span class="text-xs font-medium">{{ roster.length + 1 }}</span>
          </button>

          <div v-if="rosterOpen" class="w-56 overflow-hidden rounded-lg border bg-background/95 shadow-sm backdrop-blur">
            <p class="border-b px-2 py-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground/70">
              In this room
            </p>

            <p v-if="!roster.length" class="px-2 py-3 text-xs text-muted-foreground">
              Just you, for the moment.
            </p>

            <ul v-else class="max-h-56 overflow-y-auto py-1">
              <li v-for="p in roster" :key="p.id" class="flex items-center gap-2 px-2 py-1">
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-xs font-medium">{{ p.name }}</span>
                  <span class="block truncate text-[10px] text-muted-foreground">
                    {{ p.zone ?? `${Math.round(p.distance)} tiles away` }}
                    <template v-if="p.sitting"> · sitting</template>
                    <!-- Before the earshot note, and instead of it: somebody live is audible by
                         definition, and "on stage" is the more useful half of why. -->
                    <template v-if="p.live"> · <span class="font-medium text-amber-600">on stage</span></template>
                    <template v-else-if="!p.audible"> · out of earshot</template>
                  </span>
                </span>
                <button
                  type="button"
                  class="shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                  :title="`Walk over to ${p.name}`"
                  @click="goTo(p.id)"
                >
                  Go to
                </button>

                <!--
                  The other direction: bring them to you rather than going to them.

                  Deliberately next to "Go to", because they are the same wish pointed opposite
                  ways and the roster is where you're already looking at a name. Staff only, and
                  only while you're in the room — the same two conditions the header's
                  everyone-follow-me carries, for the same reasons.
                -->
                <button
                  v-if="canModerate && inThisRoom"
                  type="button"
                  class="shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-medium transition-colors hover:bg-muted"
                  :class="summonedIds.includes(p.id) ? 'border-amber-600 text-amber-600' : 'text-muted-foreground hover:text-foreground'"
                  :title="summonedIds.includes(p.id) ? `Let ${p.name} go` : `Make ${p.name} follow you`"
                  @click="toggleSummonOne(p.id)"
                >
                  {{ summonedIds.includes(p.id) ? 'Release' : 'Summon' }}
                </button>
              </li>
            </ul>
          </div>

          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center rounded-lg border bg-background/90 text-muted-foreground shadow-sm backdrop-blur transition-colors hover:bg-muted hover:text-foreground"
            :class="emoteBarOpen ? 'bg-muted text-foreground' : undefined"
            :title="emoteBarOpen ? 'Close the emotes' : 'Pull a face'"
            :aria-expanded="emoteBarOpen"
            aria-label="Emotes"
            @click="emoteBarOpen = !emoteBarOpen"
          >
            <Smile class="h-4 w-4" />
          </button>

          <div v-if="emoteBarOpen" class="grid w-[104px] grid-cols-3 gap-0.5 rounded-lg border bg-background/90 p-1 shadow-sm backdrop-blur">
            <button
              v-for="e in EMOTES"
              :key="e.glyph"
              type="button"
              class="flex h-8 w-8 items-center justify-center rounded text-lg leading-none transition-colors hover:bg-muted"
              :title="e.label"
              @click="emote(e.glyph)"
            >
              {{ e.glyph }}
            </button>
          </div>
        </div>

        <!--
          How close you're standing. The wheel and a two-finger pinch do the same thing; these are
          for the touchscreen that has no wheel and the person who'd rather press a button than
          discover a gesture.

          Up the right-hand edge, clear of the earshot line at bottom-left and the "press E" pill
          in the middle, and 32px square so a thumb can hit it. Hidden while a game owns the room.
        -->
        <div
          v-if="!gameRunning && !gameMeeting"
          class="absolute right-2 top-2 flex flex-col overflow-hidden rounded-lg border bg-background/90 shadow-sm backdrop-blur"
        >
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
            title="Closer"
            aria-label="Zoom in"
            :disabled="zoomLevel >= MAX_ZOOM - 0.001"
            @click="zoomBy(ZOOM_STEP)"
          >
            <Plus class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center border-t text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
            title="Further out"
            aria-label="Zoom out"
            :disabled="zoomLevel <= MIN_ZOOM + 0.001"
            @click="zoomBy(1 / ZOOM_STEP)"
          >
            <Minus class="h-4 w-4" />
          </button>
          <!-- Only once you've moved it, and it says what it'll go back to rather than "reset". -->
          <button
            v-if="Math.abs(zoomLevel - 1) > 0.01"
            type="button"
            class="flex h-8 w-8 items-center justify-center border-t text-[10px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Back to the size this room picks for itself"
            @click="zoomBy(1 / zoomLevel)"
          >
            1×
          </button>
        </div>

        <!--
          The overview.

          Under the zoom buttons on the same edge, so the two things that answer "where am I" sit
          together. Only for maps too big to take in at once — on a room that already fits on
          screen a second smaller copy of it is decoration that costs a repaint. Hidden while a
          game owns the room, like the zoom controls, because a game draws its own world.
        -->
        <SideSpaceMiniMap
          v-if="map && showMiniMap && !gameRunning && !gameMeeting"
          class="absolute right-2 top-[7.5rem]"
          :map="map"
          :occupants="occupants"
          :me-id="user?.id ?? null"
          :camera="camera"
        />

        <!--
          You, on a stage. Sits above the earshot line rather than replacing it, because the two
          say different things: this is who can hear you *because of where you're standing*, and
          that is who you can hear.
        -->
        <div
          v-if="inThisRoom && myStage && !gameRunning"
          class="pointer-events-none absolute left-1/2 top-3 flex -translate-x-1/2 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium shadow-lg"
          :class="myStage.live ? 'bg-amber-500 text-white' : 'bg-background/95 text-muted-foreground border'"
        >
          <Megaphone class="h-3.5 w-3.5" />
          <template v-if="myStage.live">
            You're live on {{ myStage.name }} — the whole room can hear and see you.
          </template>
          <template v-else>
            {{ myStage.name }} is full ({{ STAGE_SPEAKERS }} speakers). You're in the wings.
          </template>
        </div>

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

        <!-- Which hero is going down — asked before a portal opens, and before joining one. -->
        <ArpgHeroDialog
          v-if="heroPrompt"
          @enter="onHeroChosen"
          @close="heroPrompt = null"
        />

        <!-- Drop-in co-op: a game running here that you could still walk into. Framework-level
             rather than any panel's, because whether there's a seat is a question the service
             answers the same way for every game — `can_join` is the whole rule. -->
        <button
          v-if="game && inThisRoom && game.can_join"
          type="button"
          class="pointer-events-auto absolute left-1/2 top-3 z-20 flex -translate-x-1/2 items-center gap-1.5 rounded-full border bg-background/95 py-1.5 pl-3 pr-3.5 text-xs font-medium shadow-lg backdrop-blur transition hover:bg-muted"
          @click="onJoinGame"
        >
          <DoorOpen class="h-3.5 w-3.5 text-primary" />
          Join {{ game.label }}
        </button>

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
          <!-- The crawl draws its own world rather than the room's: it's the one game that isn't
               played on this map. It also does its own networking (positions and monsters over
               whispers), so all the stage passes it is the channel to whisper on. -->
          <ArpgPanel
            v-else-if="game.type === 'arpg'"
            :game="game"
            :channel-id="channel.id"
            :my-id="user?.id ?? null"
            @act="(action, payload) => actGame(action, payload)"
            @dismiss="onGameDismiss"
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

        <!-- The way back, on the edge the panel went out by. The header button does the same
             thing, but a rail you've hidden should be reachable from where it used to be rather
             than only from an icon three feet away. Narrow windows have the sheet's own button. -->
        <button
          v-if="inThisRoom && !narrow && !peopleShowing"
          type="button"
          class="absolute right-0 top-1/2 z-20 flex -translate-y-1/2 items-center gap-1 rounded-l-md border border-r-0 bg-background/90 py-2 pl-1.5 pr-1 text-[11px] text-muted-foreground shadow-sm transition hover:bg-muted hover:text-foreground"
          title="Show who's in earshot"
          @click="togglePeople"
        >
          <ChevronLeft class="h-3.5 w-3.5" />
          <span class="[writing-mode:vertical-rl]">In earshot</span>
          <!-- Somebody's sharing behind the panel you put away. -->
          <span v-if="someoneSharing" class="absolute -left-1 top-1 h-2 w-2 rounded-full bg-primary ring-2 ring-background" />
        </button>

        <!--
          Where a shared screen is watched, when it's watched big.

          The people rail is a rail: wide enough for a face in a circle and a volume slider,
          which makes the one thing in it that has detail — somebody's screen — a postage
          stamp. Fullscreen was the only way up from there, and fullscreen takes the room away
          entirely: you can't see who walked off, and you can't walk anywhere yourself.

          So the screen gets the top of the *room* instead, at the size the room can spare, with
          the bottom of the map still showing so proximity keeps meaning something. It's an empty
          box until {@link SideSpaceCallDock} teleports its stage in here, which it does by
          default the moment somebody shares; the dock's own button puts it back in the rail.
        -->
        <div id="space-screen-theater" class="pointer-events-none absolute inset-0 z-20" />
      </div>

      <!--
        Cameras, screens and the volume of everyone near you.

        Hidden rather than unmounted when you put it away, because the screen it's showing is
        teleported over the room and should keep playing after the panel it came from is gone —
        which is the whole point on a phone, where the panel covers the room and walking anywhere
        means closing it. It knows it's stowed; nothing else in it renders while it is.
      -->
      <SideSpaceCallDock
        v-if="inThisRoom"
        v-show="peopleShowing"
        :class="narrow ? 'absolute inset-0 z-30' : 'shrink-0'"
        :style="narrow ? undefined : { width: `${peopleWidth}px` }"
        :can-moderate="canModerate"
        :sheet="narrow"
        :stowed="!peopleShowing"
        @close="togglePeople"
        @resize="startPeopleResize"
      />
    </div>

    <!-- The way back to the conversation. Same pill an app channel uses. -->
    <ChatTogglePill v-model="chatHidden" />

    <!-- Drag the room's bottom edge to trade height with the conversation. Pointless when the
         chat is hidden, since there is nothing to trade against. -->
    <ResizeHandle v-if="!chatHidden" edge="bottom" @resize="startResize" />

    <!--
      Keyed on the map, so walking into another of this Side Space's rooms rebuilds the editor
      around it. Without the key the panel would keep the draft it built from the map it opened
      on — every ref in it is seeded from the prop at setup — and the next Save would write the
      lobby's grid into the cinema.
    -->
    <SideSpaceMapEditor
      v-if="editing && map"
      :key="map.slug"
      :channel-id="channel.id"
      :map="map"
      :mode="editing"
      @close="editing = null"
      @saved="onMapSaved"
      @open="onEditorOpenMap"
      @rooms-changed="refreshMap()"
    />

    <!-- A painting, full size. Over everything, and the room keeps running behind it. -->
    <SpaceExhibitViewer
      v-if="viewingPiece"
      :piece="viewingPiece"
      @close="viewingPiece = null"
    />

    <SpaceAppearanceDialog
      v-if="dressing"
      @close="dressing = false"
      @saved="onDressed"
    />

    <SpaceShoutDialog
      v-if="shouting"
      @close="shouting = false"
      @saved="onShouted"
    />

    <SpaceDoorPasswordDialog
      v-if="askingDoor"
      :door-id="askingDoor"
      :channel-id="channel.id"
      :map-slug="mapSlug"
      :room-name="blockedRoomName"
      @entered="onEnteredDoor"
      @close="askingDoor = null"
    />

    <SpaceLocksDialog
      v-if="managingLocks && map"
      :channel-id="channel.id"
      :map="map"
      :members="roomMembers"
      @close="managingLocks = false"
    />
  </div>
</template>

<style scoped>
/*
 * The share, once it *is* the whole display.
 *
 * The element is a transparent pixel the rest of the time — it exists so the canvas has a frame
 * to copy — and none of that stops applying when it goes fullscreen. Which is the bug this
 * fixes: `requestFullscreen` on an `opacity: 0` element a pixel across gives you a perfectly
 * working fullscreen video that is invisible, i.e. a black screen.
 *
 * A `:fullscreen` rule rather than a bound class because the element can leave fullscreen without
 * us asking — Escape, the window manager, another element taking it — and a class would have to
 * be unset from a listener for every one of those. The pseudo-class is true exactly when it is
 * true.
 *
 * The `!important`s are load-bearing against Tailwind: `.opacity-0` and `.h-px` are author styles
 * of the same specificity order, and the UA's own fullscreen sizing loses to both.
 */
.space-share:fullscreen {
  width: 100vw !important;
  height: 100vh !important;
  opacity: 1 !important;
  /* Letterboxed, not stretched — a share is whatever shape somebody's monitor is. */
  object-fit: contain;
  background: #000;
  /* It is the whole screen now, so it may as well take its own clicks. */
  pointer-events: auto !important;
}
</style>
