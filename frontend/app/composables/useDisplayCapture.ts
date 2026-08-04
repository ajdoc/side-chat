/**
 * "Give me a picture of the screen" — one call, three very different machines underneath.
 *
 * On the web and in Electron this is `getDisplayMedia`, which is the whole implementation. On a
 * phone it can't be: **no mobile WebView implements getDisplayMedia at all**, so a share button
 * there used to either be hidden (voice channels) or silently throw (the audio-share button).
 * The native shells capture the screen themselves — MediaProjection on Android, a ReplayKit
 * broadcast extension on iOS — and hand the frames back over a loopback socket, which this
 * turns into an ordinary `MediaStream`.
 *
 * That last part is the point of the design. Everything downstream of here — the mesh, the
 * pre-negotiated screen slots, the per-peer volume, the adaptive sampler — receives a
 * MediaStream with a video and/or audio track and cannot tell which of the three produced it.
 * Screen sharing on a phone therefore needed no change to any of it.
 *
 * @see useVoice, which is the only caller.
 */

/** What the native plugin returns once the user has granted the capture. */
interface NativeCaptureSession {
  /** Loopback WebSocket carrying the frames — `ws://127.0.0.1:<port>/<token>`. */
  endpoint: string
  /** The capture's real size, which the device decides, not us. */
  width: number
  height: number
  frameRate: number
  /** Whether the platform actually gave us the system audio we asked for. */
  audio: boolean
}

interface ScreenCapturePlugin {
  isSupported: () => Promise<{ supported: boolean, reason?: string }>
  start: (options: { height: number, frameRate: number, audio: boolean }) => Promise<NativeCaptureSession>
  stop: () => Promise<void>
  /** Fired when the capture ends outside the app — the system's "Stop sharing" notification. */
  addListener: (event: 'screenCaptureEnded', handler: () => void) => Promise<{ remove: () => void }>
}

/**
 * The native plugin, reached through the bridge Capacitor injects on `window`.
 *
 * Deliberately *not* an `@capacitor/core` import. The frontend has no Capacitor dependency and
 * shouldn't grow one: there is one generated bundle and it is served from a web host and from
 * Electron as well as from the phone. This is the same structural detection usePlatform uses,
 * for the same reason.
 */
function plugin(): ScreenCapturePlugin | null {
  if (typeof window === 'undefined') return null
  return (window as any).Capacitor?.Plugins?.ScreenCapture ?? null
}

/** Frame types on the wire. One byte, then the payload. */
const FRAME_VIDEO = 1
const FRAME_AUDIO = 2

/** What the native side sends: 48kHz signed 16-bit PCM, interleaved stereo. */
const NATIVE_SAMPLE_RATE = 48000
const NATIVE_CHANNELS = 2

/**
 * The live native capture — module scope, not per-caller.
 *
 * There is one screen and one capture of it, but `useVoice()` is instantiated per component:
 * a share can be started from the voice channel's own bar and stopped from the global call bar,
 * which are two different instances. Held inside the composable, the second one would find
 * nothing to tear down.
 */
let active: { teardown: () => void, listener?: { remove: () => void } } | null = null

export interface DisplayCaptureOptions {
  /** Target capture height. The native side treats it as a ceiling, as getDisplayMedia does. */
  height: number
  frameRate: number
  /** Ask for the source's own sound as well. Often simply unavailable; never an error. */
  audio: boolean
}

export function useDisplayCapture() {
  const isNativeShell = () => !!(globalThis as any).Capacitor?.isNativePlatform?.() && !!plugin()

  /**
   * Whether *this* device can share its screen at all.
   *
   * Asked rather than assumed, because it's a property of the OS version and not of the build:
   * Android needs 10+ for the audio half, and iOS needs the broadcast extension to be installed
   * alongside the app. A false answer is what keeps the button off the screen instead of putting
   * up one that fails when pressed.
   */
  const supported = useState('displayCapture:supported', () => false)
  const unsupportedReason = useState<string | null>('displayCapture:reason', () => null)

  async function probe() {
    if (!import.meta.client) return

    if (!isNativeShell()) {
      supported.value = typeof navigator?.mediaDevices?.getDisplayMedia === 'function'
      unsupportedReason.value = supported.value ? null : 'This browser can’t share a screen.'
      return
    }

    const native = plugin()
    if (!native) {
      supported.value = false
      unsupportedReason.value = 'This app build can\u2019t share a screen.'
      return
    }

    try {
      const result = await native.isSupported()
      supported.value = result.supported
      unsupportedReason.value = result.supported ? null : (result.reason ?? 'Screen sharing isn’t available on this device.')
    } catch {
      // The plugin isn't in this build (an older shell, or the web bundle in a browser tab).
      supported.value = false
      unsupportedReason.value = 'This app build can’t share a screen.'
    }
  }

  /**
   * Turn the native socket into a MediaStream.
   *
   * Video: each frame arrives as a JPEG, is decoded off the main thread by `createImageBitmap`,
   * drawn to an offscreen canvas, and leaves through `canvas.captureStream()`. Encoding to JPEG
   * on the device and decoding here is a real cost, but it is the only path from a native
   * capture into the WebView's WebRTC stack — there is no API for injecting a track.
   *
   * Audio: PCM is queued into a `MediaStreamAudioDestinationNode` via short AudioBuffers,
   * scheduled back-to-back so they play gaplessly. A quarter-second of slack absorbs the jitter
   * of a socket without being audible as delay.
   */
  function streamFromSocket(session: NativeCaptureSession, wantAudio: boolean) {
    const socket = new WebSocket(session.endpoint)
    socket.binaryType = 'arraybuffer'

    const canvas = document.createElement('canvas')
    canvas.width = session.width
    canvas.height = session.height
    const ctx = canvas.getContext('2d', { alpha: false })

    const stream: MediaStream = canvas.captureStream(session.frameRate)

    let audioContext: AudioContext | null = null
    let audioDestination: MediaStreamAudioDestinationNode | null = null
    let playhead = 0

    if (wantAudio && session.audio) {
      audioContext = new AudioContext({ sampleRate: NATIVE_SAMPLE_RATE })
      audioDestination = audioContext.createMediaStreamDestination()
      for (const track of audioDestination.stream.getAudioTracks()) stream.addTrack(track)
    }

    function playPcm(payload: ArrayBuffer) {
      if (!audioContext || !audioDestination) return

      const samples = new Int16Array(payload)
      const frames = Math.floor(samples.length / NATIVE_CHANNELS)
      if (!frames) return

      const buffer = audioContext.createBuffer(NATIVE_CHANNELS, frames, NATIVE_SAMPLE_RATE)
      for (let channel = 0; channel < NATIVE_CHANNELS; channel++) {
        const target = buffer.getChannelData(channel)
        for (let i = 0; i < frames; i++) target[i] = samples[i * NATIVE_CHANNELS + channel]! / 32768
      }

      const source = audioContext.createBufferSource()
      source.buffer = buffer
      source.connect(audioDestination)
      // Never schedule in the past — a late packet would otherwise be dropped silently and
      // the stream would drift quieter and quieter as the playhead fell behind.
      playhead = Math.max(playhead, audioContext.currentTime + 0.25)
      source.start(playhead)
      playhead += buffer.duration
    }

    socket.onmessage = async (event) => {
      const data = event.data as ArrayBuffer
      if (!(data instanceof ArrayBuffer) || data.byteLength < 2) return

      const kind = new Uint8Array(data, 0, 1)[0]
      const payload = data.slice(1)

      if (kind === FRAME_AUDIO) return playPcm(payload)
      if (kind !== FRAME_VIDEO || !ctx) return

      try {
        const bitmap = await createImageBitmap(new Blob([payload], { type: 'image/jpeg' }))
        // A rotated phone changes the capture's shape mid-share; resizing the canvas keeps
        // the picture from being squashed into the old aspect ratio.
        if (canvas.width !== bitmap.width || canvas.height !== bitmap.height) {
          canvas.width = bitmap.width
          canvas.height = bitmap.height
        }
        ctx.drawImage(bitmap, 0, 0)
        bitmap.close()
      } catch {
        // A truncated or malformed frame: skip it. The next one is 1/15th of a second away.
      }
    }

    /** Tear down everything this stream owns. Idempotent — both ends can trigger it. */
    const teardown = () => {
      try { socket.close() } catch { /* already gone */ }
      void audioContext?.close()
      for (const track of stream.getTracks()) track.stop()
    }

    // The socket dying means the capture is over, however it ended. Ending the tracks is what
    // tells useVoice — it already listens for `onended` to catch the browser's own stop button.
    socket.onclose = () => {
      for (const track of stream.getTracks()) track.stop()
      void audioContext?.close()
    }
    socket.onerror = () => socket.close()

    return { stream, teardown }
  }

  async function stopNative() {
    active?.teardown()
    active?.listener?.remove()
    active = null
    try { await plugin()?.stop() } catch { /* nothing was running */ }
  }

  /**
   * Capture the screen, whatever machine this is.
   *
   * Rejects — rather than returning null — for the same reason getDisplayMedia does: refusing at
   * the permission sheet is indistinguishable from any other failure at this level, and every
   * caller already treats a rejection as "changed their mind".
   */
  async function capture(options: DisplayCaptureOptions): Promise<MediaStream> {
    if (!isNativeShell()) {
      return navigator.mediaDevices.getDisplayMedia({
        video: {
          frameRate: { ideal: options.frameRate, max: options.frameRate },
          height: { ideal: options.height, max: options.height },
        },
        // Shared sound is the source's own audio — music, a video's soundtrack, a game — and
        // the browser's speech processing is actively wrong for it: noise suppression chews on
        // sustained notes and auto gain rides the mix. Off explicitly, because Chrome applies
        // some of it to display capture by default. There's no echo risk to trade away; this
        // never touches the microphone.
        audio: options.audio
          ? { echoCancellation: false, noiseSuppression: false, autoGainControl: false }
          : false,
      })
    }

    await stopNative() // only one capture at a time; a second supersedes the first

    const native = plugin()!
    const session = await native.start({
      height: options.height,
      frameRate: options.frameRate,
      audio: options.audio,
    })

    const { stream, teardown } = streamFromSocket(session, options.audio)

    // The system's own "Stop sharing" notification bypasses every button in the app.
    const listener = await native.addListener('screenCaptureEnded', () => {
      active?.teardown()
      active = null
    })

    active = { teardown, listener }
    return stream
  }

  /** Called when the app stops a share, so the OS recording stops with it. */
  async function release() {
    if (isNativeShell()) await stopNative()
  }

  return { supported, unsupportedReason, probe, capture, release }
}
