import type { AvatarLook } from '~/lib/spaceAvatar'
import type { PetKind } from '~/lib/spacePets'

export type ThemeMode = 'light' | 'dark' | 'system'
// Each one drives the whole palette (surfaces, borders, hovers), not just the
// accent — see the accent registry in assets/css/tailwind.css.
export type ThemeColor = 'slate' | 'blue' | 'violet' | 'rose' | 'red' | 'amber' | 'green' | 'teal'

/**
 * A `/command` callable in the channel you're looking at.
 *
 * Fetched per channel rather than held as a constant, because the list genuinely differs
 * between channels: bots register their own commands, and a bot that isn't in this channel
 * can't be called from it. `bot` names whose command it is, or null for a built-in.
 */
export interface SlashCommand {
  name: string
  description: string | null
  usage: string | null
  bot: string | null
}

export interface User {
  id: number
  name: string
  email: string
  avatar: string | null
  provider: string | null
  /**
   * An automated account rather than a person: it posts through the bot API with a token its
   * server's owner issued. Everything else about it is an ordinary user, which is why the
   * flag has to be rendered — see BotBadge.
   */
  is_bot: boolean
  theme_mode: ThemeMode
  theme_color: ThemeColor
  /**
   * How this person is drawn walking around a Side Space, and what's following them.
   *
   * Always a complete look — the server fills in anything unchosen — because every client
   * draws a sprite for everybody, and "hasn't picked yet" has to arrive as something drawable
   * rather than as an absence each of them handles differently. See lib/spaceAvatar.
   */
  space_avatar: AvatarLook
  space_pet: PetKind | null
  /** The short line they've left hanging over their head, or null for none. */
  space_shout: string | null
  created_at: string
}

export interface AuthResponse {
  user: User
  token: string
  token_type: string
}

/**
 * `space` is a Side Space — a room you walk an avatar around, hearing whoever is near you.
 * Like `voice` it's a text channel that also holds a call, so everything addressed by channel
 * id works in one unchanged; only the surface above the timeline differs.
 */
export type ChannelType = 'text' | 'voice' | 'space'

export interface Channel {
  id: number
  /** Null when this channel belongs to a conversation rather than a server. */
  server_id: number | null
  conversation_id: number | null
  name: string
  type: ChannelType
  position: number
  /** Restricted to an allow-list rather than open to the whole server. */
  is_private?: boolean
  /** Who is on that allow-list. Only ever fetched by staff, from the access endpoint. */
  member_ids?: number[]
  /** Messages from other people you haven't read. Only present on the channel list. */
  unread_count?: number
  /** An unread here named you (by @you or @all) — badge it louder than a plain unread. */
  mention?: boolean
  created_at: string
}

/**
 * A member of a channel. The composer's @-mention autocomplete only reads id/name/avatar;
 * the Info panel's participant list also shows `email`.
 */
export interface ChannelMember {
  id: number
  name: string
  email: string
  avatar: string | null
  /** This server's badges for them. Empty in a DM or group chat, which have no server. */
  badges?: MemberBadge[]
  /** Server channels only — a chat has no roles, and the field is simply 'member' there. */
  role?: ServerRole
}

export type ConversationType = 'dm' | 'group'

/**
 * A DM or a group chat.
 *
 * `channel_id` is the whole design in one field: a conversation owns a channel, and every
 * message, thread, reaction, pin, attachment and call endpoint in the app is addressed by
 * channel id. Which is why none of those composables needed a single line changing to work
 * in a DM — `useMessages(channel_id)` simply doesn't care what the channel belongs to.
 *
 * Note there's no `title`. A DM is called "Ana" to you and "Ben" to Ana, so a title baked
 * into the payload would be wrong for half the people who received it — and this payload
 * *is* broadcast. The client does that subtraction itself; see `conversationTitle()`.
 */
export interface Conversation {
  id: number
  type: ConversationType
  /** Groups only. A DM has no name of its own. */
  name: string | null
  owner_id: number | null
  channel_id: number
  members: User[]
  /** Somebody is in a call in here, right now. */
  call_active: boolean
  call_started_at: string | null
  call_started_by: number | null
  unread_count?: number
  /** An unread here named you (by @you or @all). */
  mention?: boolean
  last_message_at?: string | null
  created_at: string
}

/** A ringing phone: someone started a call in a chat you're in. */
export interface IncomingCall {
  conversation: Conversation
  caller: User
}

/** What somebody may be in a server. 'owner' isn't a stored role — see the backend model. */
export type ServerRole = 'owner' | 'admin' | 'member'

export interface Server {
  id: number
  name: string
  owner_id: number
  is_owner: boolean
  /** What *you* are here: 'owner', 'admin' or 'member'. */
  role?: ServerRole
  /** Owner or admin — the gate on every setting the two share. */
  is_staff?: boolean
  invite_code: string
  invite_url: string
  pending_requests_count?: number
  channels?: Channel[]
  created_at: string
}

export interface StartedThread {
  id: number
  name: string
  replies_count: number
}

export interface ReplyRef {
  id: number
  body: string
  // The id rides along with the name so the author can be shown under whatever they're
  // called in this server or chat — see useNicknames.
  user_id: number | null
  user_name: string | null
}

export interface Attachment {
  id: number
  message_id: number
  name: string
  mime_type: string
  extension: string | null
  size: number
  is_image: boolean
  is_pdf: boolean
  is_gif: boolean
  url: string
  download_url: string
  uploaded_by?: string | null
  created_at: string
}

/** One GIF from a picker provider (Giphy, Klipy), as returned by /api/gifs/*. */
export interface GifResult {
  id: string
  /** Full GIF media — what gets sent and stored as a remote attachment. */
  url: string
  /** Small thumbnail for the picker grid. */
  preview_url: string
  width: number
  height: number
  title: string
  /** Which provider served this result — 'giphy' | 'klipy'. */
  provider: string
}

/**
 * One emoji on one message, with everyone who used it. The API deliberately doesn't
 * say whether *you* reacted — the same payload is broadcast to every subscriber — so
 * the UI works that out by looking for itself in `users`.
 */
export interface Reaction {
  emoji: string
  count: number
  users: { id: number, name: string }[]
}

/**
 * A "popular comment" chip: one phrase, everyone who left it, and the count. Like Reaction,
 * the API doesn't say whether *you* left it — the same payload is broadcast to everyone — so
 * the UI works that out by looking for itself in `users`. `key` is a stable id for the phrase
 * (normalized body + emoji), used both for :key and to re-post the exact phrase on a toggle.
 */
export interface CommentSummary {
  key: string
  body: string
  emoji: string | null
  count: number
  users: { id: number, name: string }[]
}

/** One comment as it appears in the full list behind the chips. */
export interface Comment {
  id: number
  message_id: number
  body: string
  emoji: string | null
  user: User
  created_at: string
}

export interface LinkPreview {
  id: number
  url: string
  /** `image` renders the image itself; `link` renders an Open Graph card. */
  kind: 'link' | 'image'
  title: string | null
  description: string | null
  site_name: string | null
  image_url: string | null
}

export interface Message {
  id: number
  channel_id: number
  thread_id: number | null
  side_chat_id: number | null
  body: string | null
  /**
   * What kind of card this is, and `null` for the ordinary case — somebody talking.
   *
   * The column defaults to `'user'`, but a plain message is written with no type at all, so
   * that is what the API sends and `'user'` is a value you will almost never see. Ask what
   * this *isn't* ("not system, not widget"), the way the models and MessageItem do; testing
   * for `'user'` looks equivalent and silently matches nothing.
   */
  type: 'user' | 'system' | 'widget' | null
  /**
   * A top-level reply to the side chat *post* — addressed at its title, not at another
   * message. Distinct from `reply_to`, which names a message and shows its author and body.
   */
  replies_to_post?: boolean
  edited: boolean
  pinned: boolean
  pinned_at: string | null
  /** Marked as a recorded decision (side-chat messages only). */
  decided?: boolean
  /** Who pinned it. Only sent where it's shown — the Pinned tab, not the timeline. */
  pinned_by?: string | null
  user: User
  attachments?: Attachment[]
  reactions?: Reaction[]
  /** Aggregated "popular comment" chips. Absent until the message carries any. */
  comments?: CommentSummary[]
  /** Arrives empty and fills in over the websocket once the unfurl job finishes. */
  link_previews?: LinkPreview[]
  reply_to?: ReplyRef | null
  /** Set when this message was forwarded — names the original author for the "Forwarded from" line. */
  forwarded_from?: { user_id: number | null, user_name: string | null } | null
  started_thread?: StartedThread | null
  /** The living-object card for a side chat spun off this message (channel timeline only). */
  started_side_chat?: SideChat | null
  /** The interactive widget this message renders — only present on `type: 'widget'` cards. */
  widget?: Widget | null
  /**
   * A Side Desk app to pop into a floating window, carried only by the ephemeral answer to an
   * `a!<app>` command and only to the client that typed it. See useMessages.send.
   */
  open_app?: SideDeskAppId
  created_at: string
}

/**
 * An interactive, channel-shared widget — the music player, the kanban board — rendered
 * as a live card in the timeline and kept in sync over the channel's Reverb stream.
 * `state` is discriminated by `type`; the matching card component owns its shape.
 */
/**
 * The kinds of interactive widget there are.
 *
 * Named as its own type because it's now load-bearing in three places: a widget itself, a
 * `widget` canvas card, and — since widgets were promoted to full Side Desk apps — a tab. One
 * union keeps those from drifting apart. Must stay in step with `App\Support\DeskApps`.
 */
export type WidgetType = 'music' | 'video' | 'kanban' | 'poll' | 'shooter' | 'racing' | 'skribbl' | 'poker'

export interface Widget {
  id: number
  channel_id: number
  type: WidgetType
  /**
   * The live state — present on HTTP responses. Absent when the widget arrives as a
   * *reference* over the socket (WidgetUpdated / a MessageSent card): its full state is
   * too big for Pusher's 10KB event cap, so the client fetches it from `/api/widgets/{id}`.
   */
  state?: MusicState | VideoState | KanbanState | PollState | ShooterState | RacingState | SkribblState | PokerState
  created_at?: string
}

export interface MusicTrack {
  id: string
  /** Null for a Spotify shell until it's resolved to a YouTube video (lazily, when it plays). */
  videoId: string | null
  /** `spotify:track:…` for Spotify-sourced tracks — Premium listeners play this directly. */
  spotifyUri?: string | null
  title: string
  artist: string | null
  /** Length in seconds — may be null until a client backfills it from its player. */
  duration: number | null
  thumbnail: string | null
  /** Where the link came from (playback is always YouTube). */
  source: 'youtube' | 'spotify' | 'soundcloud' | 'deezer'
  /** Set when a shell couldn't be matched on YouTube — shown greyed out, skipped on play. */
  unresolved?: boolean
  addedBy: string
}

/** The search picker: top matches awaiting a choice, shown in the card. */
export interface MusicSearch {
  query: string
  by: string
  results: MusicTrack[]
}

export interface MusicState {
  status: 'idle' | 'playing' | 'paused'
  queue: MusicTrack[]
  /** Index into `queue` of the current track, or null when idle. */
  currentIndex: number | null
  /** Seconds into the current track at `updated_at` — clients extrapolate from here (× speed). */
  position: number
  updated_at: string
  loop: 'off' | 'track' | 'queue'
  /** Playback rate, 0.5–2. Shared, so everyone stays in sync; >1 is the "nightcore" effect. */
  speed: number
  /** Radio mode: keep going with a related track when the queue empties. */
  autoplay: boolean
  pendingSearch: MusicSearch | null
}

/**
 * Which player a video source needs, decided server-side by VideoResolver. This is the whole
 * reason the widget can be "universal": the card keeps one player of each kind and shows the
 * one the current source asks for.
 *
 * - `youtube` — the IFrame Player API. Driveable, so the room stays in lockstep.
 * - `file`    — a plain <video>: an uploaded clip, or a direct link to .mp4/.webm/… Driveable.
 * - `embed`   — the provider's own iframe (Vimeo, Dailymotion, Twitch, Streamable, Google
 *               Drive). Everyone starts at the same offset and then it's on its own; a
 *               third-party iframe won't take a seek from us, and the card says so instead of
 *               faking it. Drive's preview takes no offset either, and is labelled harder.
 */
export type VideoKind = 'youtube' | 'file' | 'embed'

export interface VideoSource {
  id: string
  kind: VideoKind
  /** The provider's own id — a YouTube video id, a Vimeo id. Null for direct files. */
  key: string | null
  /**
   * What a <video> should open, for `kind: 'file'`. Both local kinds get a short-lived
   * *signed* URL minted per viewer (neither the disk path nor the attachment id leaves the
   * server), so don't cache it past a reload. Null when the file has gone — see `missing`.
   */
  url: string | null
  /** The iframe src, for `kind: 'embed'`. */
  embedUrl: string | null
  /**
   * Where it came from — drives the badge. Two are local: `upload` is a clip the widget hosts
   * itself, `attachment` one already posted in this chat and added by reference.
   */
  provider: 'youtube' | 'vimeo' | 'dailymotion' | 'twitch' | 'streamable' | 'drive' | 'direct' | 'upload' | 'attachment' | string
  title: string
  /** For a borrowed attachment, whoever posted it originally — `addedBy` is who queued it. */
  author: string | null
  /** Length in seconds — may be null until a viewer's player backfills it. */
  duration: number | null
  thumbnail: string | null
  /** A borrowed attachment whose message has since been deleted. Shown greyed out, unplayable. */
  missing?: boolean
  addedBy: string
}

/** A video already posted in this chat, as the card's "in this chat" picker lists it. */
export interface ChannelVideoFile {
  id: number
  name: string
  mime_type: string
  size: number
  uploaded_by?: string | null
  created_at?: string
}

/** The search picker: top YouTube matches awaiting a choice, shown in the card. */
export interface VideoSearch {
  query: string
  by: string
  results: VideoSource[]
}

/**
 * The watch-along video widget's shared state. Like {@link MusicState}, the server owns the
 * transport and is not a clock: `position` is where the current video was at `updated_at`, so
 * every viewer — including one who just arrived — extrapolates from there (× `speed`) and puts
 * their own player at the same spot.
 */
export interface VideoState {
  status: 'idle' | 'playing' | 'paused'
  playlist: VideoSource[]
  /** Index into `playlist` of what's on screen, or null when nothing is seated. */
  currentIndex: number | null
  position: number
  updated_at: string
  loop: 'off' | 'one' | 'all'
  /** Playback rate, 0.25–2. Shared, so the room stays together. */
  speed: number
  pendingSearch: VideoSearch | null
}

export interface KanbanCard {
  id: number
  text: string
  column: 'todo' | 'doing' | 'done'
  assignee: { id: number, name: string } | null
  addedBy: string
}

export interface KanbanState {
  seq: number
  cards: KanbanCard[]
}

export interface PollOption {
  id: number
  text: string
  /** Everyone who's picked this option — the tally is its length, and a voter can see their own pick. */
  voters: { id: number, name: string }[]
}

export interface PollState {
  seq: number
  question: string
  /** Let a voter pick more than one option; single-choice (the default) replaces their pick. */
  multi: boolean
  /** Voting is locked and the result stands. */
  closed: boolean
  options: PollOption[]
}

/** One pilot's spot on the Side Squadron leaderboard, keyed by user id in `players`. */
export interface ShooterPlayer {
  name: string
  kills: number
}

/**
 * The persisted, shared half of the co-op Galaga-style shooter ("Side Squadron"). The playable
 * game is a client-side canvas shooter (see CoopShooter + lib/squadronEngine); this state is
 * only what must survive a refresh and stay identical for everyone: the `seed` every client
 * spawns waves from, the team's `wave` high-water mark, the shared `teamLives` pool, and the
 * pooled `score` / per-player `kills`. Live teammate positions travel over whispers, not here.
 */
export interface ShooterState {
  status: 'idle' | 'active' | 'lost'
  wave: number
  seed: number
  score: number
  teamLives: number
  maxLives: number
  players: Record<string, ShooterPlayer>
  /** Recent events, newest last — the little raid feed on the card. */
  log: string[]
}

/** One driver's spot on the Side Grand Prix leaderboard, keyed by user id in `players`. */
export interface RacingPlayer {
  name: string
  /** Best lap in ms, or null until they've completed a lap. Only ever falls. */
  bestLap: number | null
  lapsDone: number
  finished: boolean
  /** Total race time in ms when they took the flag, if their client reported it. */
  finishMs: number | null
  /** Server-assigned finishing position (1 = first past the flag), or null until finished. */
  place: number | null
}

/**
 * The persisted, shared half of the co-op top-down racer ("Side Grand Prix"). The playable
 * game is a client-side canvas racer (see CoopRacer + lib/raceEngine); this state is only
 * what must survive a refresh and stay identical for everyone on the grid: the `seed` every
 * client builds the same track from, the `laps` the race runs for, and the pooled
 * leaderboard of best laps and finishing places. Live rival cars travel over whispers, not
 * here.
 */
export interface RacingState {
  status: 'idle' | 'racing' | 'finished'
  seed: number
  laps: number
  /** How many drivers have taken the flag — the next place to hand out. */
  finishers: number
  players: Record<string, RacingPlayer>
  /** Recent events, newest last — the little race feed on the card. */
  log: string[]
}

/** One player at the Skribbl table, keyed by user id in `players`. */
export interface SkribblPlayer {
  name: string
  score: number
}

/** A line in the guess feed. A correct guess never carries the word — `text` is the payoff line. */
export interface SkribblChatLine {
  name: string
  text: string
  /** They got it; the card shows this as a win, not a guess. */
  ok: boolean
  /** One letter off — worth a nudge without giving the answer away. */
  close: boolean
}

/**
 * The persisted, shared half of Side Skribbl (see SkribblGame + the SkribblWidget handler).
 * The picture itself never lives here — strokes travel client-to-client over whispers, like
 * the racer's ghost cars. This is the turn, the clock, the scoreboard, and the secret.
 *
 * `word` is the one field the server hands out selectively: while a turn is live only the
 * drawer's copy of the state carries it, and everyone else gets `null` plus `mask`. Don't
 * treat its absence as a bug — it's the game working.
 */
export interface SkribblState {
  status: 'idle' | 'drawing' | 'reveal' | 'over'
  /** 1-based turn number; also the token that makes `timeup`/`next` actions idempotent. */
  turn: number
  turns: number
  drawerId: number | null
  drawerName: string | null
  /** The word — only ever present for the drawer while drawing, and for all once revealed. */
  word: string | null
  /** The word as underscores, safe for the table to see. */
  mask: string | null
  /** Epoch ms the turn expires — the clock every client counts down against. */
  endsAt: number
  /** Epoch ms the reveal gives way to the next turn. */
  revealEndsAt: number
  /** The drawing rotation, in join order. */
  order: number[]
  /** Who's already guessed it this turn. */
  correct: number[]
  players: Record<string, SkribblPlayer>
  chat: SkribblChatLine[]
  log: string[]
}

/**
 * A whispered chunk of the drawer's pen — never touches Laravel. Coordinates are 0..1
 * fractions of the canvas so every screen redraws the same picture at its own size, and
 * segments of one stroke accumulate under its `s` id as the pen moves.
 */
export interface SkribblDrawMsg {
  /** The sender — receivers ignore anything not from the current drawer. */
  by: number
  /** Stroke id, unique within a turn. */
  s: number
  /** Stroke colour (an eraser is just the canvas colour). */
  c: string
  /** Stroke width, in the same 0..1 space as the points. */
  w: number
  /** Flat [x0,y0,x1,y1,…], appended to whatever's already under `s`. */
  p: number[]
}

/** A rival's whispered car position/state, as received off the channel's Reverb stream. */
export interface RaceGhostMsg {
  id: number
  name: string
  x: number
  y: number
  /** Heading in radians, for orienting the ghost car sprite. */
  a: number
  /** Which lap they're on, shown under their car. */
  lap: number
}

/** A teammate's whispered ship state, as received off the channel's Reverb stream. */
export interface RaidGhostMsg {
  id: number
  name: string
  /** Horizontal position as a 0..1 fraction of the play field's width (resolution-agnostic). */
  x: number
  hp: number
  /** 1 in the frame they fired, for a muzzle flash on their ship. */
  f?: 0 | 1
}

/** A link as it appears in the channel Info panel's Links tab. */
export interface ChannelLink extends LinkPreview {
  /** The message it was shared in — click through to jump to it. */
  message_id: number
  /** Set when it was shared inside a thread, where the channel timeline can't jump to it. */
  thread_id: number | null
  shared_by: string
  shared_at: string
}

/** The "message info" panel: who saw it, who hasn't, who reacted. */
export interface MessageInfo {
  message_id: number
  /** False for thread replies — read markers only ever point at the main timeline. */
  receipts_tracked: boolean
  seen_by: { user: User, read_at: string }[]
  not_seen_by: User[]
  reactions: Reaction[]
}

/** How far one member has read in a channel — the source of the seen-by avatars. */
export interface ChannelRead {
  channel_id: number
  user: User
  last_read_message_id: number | null
  read_at: string
}

export interface Thread {
  id: number
  channel_id: number
  /** Set when this thread belongs to a side chat's workspace rather than the channel at large. */
  side_chat_id?: number | null
  message_id: number | null
  name: string
  replies_count?: number
  creator?: User
  /** May *you* rename or delete it — the creator, or a server owner/admin. */
  can_manage?: boolean
  parent_message?: Message | null
  created_at: string
}

/** The kinds of mark on a side chat's shared whiteboard. */
export type WhiteboardStrokeKind = 'pen' | 'rect' | 'ellipse' | 'line' | 'arrow' | 'text' | 'note' | 'bg'

/**
 * The payload shape depends on `kind` and is the whiteboard engine's contract (see
 * `app/lib/whiteboardEngine.ts`), not the API's — the server passes it straight through.
 * All coordinates are in the board's logical space (fixed width, see `LOGICAL_WIDTH`).
 */
export interface WhiteboardStrokePayload {
  color?: string
  fill?: string
  width?: number
  text?: string
  points?: { x: number, y: number }[]
  x1?: number
  y1?: number
  x2?: number
  y2?: number
  x?: number
  y?: number
  /** Sticky-note side length (logical units). Absent = default size. */
  w?: number
}

export interface WhiteboardStroke {
  /** Server id once committed. Optimistic strokes carry a temporary negative id until then. */
  id: number
  kind: WhiteboardStrokeKind
  payload: WhiteboardStrokePayload
  /** The drawer's own id for this stroke, for reconciling the optimistic copy with the broadcast. */
  client_id: string
  user?: User
  created_at?: string
}

/**
 * The apps a Side Desk can house.
 *
 * Two families, and the split is the whole point of the design (see `useDeskApps`):
 *
 *   - **surface apps** own storage hanging off the surface — the board's strokes, the one
 *     shared note, the doc shelf, the calendar's events, the canvas's cards.
 *   - **widget apps** are the interactive widgets promoted to full tabs. They add no storage:
 *     a tab renders the channel's existing widget of that type, the very same row the timeline
 *     card and the canvas card render. That's why an app and its widget stay in step — there
 *     is only ever one of them.
 *
 * `canvas` is in the union but never in a stored list: the Open Canvas can't be removed.
 */
export type SideDeskSurfaceAppId = 'board' | 'notes' | 'docs' | 'canvas' | 'calendar'
export type SideDeskWidgetAppId = WidgetType
export type SideDeskAppId = SideDeskSurfaceAppId | SideDeskWidgetAppId

/**
 * What pressing E on a piece of Side Space furniture opens.
 *
 * The two families of Side Desk app answer differently, and the discriminator says which: a
 * widget app hands back the channel's widget whole (the same row the timeline card renders), a
 * surface app hands back only its name, because the board and the notes hang off the channel
 * rather than off a widget row. Both end up as a floating window — see SideSpaceStage.
 */
export type SpaceInteraction =
  | { type: 'widget', app: SideDeskWidgetAppId, data: Widget }
  | { type: 'app', app: SideDeskSurfaceAppId }

/** The named colours a calendar entry may wear; the palette they map to is the client's. */
export type CalendarEventColor = 'primary' | 'green' | 'amber' | 'rose' | 'violet' | 'teal' | 'slate'

/**
 * One entry on a Side Desk's shared Calendar.
 *
 * Times arrive as ISO-8601 UTC and are rendered in the viewer's own zone — a calendar shared
 * across zones has to agree on the instant, not the wall clock. `ends_at` is null for an entry
 * that's a moment rather than a span. An `all_day` entry still carries a real `starts_at` (UTC
 * midnight of its day) so one ordering serves both kinds.
 */
export interface CalendarEvent {
  id: number
  title: string
  description: string | null
  starts_at: string
  ends_at: string | null
  all_day: boolean
  color: CalendarEventColor
  user?: User
  created_at?: string
}

/**
 * A Side Desk note: one shared markdown document per surface, edited collaboratively with
 * last-write-wins. Addressed by its surface (channel or side chat), never on its own — so no
 * id. `updated_by` is who saved it last, for the "edited by" line.
 */
export interface SpaceNote {
  content: string
  /** The revision this body belongs to; echoed back on save so concurrent edits merge. */
  version: number
  updated_by: User | null
  updated_at: string | null
}

/**
 * The kinds of card the Open Canvas holds.
 *
 * `widget` places one of the interactive widgets. `board`, `notes` and `calendar` place a Side
 * Desk *app* — the mirror image of a widget promoted to a tab. Those three carry no content of
 * their own: the card is a window onto the surface's one board / one note / one calendar, so
 * editing it from the canvas and editing it from its tab are the same edit.
 */
export type CanvasItemKind = 'note' | 'todo' | 'widget' | 'board' | 'notes' | 'calendar'

/** One entry in a `todo` card's checklist. `id` is a client-minted uuid, stable across saves. */
export interface CanvasTodoEntry {
  id: string
  text: string
  done: boolean
}

/**
 * One card on a Side Desk's Open Canvas — a markdown note or a checklist, freely placed on
 * a surface's 2D board. `content` is kind-specific (see {@link CanvasNoteCard} / {@link
 * CanvasTodoCard}); `x`/`y`/`w`/`h` are the canvas's logical pixels and `z` is stack order.
 */
export interface CanvasItem {
  id: number
  kind: CanvasItemKind
  content: Record<string, any>
  x: number
  y: number
  w: number
  h: number
  z: number
  /** Present on `widget` cards: the interactive widget this card places, with its live state. */
  widget?: Widget
  user?: User
  created_at?: string
}

/** How the Docs app previews a file: PDF in an iframe, sheet/word via a viewer, else download. */
export type SpaceDocumentKind = 'pdf' | 'sheet' | 'word' | 'other'

/**
 * A file on a Side Desk's Docs app. Bytes live on a private disk; `url`/`download_url` are
 * short-lived signed links (like an {@link Attachment}'s), so they're re-fetched with the list
 * rather than held forever.
 */
export interface SpaceDocument {
  id: number
  name: string
  mime_type: string
  extension: string | null
  size: number
  kind: SpaceDocumentKind
  /** 'shelf' — uploaded to Docs (deletable, can be sent to chat). 'chat' — shared in a message. */
  source: 'shelf' | 'chat'
  /** The message a 'chat' document rode in on; null for 'shelf' documents. */
  message_id: number | null
  url: string
  download_url: string
  uploaded_by: User | null
  created_at: string
}

/**
 * A side chat: a mini room spun off a message, with its own roster and timeline. The
 * "living object" — its card in the main timeline carries the counts that keep it alive
 * (members, messages, pinned, decisions, last-active).
 *
 * `participant_ids` ships on every payload (unlike the full `participants`, which is only
 * loaded for the panel) so the client can decide, viewer by viewer, whether to show [Join]
 * or [Open] — the resource is broadcast to everyone, so a baked-in `joined` flag couldn't be.
 */
export interface SideChat {
  id: number
  channel_id: number
  message_id: number | null
  name: string
  /** The forum layer's labels — lowercase, deduped, server-normalised. */
  tags?: string[]
  /**
   * The group heading this post is filed under, or null for "Uncategorised".
   *
   * A group and a tag answer different questions: a tag says what the post is *about* and
   * any number may apply; a group says where in the list it lives and exactly one does.
   */
  side_chat_forum_id?: number | null
  /** Reactions to the *post*, same summary shape as a message's (see ReactionBar). */
  reactions?: Reaction[]
  /**
   * "Popular comment" chips on the *post* — feedback about the topic, as opposed to a
   * reply, which is a message in the side chat's own timeline.
   */
  comments?: CommentSummary[]
  /** May *you* retitle, retag or delete it — the OP, or a server owner/admin. */
  can_manage?: boolean
  creator?: User
  parent_message?: Message | null
  /** Frozen snapshot of the origin message, so "Started from" survives its deletion. */
  origin_author?: string | null
  origin_excerpt?: string | null
  participants?: User[]
  participant_ids?: number[]
  participants_count?: number
  messages_count?: number
  threads_count?: number
  pinned_count?: number
  decisions_count?: number
  last_active_at: string
  created_at: string
}

/**
 * A named group of side chats inside a channel — the heading the forum list folds under.
 *
 * "Uncategorised" is deliberately *not* one of these: it's the posts with no group at all,
 * synthesised by the list. That's why it can't be renamed, reordered or deleted, and why it
 * can never go missing.
 */
export interface SideChatForum {
  id: number
  channel_id: number
  name: string
  position: number
  created_at?: string
}

/**
 * Someone sitting in a voice channel, as the *server* sees them.
 *
 * Everything here is self-reported and identical for every viewer. How loud this person
 * is for you, and whether you've muted them, is a decision you made about your own
 * speakers — it is nobody else's business and never leaves your browser. See `Peer`.
 */
export interface VoiceParticipant {
  channel_id: number
  user: User
  muted: boolean
  deafened: boolean
  screen_sharing: boolean
  camera_on: boolean
  /** Sharing sound with nothing to look at — a track, or a video's audio. */
  audio_sharing: boolean
  /**
   * Where they were last known to be standing, in a Side Space; null everywhere else. Not the
   * live position — that's whispered — but what lets the room be drawn correctly the instant
   * you walk in, and what puts *you* back where you were after a reload.
   */
  x: number | null
  y: number | null
  facing: 'up' | 'down' | 'left' | 'right' | null
  joined_at: string
}

/**
 * What a room does when somebody arrives or leaves: drawn and synthesised in the browser,
 * so the wire only ever carries the name of one. Kept in step with Channel::VOICE_EFFECTS
 * on the backend, which is what actually refuses anything else.
 */
export type VoiceEffect = 'fireworks' | 'confetti' | 'sparkles'

/** What a call plays for one person, or — as `default` below — for anybody in particular. */
export interface VoiceEffectPair {
  join: VoiceEffect | null
  leave: VoiceEffect | null
}

/**
 * Everything a channel's call does about arrivals and departures.
 *
 * A default plus a list of exceptions, which is the shape of the feature: the owner can give
 * one person a fanfare without having decided anything about the other twenty members.
 */
export interface VoiceEffects {
  default: VoiceEffectPair
  people: (VoiceEffectPair & { user_id: number })[]
}

/** One effect going off right now, queued for the overlay to draw. See useVoiceEffects. */
export interface VoiceEffectEvent {
  /** Unique per firing, so two people arriving together animate as two separate effects. */
  id: number
  effect: VoiceEffect
  /** Arriving or leaving — the same effect plays outward for one and inward for the other. */
  phase: 'join' | 'leave'
  /** Who it's about, for the line of text under it. */
  name: string
}

/** Everything the browser needs to hand to RTCPeerConnection, served on join. */
export interface IceServer {
  urls: string | string[]
  username?: string
  credential?: string
}

export type PeerConnectionState = 'connecting' | 'connected' | 'failed'

/**
 * One other person in the call *you* are in — the live view, not the server's.
 *
 * The last two fields are the local half: they exist only in this tab, are never sent
 * anywhere, and are what "individually mute someone" and "turn someone down" actually
 * mean. `muted` (above, on VoiceParticipant) is them silencing their own microphone for
 * everybody; `localMuted` is you silencing them for yourself.
 */
export interface Peer {
  id: number
  name: string
  avatar: string | null
  /**
   * Their camera and their screen, kept apart.
   *
   * They arrive over two separately negotiated video slots precisely so they can be told
   * apart: someone presenting a screen while on camera has to appear in two places at once
   * — their face on their tile, their screen on the stage — and one merged stream makes
   * that impossible to render. See createPeer() in useVoice.
   */
  camera: MediaStream | null
  screen: MediaStream | null
  connection: PeerConnectionState
  speaking: boolean
  muted: boolean
  deafened: boolean
  screenSharing: boolean
  cameraOn: boolean
  /** Sharing sound and nothing else — there is no picture of theirs to watch. */
  audioSharing: boolean
  localMuted: boolean
  /** 0–1, applied to their microphone audio element alone. */
  volume: number
  /**
   * 0–1, applied to the audio *of what they're sharing* — their screen's sound or an
   * audio-only share, which arrive over the same slot. Kept apart from `volume` so a loud
   * shared video can be turned down without also quietening the person talking over it.
   */
  screenVolume: number
  /**
   * You've stopped listening to what they're sharing, while still listening to them. Local
   * like `localMuted`, and independent of it: silencing someone's music is not the same
   * decision as silencing them.
   */
  screenMuted: boolean
  /**
   * 0–1, how near they are — the Side Space's distance falloff, multiplied into `volume` on
   * their microphone element. 1 everywhere else, so a voice channel and a DM behave exactly as
   * they always did.
   *
   * Distinct from `volume` because the two mean different things and are owned by different
   * people: `volume` is a decision *you* made about them and is remembered across calls, while
   * this is a fact about where you are both standing and is recomputed every frame. Folding
   * proximity into `volume` would have the room quietly overwriting your preferences.
   */
  proximity: number
}

/**
 * A bot, as its server's owner sees it.
 *
 * Neither of its two secrets is ever in here. The API token and the webhook signing secret
 * are each shown exactly once — in the response that mints them — and after that they can
 * only be replaced, so there is nothing for this shape to carry.
 */
export interface Bot {
  id: number
  server_id: number
  description: string | null
  webhook_url: string | null
  events: string[]
  /** False once delivery has been switched off after too many failures. */
  webhook_enabled: boolean
  webhook_failures: number
  webhook_disabled_at: string | null
  last_used_at: string | null
  created_at: string
  /** The account it posts as — its name and avatar are edited through the bot. */
  user: User
  /** Who created it, or null if their account is gone. */
  created_by: string | null
  /** Whether this is the bot the server's automations speak as. One per server. */
  runs_automations?: boolean
}

/**
 * A "when X, do Y" rule, configured on the bot dashboard and run by the server.
 *
 * `actions` is ordered and complete — the list and the editor read the same object, because
 * a rule is small and a list that had to fetch each row to open it would flicker on
 * every edit.
 */
export interface Automation {
  id: number
  server_id: number
  name: string
  trigger: string
  trigger_config: Record<string, unknown>
  conditions: AutomationCondition[]
  enabled: boolean
  /**
   * Set for the rules that have a dashboard page of their own (the welcome message, a
   * reaction role). The generic list hides these; the feature page finds its row by it.
   */
  builtin: string | null
  run_count: number
  last_run_at: string | null
  created_at: string
  updated_at: string
  actions: AutomationActionRow[]
}

export interface AutomationActionRow {
  id?: number
  type: string
  config: Record<string, unknown>
  position?: number
}

export interface AutomationCondition {
  field: string
  operator: string
  value: unknown
}

/**
 * What the builder may offer, served by the backend rather than duplicated here.
 *
 * An action added in PHP appears in the UI on its own — the alternative is two lists that
 * agree until they don't.
 */
export interface AutomationCatalogue {
  triggers: { name: string, label: string, description: string, fields: string[] }[]
  actions: { name: string, label: string, schema: AutomationFieldSchema[] }[]
  operators: { name: string, label: string }[]
  /**
   * The pickers an action's schema can name, sent alongside so the builder never has to
   * know which field types imply an extra request.
   */
  commands: { id: number, name: string }[]
  schedules: { id: number, name: string }[]
  giveaways: { id: number, name: string }[]
}

/** One field of an action's form. `type` names a *picker*, not a primitive. */
export interface AutomationFieldSchema {
  key: string
  type: 'text' | 'textarea' | 'channel' | 'badge' | 'role' | 'command' | 'schedule' | 'giveaway' | 'number' | 'boolean'
  label: string
  required?: boolean
  help?: string
  options?: string[]
  placeholders?: string[]
}

/**
 * A badge as it rides along on a member in a roster — just enough to draw the pill.
 *
 * Deliberately not the full {@link Badge}: a roster has no use for the description or the
 * holder count, and sending them for every member of a large server would be waste.
 */
export interface MemberBadge {
  id: number
  name: string
  emoji: string | null
  color: string | null
}

/** A label a server hands out. Cosmetic and addressable — never a permission. */
export interface Badge {
  id: number
  server_id: number
  name: string
  emoji: string | null
  color: string | null
  description: string | null
  holders_count?: number
}

/** A canned answer a server declared for itself — `/rules`, `!ip`. */
export interface CustomCommand {
  id: number
  server_id: number
  name: string
  /** Which syntax answers to it: `/name`, `!name`, or both. */
  kind: 'slash' | 'prefix' | 'both'
  description: string | null
  response: string
  /** Only members holding this badge may run it. Null is anybody. */
  required_badge_id: number | null
  /** Per person, not per channel. Zero is no limit. */
  cooldown_seconds: number
  enabled: boolean
  use_count: number
}

/** A recurring post. `next_run_at` is stored, not recomputed on read. */
export interface BotSchedule {
  id: number
  server_id: number
  name: string
  /** Null falls back to the server's configured reminder channel. */
  channel_id: number | null
  body: string
  cron: string
  timezone: string
  enabled: boolean
  last_run_at: string | null
  next_run_at: string | null
}

/**
 * One reaction-role message, as the page shows it: a post and its emoji→badge pairs.
 *
 * Underneath it's two automations per pair (grant on react, revoke on un-react) — the API
 * regroups them so the screen doesn't have to know that.
 */
export interface ReactionRoleGroup {
  message_id: number
  channel_id: number
  pairs: { emoji: string, badge_id: number, badge_name: string | null }[]
}

export interface Giveaway {
  id: number
  server_id: number
  channel_id: number
  message_id: number | null
  prize: string
  emoji: string
  winner_count: number
  required_badge: string | null
  ends_at: string
  drawn_at: string | null
  /** Derived from the timestamps, not stored: running | ending | drawn | cancelled. */
  status: 'running' | 'ending' | 'drawn' | 'cancelled'
  entries_count: number
  winners: string[]
}

export interface BotSettings {
  command_prefix: string
  mod_log_channel_id: number | null
  announcement_channel_id: number | null
  reminder_channel_id: number | null
  /** Empty means nobody — moderation commands stay off until somebody says who has them. */
  mod_roles: ServerRole[]
}

/** The welcome message. A settings form on the outside, a `member.joined` rule inside. */
export interface BotWelcome {
  enabled: boolean
  channel_id: number | null
  body: string | null
}

export interface BotOverview {
  bot: Bot | null
  /** Whether the server has any bots at all — "none chosen" and "none exist" differ. */
  has_bots: boolean
  member_count: number
  channel_count: number
  automation_count: number
  enabled_automation_count: number
  badge_count: number
  recent: BotAuditLine[]
}

export interface BotAuditLine {
  id: number
  action: string
  /** 'ok' | 'failed' | 'skipped'. A skip is not a failure — it's "there was nothing to do". */
  outcome: 'ok' | 'failed' | 'skipped'
  message: string | null
  automation: string | null
  subject: string | null
  created_at: string
}

export interface ServerJoinRequest {
  id: number
  server_id: number
  user: User
  created_at: string
}

export interface InvitePreview {
  server: { id: number, name: string, members_count: number }
  status: 'none' | 'pending' | 'member'
}

/**
 * A game living in a Side Space — the framework half, the same for every game.
 *
 * Mirrors what `GameService::present` hands back: the public facts about the game plus this
 * viewer's *redacted* slice of its state. `state` is `null` while a game is only being voted on
 * (it has none yet) and typed per game once it's running — Among Us is the one below.
 */
export interface SpaceGamePayload {
  type: string
  label: string
  status: 'voting' | 'running' | 'ended'
  created_by: number | null
  /** Who was challenged, for a duel — null for a room-wide game. */
  opponent: number | null
  /** 'vote' (put to the room) or 'challenge' (aimed at one person). */
  start_mode: 'vote' | 'challenge'
  min_players: number
  /** Present only while a room-wide vote is open. */
  vote: { yes: number, present: number, mine: boolean | null } | null
  /** The game's own state, typed per game — null while only being proposed. */
  state: AmongUsState | PetBattleState | null
}

/** One entry of the propose menu — a game the room can be asked to play. */
export interface SpaceGameInfo {
  type: string
  label: string
  blurb: string
  /** How it starts: put to the room, or aimed at one person. */
  mode: 'vote' | 'challenge'
  min: number
  max: number
}

/** One fighter in a pet battle, everything about it on show — a battle keeps no secrets. */
export interface PetBattleFighter {
  id: number
  name: string
  pet: PetKind
  element: 'grass' | 'fire' | 'water'
  hp: number
  max_hp: number
  guarding: boolean
}

/** A turn-based pet battle, as any viewer sees it (the same for everyone but for `you`). */
export interface PetBattleState {
  /** The two fighters, in a stable left/right order. */
  players: PetBattleFighter[]
  /** Whose move it is. */
  turn: number | null
  round: number
  /** Which fighter you are, or null if you're only watching. */
  you: number | null
  log: string[]
  /** The winner's id, once there is one. */
  winner: number | null
}

/** A crewmate's task: a spot on the map to walk to and a flag for whether it's done. */
export interface AmongUsTask {
  id: string
  x: number
  y: number
  done: boolean
}

/**
 * Among Us's state, as *this* player is allowed to see it.
 *
 * The secrecy is in what's missing: `players[id].role` is null for anyone whose role you
 * haven't earned the right to know, and `my_tasks` is your list alone. `my_role` and
 * `my_cooldown` are yours; everything else is public.
 */
export interface AmongUsState {
  phase: 'play' | 'meeting'
  players: Record<number, { alive: boolean, role: 'crew' | 'impostor' | null }>
  my_role: 'crew' | 'impostor' | null
  my_tasks: AmongUsTask[]
  bodies: { user: number, x: number, y: number }[]
  /** Epoch ms your kill is free again, if you're an impostor. */
  my_cooldown: number | null
  meeting: {
    by: number
    reason: 'body' | 'emergency'
    ends_at: number
    /** Ids of players who have voted — not who they voted for. */
    voted: number[]
    /** Your own vote, once cast. */
    mine: number | 'skip' | null
  } | null
  log: string[]
  task_goal: number
  task_done: number
  winner: null | 'crew' | 'impostor'
}

/**
 * Where a search result was found.
 *
 * On a timeline a message needs no context — you're looking at the place it lives. Pulled
 * out of that place it's nearly useless without one, so every message result carries the
 * channel, the server or chat above it, and the branch it was said on.
 */
export interface SearchContext {
  channel_id: number
  channel_name: string
  channel_type: ChannelType
  server_id: number | null
  server_name: string | null
  conversation_id: number | null
  conversation_type: ConversationType | null
  /** A DM has no name — title it from these, exactly as the sidebar does. */
  conversation_members: User[] | null
  thread_id: number | null
  thread_name?: string | null
  side_chat_id: number | null
  side_chat_name?: string | null
}

/** A message as a search result: the ordinary payload plus where it came from. */
export interface SearchMessage extends Message {
  context: SearchContext
}

/**
 * A named place inside a channel, as a search result: a side chat, a thread, or the group a
 * side chat list folds under.
 *
 * One shape for three models because from the searcher's side they're three spellings of
 * the same thing — a titled place you open by clicking it. `kind` is what the client routes
 * on. See the backend's SearchSurfaceResource.
 */
export interface SearchSurface {
  kind: 'side_chat' | 'thread' | 'side_chat_group'
  id: number
  name: string
  channel_id: number
  channel_name: string
  server_id: number | null
  conversation_id: number | null
  /** A DM has no name — title it from these, exactly as the sidebar does. */
  conversation_members: User[] | null
  /** Side chats only: the group it's filed under, or null for Uncategorised. */
  group_id?: number | null
  group_name?: string | null
  /** Threads only: set when the thread belongs to a side chat rather than the channel. */
  side_chat_id?: number | null
  side_chat_name?: string | null
  created_at: string
}

/** The command palette's answer — a few of each kind, grouped, never interleaved. */
export interface SearchResults {
  conversations: Conversation[]
  channels: Channel[]
  side_chats: SearchSurface[]
  threads: SearchSurface[]
  side_chat_groups: SearchSurface[]
  servers: Server[]
  messages: SearchMessage[]
}

/** What `has:` may narrow a message search to. */
export type SearchHas = 'link' | 'file' | 'image'

/** The narrowing half of a search, as the filter chips express it. */
export interface SearchFilters {
  channel_id?: number
  server_id?: number
  conversation_id?: number
  /** A user id — the `from:` chip. */
  from?: number
  after?: string
  before?: string
  has?: SearchHas
}

/**
 * One seat at the Side Poker table, keyed by user id in `players`.
 *
 * `cards` is the field to read carefully: the server only ever sends you *your* hole cards.
 * Everyone else's arrive as an array of `null`s of the right length — enough to draw the
 * right number of face-down cards, and nothing more. At a showdown the players still in the
 * hand get real strings; folded hands stay face down, as they would on a table.
 */
export interface PokerPlayer {
  name: string
  /** Their stack, not counting what's already out in front of them this street. */
  chips: number
  /** Out in front of them on the current street. */
  bet: number
  /** Everything they've put in across the whole hand — what side pots are cut from. */
  committed: number
  folded: boolean
  allIn: boolean
  /** They've had their say on this street (a raise behind them takes this back). */
  acted: boolean
  /** Two cards: real ones if they're yours or face-up at a showdown, otherwise `null`s. */
  cards: (string | null)[]
  /** The English name of their made hand, filled in at the showdown. */
  hand: string | null
  /** Chips won in the hand just finished. */
  won: number
  /**
   * A house bot rather than a person — a seat with no account behind it, so that one player
   * can have a game. Its hole cards are hidden from everyone, including whoever added it.
   */
  bot: boolean
}

/**
 * The Side Poker table (see PokerTable + the PokerWidget handler).
 *
 * Unlike the other games, none of this game runs on the client: the deck, the turn order,
 * what a legal raise is and who won are all decided server-side, and the card only draws
 * what it's given. The `deck` field of the server's state is never sent to anyone at all.
 */
export interface PokerState {
  status: 'idle' | 'betting' | 'showdown'
  stage: 'preflop' | 'flop' | 'turn' | 'river'
  handNo: number
  /** Community cards — 0, 3, 4 or 5 of them, as `"As"`, `"Td"`. */
  board: string[]
  /** Chips already swept in from finished streets; the current street's bets sit on the players. */
  pot: number
  /** The amount to match on this street. */
  bet: number
  /** The smallest legal raise increment right now. */
  minRaise: number
  buttonId: number | null
  /** Whose decision the table is waiting on, or null between hands. */
  turnId: number | null
  /** Seating order, clockwise — blinds and turn order follow it. */
  seats: number[]
  players: Record<string, PokerPlayer>
  log: string[]
}
