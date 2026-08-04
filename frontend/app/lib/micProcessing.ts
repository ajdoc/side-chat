/**
 * What happens to your microphone between the capture and the peer connection.
 *
 * ## Three levels, not a switch
 *
 * Noise suppression isn't one thing, and the right amount of it depends on the room and the
 * microphone — which is why this is a choice and not a constant:
 *
 * - `off` — the raw capture. For someone on a good mic in a quiet room, and above all for
 *   anyone playing an instrument or singing: every kind of suppression below is tuned for
 *   speech and will treat a held note as noise to be chewed on.
 * - `standard` — the browser's own echo cancellation, noise suppression and gain control.
 *   This is what a call has always used and what most people should stay on: it's free, it
 *   runs before the audio ever reaches us, and it handles steady noise (fans, hum, traffic)
 *   well.
 * - `high` — the browser's, and then ours on top: a high-pass, a {@link noise gate}, and a
 *   little compression. This is for the case the browser is weakest at — a room that is
 *   *quiet but not silent*, where a keyboard, a fan or a housemate sits under your voice and
 *   is transmitted continuously because you are transmitting continuously. The gate means
 *   you send near-silence when you aren't talking, which is most of a call.
 *
 * ## Why the gate is worth the machinery
 *
 * In a mesh, everything you send goes up your upload pipe once per other person in the call,
 * and Opus DTX only stops sending when the input is *actually* quiet. Constant low-level room
 * noise defeats it: the encoder faithfully spends bitrate describing your fan to five people.
 * A gate makes the silences silent again, which is both the polite thing and the cheap one.
 *
 * ## Degrading rather than failing
 *
 * Every part of this is best-effort. If the AudioWorklet module can't be loaded — an old
 * browser, an offline first paint, a strict CSP — the chain quietly comes back without the
 * gate rather than failing the join. Losing suppression is a worse call; failing to open the
 * microphone is no call at all.
 */

export const NOISE_SUPPRESSION_LEVELS = ['off', 'standard', 'high'] as const
export type NoiseSuppression = (typeof NOISE_SUPPRESSION_LEVELS)[number]

export const DEFAULT_NOISE_SUPPRESSION: NoiseSuppression = 'standard'

export const NOISE_SUPPRESSION_OPTIONS: { value: NoiseSuppression, label: string, hint: string }[] = [
  { value: 'standard', label: 'Standard', hint: 'Your browser\'s echo and noise cleanup' },
  { value: 'high', label: 'Aggressive', hint: 'Also gates out fans, keyboards and room noise between words' },
  { value: 'off', label: 'Off', hint: 'Raw microphone — for music, or a good mic in a quiet room' },
]

/**
 * How the microphone is captured, in one place so a fresh join and a mid-call swap can't
 * drift apart. `channelCount: 1` is the mono capture the bitrate budget is built around.
 *
 * `off` turns the browser's processing off at the *constraint*, which is the only place it
 * can be turned off — nothing downstream can put back what echo cancellation removed.
 */
export function micConstraints(level: NoiseSuppression): MediaTrackConstraints {
  const processed = level !== 'off'

  return {
    echoCancellation: processed,
    noiseSuppression: processed,
    autoGainControl: processed,
    channelCount: 1,
  }
}

/** A built chain: what to send, what to meter, and how to take it down. */
export interface MicChain {
  /** The stream to hand to the peer connections. The raw capture when nothing was applied. */
  stream: MediaStream
  /** The last node in the chain — what the speaking meter should listen to, so the ring
   *  agrees with what peers actually hear. Null when the audio never entered WebAudio. */
  output: AudioNode | null
  /** Stop the chain. Does *not* stop the capture it was built from; the caller owns that. */
  destroy: () => void
}

/** The unprocessed capture, wearing the same interface so callers have one code path. */
function passthrough(raw: MediaStream): MicChain {
  return { stream: raw, output: null, destroy: () => {} }
}

let workletLoaded: Promise<boolean> | null = null

/**
 * Load the gate's worklet module once per AudioContext-lifetime. Cached as the *promise*, so
 * two calls that race (a join and a device swap) share one fetch, and cached as a boolean
 * result so a browser that can't do worklets isn't asked again every call.
 */
function loadWorklet(ctx: AudioContext): Promise<boolean> {
  workletLoaded ??= ctx.audioWorklet
    ? ctx.audioWorklet.addModule('/worklets/noise-gate.js').then(() => true).catch(() => false)
    : Promise.resolve(false)

  return workletLoaded
}

/**
 * Build the processing chain for a capture.
 *
 * The order is the conventional one and it matters: cut the rumble *before* the gate, so the
 * gate's envelope isn't held open by a desk bump or a truck outside; compress last, so what
 * the far end hears is level rather than the gate's decisions being made on a squashed signal.
 */
export async function buildMicChain(
  ctx: AudioContext,
  raw: MediaStream,
  level: NoiseSuppression,
): Promise<MicChain> {
  if (level !== 'high' || !raw.getAudioTracks().length) return passthrough(raw)

  const hasWorklet = await loadWorklet(ctx)
  // Without the gate there is nothing here the browser's own suppression isn't already
  // doing, so don't pay for a WebAudio round trip to add a filter and a compressor.
  if (!hasWorklet) return passthrough(raw)

  try {
    const source = ctx.createMediaStreamSource(raw)

    // Below ~90Hz there is no speech, only rumble: desk knocks, footsteps, breath on the
    // capsule, mains hum. Removing it is the single cheapest thing in this chain.
    const highpass = ctx.createBiquadFilter()
    highpass.type = 'highpass'
    highpass.frequency.value = 90
    highpass.Q.value = 0.7

    const gate = new AudioWorkletNode(ctx, 'noise-gate', {
      numberOfInputs: 1,
      numberOfOutputs: 1,
      outputChannelCount: [1],
    })

    // Gentle, and deliberately so — this is levelling, not a broadcast chain. It catches the
    // gap between someone who leans into their mic and someone sitting back from it, which is
    // otherwise something the listener has to fix with a volume slider per person.
    const compressor = ctx.createDynamicsCompressor()
    compressor.threshold.value = -28
    compressor.knee.value = 24
    compressor.ratio.value = 3
    compressor.attack.value = 0.005
    compressor.release.value = 0.15

    const destination = ctx.createMediaStreamDestination()

    source.connect(highpass).connect(gate).connect(compressor).connect(destination)

    return {
      stream: destination.stream,
      output: compressor,
      destroy: () => {
        // Disconnect in order, tolerating a context that has already been closed — teardown
        // races with hanging up, and an exception here would strand the rest of the cleanup.
        for (const node of [source, highpass, gate, compressor, destination]) {
          try {
            node.disconnect()
          } catch {
            // already gone
          }
        }
      },
    }
  } catch {
    // A chain we couldn't build is not a call we should fail — send the raw capture.
    return passthrough(raw)
  }
}

/** Forget the cached worklet load. Contexts are per-call, and a module belongs to a context. */
export function resetMicProcessing() {
  workletLoaded = null
}
