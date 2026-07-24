/**
 * Who's online, and who's merely idle.
 *
 * Two halves, because they're known in two different places. **Online** is known by the server:
 * everyone joins one global presence channel (`online`), so Reverb keeps a roster and tells us
 * who's connected and when anyone arrives or drops — including the drop when a laptop lid closes,
 * which no heartbeat would catch as cleanly. **Idle** is known only by the browser: the server
 * can't tell that someone stopped touching the keyboard, so each client watches its own activity
 * and *whispers* its status (active/idle) to everyone else over that same presence channel. No
 * HTTP, no rows — presence lifecycle for the hard part, a peer-to-peer whisper for the soft one.
 *
 * Module-scoped on purpose: one subscription and one set of activity listeners per tab, shared by
 * every avatar that wants a dot. Start it once (the layout does, when a user is present).
 */

type Status = 'online' | 'idle'

// One subscription per tab; module scope keeps it alive across every component that reads it.
let channel: any = null
let started = false
let idleTimer: ReturnType<typeof setInterval> | null = null
let lastActive = Date.now()
let selfStatus: Status = 'online'

/** No pointer or key for this long ⇒ idle. */
const IDLE_MS = 5 * 60 * 1000
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'pointermove', 'wheel', 'touchstart'] as const

export function usePresence() {
  const echo: any = import.meta.client ? useNuxtApp().$echo : null
  const { user } = useAuth()

  // userId → status. Absent means offline. Shared reactive state (SSR-safe via useState).
  const statuses = useState<Record<number, Status>>('presence:statuses', () => ({}))

  function set(id: number, status: Status) {
    if (statuses.value[id] === status) return
    statuses.value = { ...statuses.value, [id]: status }
  }
  function drop(id: number) {
    if (!(id in statuses.value)) return
    const next = { ...statuses.value }
    delete next[id]
    statuses.value = next
  }

  function whisperStatus() {
    if (channel && user.value) channel.whisper('presence-status', { id: user.value.id, status: selfStatus })
  }

  function setSelf(status: Status) {
    if (selfStatus === status) return
    selfStatus = status
    if (user.value) set(user.value.id, status)
    whisperStatus()
  }

  function markActive() {
    lastActive = Date.now()
    setSelf('online')
  }
  function checkIdle() {
    if (import.meta.client && (document.hidden || Date.now() - lastActive > IDLE_MS)) setSelf('idle')
  }
  function onVisibility() {
    if (document.hidden) setSelf('idle')
    else markActive()
  }

  function start() {
    if (!echo || started) return
    started = true
    lastActive = Date.now()
    selfStatus = 'online'

    channel = echo.join('online')
      .here((members: { id: number }[]) => {
        // The roster gives us who's here; assume active until they whisper otherwise. Keep our
        // own known status rather than resetting it (we may already be idle).
        for (const m of members) set(m.id, m.id === user.value?.id ? selfStatus : 'online')
      })
      .joining((m: { id: number }) => {
        set(m.id, 'online')
        // A newcomer's roster shows us as plain online; if we're actually idle, tell them.
        if (selfStatus === 'idle') whisperStatus()
      })
      .leaving((m: { id: number }) => drop(m.id))
      .listenForWhisper('presence-status', (p: { id: number, status: Status }) => {
        if (p?.id != null) set(p.id, p.status === 'idle' ? 'idle' : 'online')
      })

    for (const e of ACTIVITY_EVENTS) window.addEventListener(e, markActive, { passive: true })
    document.addEventListener('visibilitychange', onVisibility)
    idleTimer = setInterval(checkIdle, 30_000)
  }

  function stop() {
    if (!started) return
    started = false
    for (const e of ACTIVITY_EVENTS) window.removeEventListener(e, markActive)
    document.removeEventListener('visibilitychange', onVisibility)
    if (idleTimer) { clearInterval(idleTimer); idleTimer = null }
    echo?.leave('online')
    channel = null
    statuses.value = {}
  }

  const statusOf = (id: number): Status | 'offline' => statuses.value[id] ?? 'offline'
  const isOnline = (id: number) => id in statuses.value

  return { statuses, start, stop, statusOf, isOnline }
}
