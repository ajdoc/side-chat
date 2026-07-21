export type ThemeMode = 'light' | 'dark' | 'system'
// Each one drives the whole palette (surfaces, borders, hovers), not just the
// accent — see the accent registry in assets/css/tailwind.css.
export type ThemeColor = 'slate' | 'blue' | 'violet' | 'rose' | 'red' | 'amber' | 'green' | 'teal'

export interface User {
  id: number
  name: string
  email: string
  avatar: string | null
  provider: string | null
  theme_mode: ThemeMode
  theme_color: ThemeColor
  created_at: string
}

export interface AuthResponse {
  user: User
  token: string
  token_type: string
}

export type ChannelType = 'text' | 'voice'

export interface Channel {
  id: number
  /** Null when this channel belongs to a conversation rather than a server. */
  server_id: number | null
  conversation_id: number | null
  name: string
  type: ChannelType
  position: number
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

export interface Server {
  id: number
  name: string
  owner_id: number
  is_owner: boolean
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
  type: 'user' | 'system' | 'widget'
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
  created_at: string
}

/**
 * An interactive, channel-shared widget — the music player, the kanban board — rendered
 * as a live card in the timeline and kept in sync over the channel's Reverb stream.
 * `state` is discriminated by `type`; the matching card component owns its shape.
 */
export interface Widget {
  id: number
  channel_id: number
  type: 'music' | 'kanban' | 'poll' | 'shooter' | 'racing' | 'skribbl'
  /**
   * The live state — present on HTTP responses. Absent when the widget arrives as a
   * *reference* over the socket (WidgetUpdated / a MessageSent card): its full state is
   * too big for Pusher's 10KB event cap, so the client fetches it from `/api/widgets/{id}`.
   */
  state?: MusicState | KanbanState | PollState | ShooterState | RacingState | SkribblState
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

/** The apps a Side Space houses, each a tab: whiteboard, notes, documents, widget canvas. */
export type SideSpaceAppId = 'board' | 'notes' | 'docs' | 'canvas'

/**
 * A Side Space note: one shared markdown document per surface, edited collaboratively with
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

/** The kinds of card the Open Canvas holds. `widget` places one of the interactive widgets. */
export type CanvasItemKind = 'note' | 'todo' | 'widget'

/** One entry in a `todo` card's checklist. `id` is a client-minted uuid, stable across saves. */
export interface CanvasTodoEntry {
  id: string
  text: string
  done: boolean
}

/**
 * One card on a Side Space's Open Canvas — a markdown note or a checklist, freely placed on
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
 * A file on a Side Space's Docs app. Bytes live on a private disk; `url`/`download_url` are
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
  joined_at: string
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
  localMuted: boolean
  /** 0–1, applied to their microphone audio element alone. */
  volume: number
  /**
   * 0–1, applied to the audio *of what they're sharing* — kept apart from `volume` so a
   * loud shared video can be turned down without also quietening the person talking over it.
   */
  screenVolume: number
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
