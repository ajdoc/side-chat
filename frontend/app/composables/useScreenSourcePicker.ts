/**
 * The desktop shell's screen-share request, as app state.
 *
 * Electron makes the *page* responsible for answering `getDisplayMedia` — Chromium's picker
 * isn't available to it — so the shell forwards each request here over the preload bridge and
 * waits for an answer. {@link ScreenSourcePicker} draws it.
 *
 * Shared state (`useState`) rather than a per-caller instance: the picker is mounted once in
 * the app layout, while the requests come from wherever a share was started — a voice channel,
 * a call in a DM, a Side Space. Everywhere but Electron this is inert; the browser and the
 * phone both have pickers of their own.
 */
export interface ScreenSource {
  id: string
  name: string
  kind: 'screen' | 'window'
  /** A data: URL preview, or null when the shell couldn't grab one. */
  thumbnail: string | null
  /** The window's app icon, where the platform provides one. */
  icon: string | null
}

export interface ScreenShareRequest {
  sources: ScreenSource[]
  /** Whether the caller asked for sound as well as picture. */
  audioRequested: boolean
  /** Whether this machine can actually capture it — Windows only, today. */
  audioSupported: boolean
}

interface DesktopBridge {
  platform: string
  screenShare?: {
    onRequest: (handler: (request: ScreenShareRequest) => void) => () => void
    pick: (sourceId: string, audio: boolean) => void
    cancel: () => void
  }
}

function bridge(): DesktopBridge['screenShare'] | undefined {
  if (typeof window === 'undefined') return undefined
  return ((window as any).sideChatDesktop as DesktopBridge | undefined)?.screenShare
}

export function useScreenSourcePicker() {
  const request = useState<ScreenShareRequest | null>('screenPicker:request', () => null)
  const open = computed(() => request.value !== null)

  /**
   * Start listening for requests. Called once, by the picker component itself, so nothing else
   * has to remember to wire it up — and the returned teardown keeps hot-reload from stacking
   * duplicate listeners.
   */
  function listen() {
    const desktop = bridge()
    if (!desktop) return () => {}

    return desktop.onRequest((payload) => {
      request.value = payload
    })
  }

  function choose(sourceId: string, audio: boolean) {
    bridge()?.pick(sourceId, audio)
    request.value = null
  }

  function cancel() {
    // Told to the shell even on a dismissal: without it the pending getDisplayMedia never
    // settles, and the share button stays stuck mid-press for the rest of the session.
    bridge()?.cancel()
    request.value = null
  }

  return { open, request, listen, choose, cancel }
}
