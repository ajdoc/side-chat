import type { ControlInput, ControlSignal } from './useVoice'

/**
 * Remote control of a shared screen — "give me the mouse for a minute".
 *
 * The shape is deliberately AnyDesk's and not a screen-share toy's: control is *asked for* and
 * *granted*, never taken; exactly one person holds it at a time; and either side can end it
 * instantly. Consent is the entire feature, so it lives here in the app — near the person doing
 * the consenting — rather than in the desktop shell, which only ever sees input events for a
 * session that was already approved.
 *
 * Two transports, for two very different traffic shapes (see useVoice):
 *   - the handshake goes over Reverb whispers — rare, must work before any control exists
 *   - the input events go over a per-peer WebRTC data channel — ~60/second while dragging
 *
 * ## The asymmetry that shapes everything
 *
 * A browser tab cannot move the mouse on the machine it runs on. That is the sandbox working as
 * intended, and no amount of app code gets around it — actually injecting input needs a native
 * backend, which only exists in the Electron shell (see desktop/remote-control.js). So:
 *
 *   - anyone, on any platform, can *ask for* and *hold* control (they're only sending events)
 *   - only a sharer on the desktop app can *grant* it
 *
 * We surface that up front rather than at the moment of failure: `canGrantControl` is what the
 * sharer's UI greys out, and `grantBlockedReason` is what it says instead. The alternative —
 * letting someone approve a request and then watching nothing happen — is the worst version of
 * this feature, so the capability probe runs before anyone is asked, not after.
 */

/** How long a request stands before it expires on its own, in ms. */
const REQUEST_TTL = 30_000

/**
 * Minimum gap between pointer moves pushed to the sharer, in ms (~60Hz).
 *
 * Pointer events fire faster than that on a high-polling-rate mouse, and every one past the
 * display's refresh is a packet the sharer's compositor throws away. Coalescing to the frame is
 * free accuracy — we always send the *latest* position, never a stale one.
 */
const MOVE_INTERVAL = 16

interface Capabilities {
  /** Can this machine inject input at all? False in any browser, false without the backend. */
  available: boolean
  /** Is what's being shared a whole display? A window share has no bounds we can aim at. */
  sharingIsScreen: boolean
}

export function useRemoteControl() {
  const voice = useVoice()
  const { user } = useAuth()
  const { isDesktop } = usePlatform()

  // --- state -----------------------------------------------------------------------------

  /** Sharer side: who has asked for control and hasn't been answered yet. */
  const requests = useState<number[]>('rc:requests', () => [])

  /** Sharer side: who is driving my machine right now. At most one, always. */
  const controller = useState<number | null>('rc:controller', () => null)

  /** Controller side: whose screen I'm driving, and who I've asked but not heard back from. */
  const controlling = useState<number | null>('rc:controlling', () => null)
  const awaiting = useState<number | null>('rc:awaiting', () => null)

  /** Controller side: set when a request comes back refused, so the UI can say so once. */
  const refusedBy = useState<number | null>('rc:refused', () => null)

  const capabilities = useState<Capabilities>('rc:capabilities', () => ({
    available: false,
    sharingIsScreen: false,
  }))

  // --- capability ------------------------------------------------------------------------

  /**
   * Ask the shell what it can do. Cheap, and re-run whenever a share starts, because
   * `sharingIsScreen` is a property of *this* share — the same machine can grant control for a
   * display and genuinely cannot for the window next to it.
   */
  async function probe() {
    const bridge = (window as any).sideChatDesktop?.remoteControl
    if (!bridge) {
      capabilities.value = { available: false, sharingIsScreen: false }
      return
    }
    try {
      const caps = await bridge.capabilities()
      capabilities.value = {
        available: !!caps?.available,
        sharingIsScreen: !!caps?.sharingIsScreen,
      }
    } catch {
      capabilities.value = { available: false, sharingIsScreen: false }
    }
  }

  /** Can I hand over my screen right now? */
  const canGrantControl = computed(
    () => isDesktop.value && capabilities.value.available && capabilities.value.sharingIsScreen,
  )

  /** …and if not, the one sentence explaining why, for the UI to show in its place. */
  const grantBlockedReason = computed(() => {
    if (!isDesktop.value) return 'Letting someone control your screen needs the desktop app.'
    if (!capabilities.value.available) return 'This build can’t control the mouse and keyboard.'
    if (!capabilities.value.sharingIsScreen) return 'Share a whole screen (not a single window) to allow control.'
    return ''
  })

  // --- sharer side -----------------------------------------------------------------------

  const expiries = new Map<number, ReturnType<typeof setTimeout>>()

  function forgetRequest(from: number) {
    clearTimeout(expiries.get(from))
    expiries.delete(from)
    requests.value = requests.value.filter(id => id !== from)
  }

  function onRequest(from: number) {
    // An unanswered ask that's already standing shouldn't stack a second prompt; refresh its
    // expiry instead, so someone re-asking after a while doesn't have it lapse out from under
    // them mid-decision.
    if (!requests.value.includes(from)) requests.value = [...requests.value, from]
    clearTimeout(expiries.get(from))
    expiries.set(from, setTimeout(() => forgetRequest(from), REQUEST_TTL))
  }

  /** Hand the mouse over. Any other pending ask is refused in the same breath. */
  function approve(from: number) {
    if (!canGrantControl.value) return

    // Only one driver: whoever held it before is told, plainly, that they've been replaced.
    if (controller.value !== null && controller.value !== from) {
      voice.sendControlSignal(controller.value, 'end')
    }
    for (const id of requests.value) {
      if (id !== from) voice.sendControlSignal(id, 'deny')
      clearTimeout(expiries.get(id))
    }
    expiries.clear()
    requests.value = []

    controller.value = from
    voice.sendControlSignal(from, 'approve')
  }

  function deny(from: number) {
    forgetRequest(from)
    voice.sendControlSignal(from, 'deny')
  }

  /** Take the mouse back. Safe to call when nobody has it. */
  function revoke() {
    const held = controller.value
    controller.value = null
    if (held !== null) voice.sendControlSignal(held, 'end')
    // Lift anything the controller was mid-press on — see desktop/remote-control.js.
    ;(window as any).sideChatDesktop?.remoteControl?.stop()
  }

  /**
   * An input event arriving from the person we granted control to.
   *
   * The `from === controller` check is the security boundary, and it is checked *here* rather
   * than trusted from the channel: a data channel is per-peer, so a frame can only come from
   * that peer, but "which peer may drive" is our decision and stays ours. Anything from anyone
   * else is dropped without ceremony.
   */
  function onInput(from: number, input: ControlInput) {
    if (from !== controller.value) return
    ;(window as any).sideChatDesktop?.remoteControl?.send(input)
  }

  // --- controller side -------------------------------------------------------------------

  /** Ask someone for control of the screen they're sharing. */
  function requestControl(peerId: number) {
    if (controlling.value !== null || !voice.controlChannelReady(peerId)) return
    refusedBy.value = null
    awaiting.value = peerId
    voice.sendControlSignal(peerId, 'request')

    // If they never answer, stop showing "waiting" forever. Their side expires the ask on the
    // same clock, so both ends give up together rather than one hanging on a dead prompt.
    setTimeout(() => {
      if (awaiting.value === peerId) awaiting.value = null
    }, REQUEST_TTL)
  }

  /** Give control back. Either side can do this; this is the controller's half. */
  function releaseControl() {
    const target = controlling.value
    controlling.value = null
    awaiting.value = null
    if (target !== null) voice.sendControlSignal(target, 'end')
  }

  let lastMove = 0

  /**
   * Send one input event to the screen we're driving.
   *
   * Moves are throttled to the frame (see MOVE_INTERVAL); everything else — clicks, keys,
   * wheel — goes immediately and unconditionally, because dropping a `mouseup` is how you leave
   * somebody's machine with a button stuck down.
   */
  function sendInput(input: ControlInput) {
    const target = controlling.value
    if (target === null) return

    if (input.t === 'move') {
      const now = performance.now()
      if (now - lastMove < MOVE_INTERVAL) return
      lastMove = now
    }

    voice.sendControlInput(target, input)
  }

  // --- the handshake ---------------------------------------------------------------------

  function onSignal(signal: ControlSignal) {
    switch (signal.kind) {
      case 'request':
        // Only meaningful if we're actually sharing something. Refuse outright when we can't
        // grant at all, so the asker gets a straight "no" instead of silence.
        if (!voice.isSharing.value) return
        if (!canGrantControl.value) {
          voice.sendControlSignal(signal.from, 'deny')
          return
        }
        onRequest(signal.from)
        break

      case 'approve':
        // Ignore an approval we didn't ask for — and one that arrives after we gave up waiting,
        // which would otherwise hand us a live session the sharer thinks is mutual.
        if (awaiting.value !== signal.from) {
          voice.sendControlSignal(signal.from, 'end')
          return
        }
        awaiting.value = null
        controlling.value = signal.from
        break

      case 'deny':
        if (awaiting.value === signal.from) awaiting.value = null
        refusedBy.value = signal.from
        break

      case 'end':
        // 'end' is symmetric: it arrives at the controller when the sharer revokes, and at the
        // sharer when the controller hands it back. Clearing both sides is correct either way,
        // and only one of them can be set for a given peer.
        if (controlling.value === signal.from) controlling.value = null
        if (awaiting.value === signal.from) awaiting.value = null
        if (controller.value === signal.from) {
          controller.value = null
          ;(window as any).sideChatDesktop?.remoteControl?.stop()
        }
        break
    }
  }

  // --- lifecycle -------------------------------------------------------------------------

  /**
   * Wire the state machine to the call. Called once from the app shell; guarded because the
   * composable is a singleton that any number of components may pull in.
   */
  const wired = useState('rc:wired', () => false)

  function install() {
    if (wired.value) return
    wired.value = true

    voice.onControl({ input: onInput, signal: onSignal })

    // The watchers below belong to whichever component called install(), and die with it — so
    // the flag has to die with it too. Without this, a host that unmounts and comes back (the
    // app layout across a logout/login) would take the early return above and never re-wire,
    // leaving control requests arriving at nobody.
    onScopeDispose(() => {
      wired.value = false
      voice.onControl({})
    })

    // A share that stops takes every session and every pending ask with it — there is nothing
    // left to point at. Also re-probes on start, because whether control is possible at all
    // depends on *what* was picked (a display, or one window).
    watch(
      () => voice.isSharing.value,
      async sharing => {
        if (sharing) {
          await probe()
        } else {
          revoke()
          for (const id of requests.value) voice.sendControlSignal(id, 'deny')
          for (const t of expiries.values()) clearTimeout(t)
          expiries.clear()
          requests.value = []
          capabilities.value = { ...capabilities.value, sharingIsScreen: false }
        }
      },
    )

    // Someone driving my screen who walks out of the call keeps the grant open otherwise —
    // and it would silently reattach if they came back. Same for a screen I'm driving.
    watch(
      () => voice.peers.value.map(p => p.id),
      ids => {
        if (controller.value !== null && !ids.includes(controller.value)) revoke()
        if (controlling.value !== null && !ids.includes(controlling.value)) releaseControl()
      },
    )

    // Leaving the call at all ends everything. `inCall` covers the paths a peer watch can't:
    // hanging up, being disconnected by a moderator, the tab losing the room.
    watch(
      () => voice.inCall.value,
      inCall => {
        if (inCall) return
        revoke()
        controlling.value = null
        awaiting.value = null
      },
    )
  }

  return {
    // Sharer side
    requests,
    controller,
    canGrantControl,
    grantBlockedReason,
    approve,
    deny,
    revoke,
    // Controller side
    controlling,
    awaiting,
    refusedBy,
    requestControl,
    releaseControl,
    sendInput,
    // Wiring
    install,
    probe,
    /** True for the peer whose name should appear next to "is controlling your screen". */
    isControlledBy: (id: number) => controller.value === id,
  }
}
