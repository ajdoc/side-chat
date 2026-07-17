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

/** A member who can be @mentioned in a channel — the shape the composer autocomplete needs. */
export interface ChannelMember {
  id: number
  name: string
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
  url: string
  download_url: string
  uploaded_by?: string | null
  created_at: string
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
  body: string | null
  type: 'user' | 'system'
  edited: boolean
  pinned: boolean
  pinned_at: string | null
  /** Who pinned it. Only sent where it's shown — the Pinned tab, not the timeline. */
  pinned_by?: string | null
  user: User
  attachments?: Attachment[]
  reactions?: Reaction[]
  /** Arrives empty and fills in over the websocket once the unfurl job finishes. */
  link_previews?: LinkPreview[]
  reply_to?: ReplyRef | null
  started_thread?: StartedThread | null
  created_at: string
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
  message_id: number | null
  name: string
  replies_count?: number
  creator?: User
  parent_message?: Message | null
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
  /** 0–1, applied to their audio element alone. */
  volume: number
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
