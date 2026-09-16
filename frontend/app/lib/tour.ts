/**
 * The guided tour: what it points at, and where the bubble goes.
 *
 * The steps live here rather than inside the overlay component because they are *content* —
 * a list of places in the app and a sentence about each — and because the two hard parts of
 * a tour are both pure functions over rectangles, which is to say testable without a DOM.
 *
 * Every step aims at a `data-tour` hook rather than a class or a nth-child path. Styling
 * changes constantly; a named hook is a promise that this element is the thing being taught,
 * and it survives a Tailwind rewrite.
 */

export interface Tour {
  /** Stable id. It names the "seen" cookie, so renaming one re-offers the tour to everybody. */
  id: string
  /** How the tour is listed in the account menu. */
  label: string
  steps: TourStep[]
}

export interface TourStep {
  /** Value of the `data-tour` attribute to spotlight. */
  target: string
  title: string
  body: string
  /**
   * Preferred side to hang the bubble on. Only a preference: `placePopover` overrides it
   * when that side would push the bubble off-screen.
   */
  side?: Side
  /**
   * Spotlight every element carrying the hook, as one rectangle around the lot, rather than
   * the first.
   *
   * For a step whose subject is a *set* — the channel rows, where the lesson is the way they
   * differ from each other. Lighting one row and talking about four teaches nothing; lighting
   * the run of them puts the icons side by side, which is the whole point.
   */
  spotlight?: 'first' | 'all'
}

export type Side = 'top' | 'right' | 'bottom' | 'left'

export interface Rect { x: number; y: number; width: number; height: number }

/**
 * The tour of the app shell.
 *
 * Deliberately short. A tour long enough to explain everything is a tour nobody finishes,
 * and the things worth interrupting someone for are the ones they would otherwise never
 * find: that chats and servers are different kinds of place, that ⌘K searches everything,
 * and that the account button at the foot of the sidebar is where settings live.
 */
const APP_SHELL_STEPS: TourStep[] = [
  {
    target: 'sidebar',
    title: 'Your places',
    body: 'Direct messages and group chats sit at the top; servers — shared spaces with channels inside them — sit below. Only the server you are in stays expanded, so this list stays readable.',
    side: 'right',
  },
  {
    target: 'channel-row',
    spotlight: 'all',
    title: 'Four kinds of channel',
    body: 'The icon says what a channel is before you open it. # is a timeline. A speaker is a call you drop into, with a timeline underneath. A map is a Side Space — a room you walk around, where you hear whoever is near you. Any other icon is an app channel, wearing its own app’s.',
    side: 'right',
  },
  {
    target: 'search',
    title: 'Search everything',
    body: 'Messages, channels, files and people, from one box. ⌘K opens it from anywhere, including mid-conversation.',
    side: 'bottom',
  },
  {
    target: 'add-server',
    title: 'Start something new',
    body: 'Create a server for a community, or open a chat with one person. Invites you receive land here too.',
    side: 'right',
  },
  {
    target: 'composer',
    title: 'Say something',
    body: 'Write, drop in files, or type / for commands. Messages send with Enter and Shift+Enter starts a new line.',
    side: 'top',
  },
  {
    target: 'account',
    title: 'You, and your settings',
    body: 'Theme, profile, notifications and encryption keys live here. You can replay this tour from the same menu whenever you like.',
    side: 'top',
  },
]


/**
 * The tour of a voice call.
 *
 * Offered the first time you're actually *in* one, not on the page beside it: half of what
 * there is to learn — the stage, the control row, the bar that follows you out — doesn't exist
 * until you've joined, and a tour of the join button is one step long.
 */
const VOICE_STEPS: TourStep[] = [
  {
    target: 'voice-join',
    title: 'Come in and talk',
    body: 'Joining opens a live call in this channel. You arrive with your microphone on, and nobody has to accept anything — voice channels are rooms you walk into, not calls that ring.',
    side: 'bottom',
  },
  {
    target: 'voice-roster',
    title: 'Who is in here',
    body: 'Everyone in the call, with a mark against anyone muted, deafened, or sharing something. It is visible before you join, so you can see whether it is worth coming in.',
    side: 'bottom',
  },
  {
    target: 'voice-stage',
    title: 'The stage',
    body: 'Screens and cameras you have chosen to watch, up to four side by side. Pick who lands here from the row beneath it — watching is your choice, not the sharer’s.',
    side: 'top',
  },
  {
    target: 'voice-controls',
    title: 'Mic, camera, screen',
    body: 'Mute, deafen, turn your camera on, or share a screen — or share sound alone for music and video. The gear beside them picks which microphone and camera get used.',
    side: 'top',
  },
  {
    target: 'voice-bar',
    title: 'The call follows you',
    body: 'Walking off to another channel does not hang up. This bar keeps the call — and your mute, camera and leave buttons — wherever you wander.',
    side: 'right',
  },
]

/**
 * The tour of a Side Space, kept to the basics.
 *
 * A Side Space breaks the rules the rest of the app taught: you are a character on a map,
 * whether you can be heard depends on where you are standing, and the furniture does things.
 * None of that is guessable from a chat app, which is why this tour exists and why it stops
 * at the basics — portals, exhibits, games and the map editor can wait until somebody has
 * managed to walk across the room.
 */
const SIDE_SPACE_STEPS: TourStep[] = [
  {
    target: 'space-enter',
    title: 'Step inside',
    body: 'A Side Space is a room you stand in rather than a channel you read. Entering drops your character onto the map and puts you in the room’s call.',
    side: 'bottom',
  },
  {
    target: 'space-room',
    title: 'Walk around',
    body: 'Click where you want to go, or steer with WASD and the arrow keys. Voice here is proximity-based: people near you are loud, people across the room fade out, so wandering over to someone is how you start a conversation.',
    side: 'top',
  },
  {
    target: 'space-mic',
    title: 'You arrive muted',
    body: 'Walking into a room can never open a hot mic, so your microphone starts off. This is the switch — and while you are muted a reminder sits over the room so you cannot mistake silence for being heard.',
    side: 'bottom',
  },
  {
    target: 'space-people',
    title: 'Who is in earshot',
    body: 'Faces, screens and per-person volume for everyone near you. A dot appears here when somebody nearby puts a screen or a track on.',
    side: 'bottom',
  },
  {
    target: 'space-appearance',
    title: 'Make it yours',
    body: 'Choose how your character looks and pick a companion to trail after you. Beside it: shout something over your head, and toggle whether chat appears as bubbles in the room.',
    side: 'bottom',
  },
  {
    target: 'space-minimap',
    title: 'Where you are',
    body: 'Bigger rooms get an overview in the corner, with zoom just above it. Handy when the map is larger than the window.',
    side: 'left',
  },
  {
    target: 'space-leave',
    title: 'Stand in front of things',
    body: 'Screens, chairs, doorways and games all react when you walk up and press E — a pill appears at the bottom of the room telling you what the key will do. Leave puts you back outside without leaving the channel.',
    side: 'bottom',
  },
]

export const APP_SHELL_TOUR: Tour = { id: 'app-shell', label: 'The app', steps: APP_SHELL_STEPS }
export const VOICE_TOUR: Tour = { id: 'voice', label: 'Voice calls', steps: VOICE_STEPS }
export const SIDE_SPACE_TOUR: Tour = { id: 'side-space', label: 'Side Spaces', steps: SIDE_SPACE_STEPS }

/** Every tour, in the order the account menu lists them. */
export const TOURS: Tour[] = [APP_SHELL_TOUR, VOICE_TOUR, SIDE_SPACE_TOUR]

/**
 * Drop the steps whose target isn't on screen.
 *
 * The shell is not the same on every route — there's no composer until you open a
 * conversation, and no "add a server" row once the list is long — and a tour that spotlights
 * nothing is worse than one that is a step shorter. So resolution happens at *start* time
 * against the page the user is actually on, and the step count they're shown is the real one.
 */
export function visibleSteps<T>(steps: TourStep[], find: (target: string) => T | null): TourStep[] {
  return steps.filter(s => find(s.target) != null)
}

/** Grow a rect on all sides, clamped to non-negative origin — the spotlight's breathing room. */
export function inflate(rect: Rect, padding: number): Rect {
  return {
    x: Math.max(0, rect.x - padding),
    y: Math.max(0, rect.y - padding),
    width: rect.width + padding * 2,
    height: rect.height + padding * 2,
  }
}


/**
 * The smallest rectangle containing all of them.
 *
 * Used for a `spotlight: 'all'` step. The elements are always siblings in a list, so the union
 * is tight — this is not a licence to spotlight two things at opposite ends of the screen.
 */
export function unionRect(rects: Rect[]): Rect | null {
  if (!rects.length) return null
  const left = Math.min(...rects.map(r => r.x))
  const top = Math.min(...rects.map(r => r.y))
  const right = Math.max(...rects.map(r => r.x + r.width))
  const bottom = Math.max(...rects.map(r => r.y + r.height))
  return { x: left, y: top, width: right - left, height: bottom - top }
}

/**
 * Where to put the bubble, given the hole it must not cover.
 *
 * Tries the preferred side, then the opposite one, then the remaining two — first side with
 * room wins. If none has room (a target filling the viewport on a phone), it falls back to
 * the preferred side anyway and the clamp below keeps the bubble on screen: overlapping the
 * spotlight is bad, but a bubble half off the edge with its buttons unreachable is worse.
 */
export function placePopover(
  target: Rect,
  popover: { width: number; height: number },
  viewport: { width: number; height: number },
  side: Side = 'bottom',
  gap = 12,
): { x: number; y: number; side: Side } {
  const opposite: Record<Side, Side> = { top: 'bottom', bottom: 'top', left: 'right', right: 'left' }
  const fits: Record<Side, boolean> = {
    top: target.y - gap - popover.height >= 0,
    bottom: target.y + target.height + gap + popover.height <= viewport.height,
    left: target.x - gap - popover.width >= 0,
    right: target.x + target.width + gap + popover.width <= viewport.width,
  }
  const order: Side[] = [side, opposite[side], ...(['top', 'bottom', 'left', 'right'] as Side[])]
  const chosen = order.find(s => fits[s]) ?? side

  let x: number
  let y: number
  if (chosen === 'top' || chosen === 'bottom') {
    // Centred on the target, so the bubble reads as belonging to it.
    x = target.x + target.width / 2 - popover.width / 2
    y = chosen === 'top' ? target.y - gap - popover.height : target.y + target.height + gap
  } else {
    x = chosen === 'left' ? target.x - gap - popover.width : target.x + target.width + gap
    y = target.y + target.height / 2 - popover.height / 2
  }

  return {
    x: clamp(x, gap, Math.max(gap, viewport.width - popover.width - gap)),
    y: clamp(y, gap, Math.max(gap, viewport.height - popover.height - gap)),
    side: chosen,
  }
}

function clamp(v: number, lo: number, hi: number) {
  return Math.min(Math.max(v, lo), hi)
}
