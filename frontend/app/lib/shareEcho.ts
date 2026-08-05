/**
 * Keep the call out of the sound you're sharing.
 *
 * On the desktop app, "share the sound too" is a capture of the whole machine's output — the
 * only kind of audio capture the platforms offer. While you're on a call that output contains
 * the other people in the call, so sharing it sends their own voices back to them a moment
 * late. That's the echo, and it's why this exists.
 *
 * The subtraction itself is the worklet's job (`public/worklets/echo-canceller.js`, which is
 * where the DSP and the reasoning behind it live). This file is the plumbing on either side of
 * it: the capture in one input, the mix of everything the call is playing in the other, and a
 * cleaned {@link MediaStreamTrack} out — the same shape of thing the caller had before, so
 * nothing downstream of it (the transceiver, the per-peer volume, the far end) knows.
 *
 * The whole thing is best-effort by design. A browser without AudioWorklet, a worklet that
 * fails to load, a context that won't start: every one of those returns the original track
 * rather than an error, because a share with an echo is worse than a share, and a share that
 * didn't start is worse than both.
 */

/** A wrapped capture: the track to send, and how to put the graph away afterwards. */
export interface CleanedCapture {
  track: MediaStreamTrack
  /** Tear the graph down. Never stops the original capture — the caller owns that. */
  destroy: () => void
}

let moduleLoaded: Promise<boolean> | null = null

/** Load the processor once per page. Cached as the promise so two shares can't race it. */
function loadWorklet(ctx: AudioContext): Promise<boolean> {
  moduleLoaded ??= ctx.audioWorklet
    ? ctx.audioWorklet.addModule('/worklets/echo-canceller.js').then(() => true).catch(() => false)
    : Promise.resolve(false)

  return moduleLoaded
}

/**
 * Wrap `track` so that whatever `reference` is playing is subtracted from it.
 *
 * `reference` is a node carrying the call's own playout — see the reference mix in useVoice.
 * It is *not* connected to the destination here: the call is already audible through its own
 * elements, and connecting it would play everyone twice.
 *
 * Resolves to null when this can't be done, which the caller reads as "send the raw capture".
 */
export async function cancelCallEcho(
  ctx: AudioContext,
  track: MediaStreamTrack,
  reference: AudioNode,
): Promise<CleanedCapture | null> {
  if (!(await loadWorklet(ctx))) return null

  try {
    const source = ctx.createMediaStreamSource(new MediaStream([track]))
    const node = new AudioWorkletNode(ctx, 'echo-canceller', {
      numberOfInputs: 2,
      numberOfOutputs: 1,
      // Stereo throughout: a machine's output is stereo, and folding it to mono to cancel it
      // would throw away half of what people are sharing.
      outputChannelCount: [2],
      channelCount: 2,
      channelCountMode: 'explicit',
      channelInterpretation: 'speakers',
    })

    source.connect(node, 0, 0)
    reference.connect(node, 0, 1)

    const destination = ctx.createMediaStreamDestination()
    node.connect(destination)

    const cleaned = destination.stream.getAudioTracks()[0]
    if (!cleaned) throw new Error('no cleaned track')

    // The capture's own contentHint travels with it — the far end still needs to know this is
    // music rather than speech, and the track it gets is not the track that was hinted.
    cleaned.contentHint = track.contentHint

    return {
      track: cleaned,
      destroy: () => {
        try {
          reference.disconnect(node)
        } catch {
          // Already disconnected — a call that ended before the share did.
        }
        source.disconnect()
        node.port.postMessage({ enabled: false })
        node.disconnect()
        cleaned.stop()
      },
    }
  } catch {
    return null
  }
}
