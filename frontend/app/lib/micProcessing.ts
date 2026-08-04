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
 * ## Levelling is a separate axis
 *
 * {@link buildMicChain} also takes a `normalize` flag, and it is deliberately *not* folded into
 * the three levels above. Suppression is about what sits under your voice; normalisation is
 * about how loud your voice arrives — a quiet talker on `standard` and a loud one on `high` are
 * both problems the other setting can't reach. The browser's own auto gain control only moves
 * the *capture* gain, and only on the levels where it's on at all; it can't hear that you are
 * still arriving at half the volume of everyone else in the call. So we measure what's actually
 * leaving and ride a gain toward a common target, which is the thing that stops a call being a
 * volume slider per person.
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

export const DEFAULT_NORMALIZE_VOLUME = true

/**
 * The RMS we aim a speaking voice at, and the window either side of it we don't bother
 * correcting. ~0.06 is a comfortable conversational level that leaves plenty of headroom for
 * the peaks a running average never sees; the deadband stops the gain breathing on the natural
 * rise and fall of a sentence.
 */
const TARGET_RMS = 0.06
const DEADBAND = 0.2
/** Below this we assume nobody is talking, and freeze — otherwise the gate's silence would be
 *  amplified back into the room noise we just spent a chain removing. */
const SPEECH_FLOOR_RMS = 0.006
/** How far we'll go either way. Boost is capped well short of what a truly dead mic would need
 *  because past this you're amplifying the preamp hiss, not the person. */
const MIN_GAIN = 0.5
const MAX_GAIN = 8
/** Down fast (a shout that clips is immediately unpleasant), up slowly (a lift you can hear
 *  happening is worse than one that takes a second). Time constants for setTargetAtTime. */
const ATTACK_S = 0.12
const RELEASE_S = 0.9
const MEASURE_MS = 100

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
 * A slow automatic gain: measure what's leaving, ride a gain toward {@link TARGET_RMS}.
 *
 * Deliberately a *measured* loop on a plain GainNode rather than a compressor doing the work.
 * A compressor with enough makeup gain to lift a quiet talker would also pump every breath and
 * key press up with them; this only moves while someone is actually speaking, and moves slowly
 * enough that the far end hears a person at a steady level rather than a gain being ridden.
 *
 * Returns the gain node to insert plus a stop for its timer. The measurement tap is a branch
 * off the *output* of the gain, so the loop sees the level it is producing (a closed loop that
 * settles) rather than the one it started from.
 */
function buildNormalizer(ctx: AudioContext): { node: GainNode, stop: () => void } {
  const gain = ctx.createGain()
  gain.gain.value = 1

  const analyser = ctx.createAnalyser()
  analyser.fftSize = 1024
  analyser.smoothingTimeConstant = 0
  gain.connect(analyser)

  const buffer = new Float32Array(analyser.fftSize)

  const timer = setInterval(() => {
    analyser.getFloatTimeDomainData(buffer)

    let sum = 0
    for (const sample of buffer) sum += sample * sample
    const rms = Math.sqrt(sum / buffer.length)

    // Silence tells us nothing about how loud this person is; hold the last decision.
    if (rms < SPEECH_FLOOR_RMS) return

    const current = gain.gain.value
    // What the *input* is doing, undoing our own contribution — otherwise the target is a
    // moving one and the loop chases itself.
    const inputRms = rms / Math.max(current, 0.0001)
    const wanted = Math.min(MAX_GAIN, Math.max(MIN_GAIN, TARGET_RMS / inputRms))

    if (Math.abs(wanted - current) / current < DEADBAND) return

    gain.gain.setTargetAtTime(
      wanted,
      ctx.currentTime,
      wanted < current ? ATTACK_S : RELEASE_S,
    )
  }, MEASURE_MS)

  return {
    node: gain,
    stop: () => {
      clearInterval(timer)
      try {
        analyser.disconnect()
      } catch {
        // already gone
      }
    },
  }
}

/**
 * Build the processing chain for a capture.
 *
 * Two independent things can put us in WebAudio — the `high` gate and normalisation — so the
 * chain is assembled from whichever are asked for, and skipped entirely when neither is (a
 * round trip through WebAudio to do nothing is latency for free).
 *
 * The order is the conventional one and it matters: cut the rumble *before* the gate, so the
 * gate's envelope isn't held open by a desk bump or a truck outside; normalise after the gate,
 * so the level being measured is speech rather than the room; compress last, so what the far
 * end hears is level rather than earlier decisions being made on a squashed signal.
 */
export async function buildMicChain(
  ctx: AudioContext,
  raw: MediaStream,
  level: NoiseSuppression,
  normalize = false,
): Promise<MicChain> {
  if (!raw.getAudioTracks().length) return passthrough(raw)

  // `off` is the raw capture and means it — someone playing an instrument doesn't want their
  // dynamics levelled any more than they want them gated.
  const wantsNormalizer = normalize && level !== 'off'
  // Without the worklet there's nothing in the gate half the browser isn't already doing.
  const wantsGate = level === 'high' && await loadWorklet(ctx)

  if (!wantsGate && !wantsNormalizer) return passthrough(raw)

  const built: AudioNode[] = []
  let stopNormalizer: (() => void) | null = null

  try {
    const source = ctx.createMediaStreamSource(raw)
    built.push(source)
    let tail: AudioNode = source

    if (wantsGate) {
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

      built.push(highpass, gate)
      tail = tail.connect(highpass).connect(gate)
    }

    if (wantsNormalizer) {
      const normalizer = buildNormalizer(ctx)
      stopNormalizer = normalizer.stop
      built.push(normalizer.node)
      tail = tail.connect(normalizer.node)
    }

    // Gentle, and deliberately so — this is levelling, not a broadcast chain. With the
    // normalizer in front it doubles as its safety net: a gain that has been riding a quiet
    // talker up meets a sudden laugh at the ratio rather than at the clip.
    const compressor = ctx.createDynamicsCompressor()
    compressor.threshold.value = -28
    compressor.knee.value = 24
    compressor.ratio.value = 3
    compressor.attack.value = 0.005
    compressor.release.value = 0.15

    const destination = ctx.createMediaStreamDestination()
    built.push(compressor, destination)

    tail.connect(compressor).connect(destination)

    return {
      stream: destination.stream,
      output: compressor,
      destroy: () => {
        stopNormalizer?.()
        // Disconnect in order, tolerating a context that has already been closed — teardown
        // races with hanging up, and an exception here would strand the rest of the cleanup.
        for (const node of built) {
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
    stopNormalizer?.()
    return passthrough(raw)
  }
}

/** Forget the cached worklet load. Contexts are per-call, and a module belongs to a context. */
export function resetMicProcessing() {
  workletLoaded = null
}
