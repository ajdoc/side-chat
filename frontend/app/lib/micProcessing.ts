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
 * - `high` — the browser's, and then ours on top: a high-pass, a spectral
 *   {@link noise-suppressor}, a {@link noise-gate}, and a little compression. This is for the
 *   case the browser is weakest at — a room that is *not quiet*, where a fan, rain on the
 *   window or the road outside sits under your voice.
 * - `voice` — the same chain with a neural denoiser ({@link rnnoise}) in the suppressor's slot.
 *   For the case *everything above* is weakest at: noise that isn't steady, and so can't be
 *   measured. See below.
 *
 * ## Why `voice` is a fourth level and not a better `high`
 *
 * Every level above `off` works by measuring the room and subtracting it, which quietly assumes
 * the room holds still long enough to be measured. Against a fan that assumption is free.
 * Against a keyboard, a clap, a door or a dog it is simply false — the sound is over before any
 * estimate has moved, so it arrives at the far end untouched, and worse, every envelope
 * downstream lurches around it. No amount of tuning reaches that, because the information
 * needed isn't in the signal's history.
 *
 * `voice` gets it from a model instead: a small recurrent network trained on what speech sounds
 * like, which can therefore call a clap "not speech" the first time it hears one. It is the only
 * level that removes non-stationary noise, and it is the answer to "my friend on Discord can't
 * hear clapping" — that is the same class of tool.
 *
 * It is not the default and should not become one. It is trained on speech, at 48kHz, and it
 * will happily decide that a guitar, a sung note or a second person across the room is noise to
 * be removed. `high` colours a voice; `voice` can delete a sound outright. That is the right
 * trade for a loud room and the wrong one for almost anything else, so it stays a choice made by
 * someone who knows their own problem.
 *
 * It also replaces the spectral suppressor rather than stacking with it. Two estimators each
 * subtracting from the other's output is precisely how you arrive at the underwater artefacts
 * both of them are tuned to avoid.
 *
 * ## Why `high` needs both a suppressor and a gate
 *
 * They fix different halves of the same complaint, and neither is enough alone.
 *
 * A **gate** is one decision about the whole signal: open, or shut. It makes the pauses between
 * words genuinely silent — which matters more than it sounds like, because in a mesh everything
 * you send goes up your upload pipe once per other person in the call, and Opus DTX only stops
 * sending when the input is *actually* quiet. Constant low-level room noise defeats it: the
 * encoder faithfully spends bitrate describing your fan to five people. But while you are
 * talking the gate is open, and your room comes through with you.
 *
 * The **suppressor** is what reaches that. It decides band by band, hundreds of times a second,
 * how much of each is your voice and how much is the room, and turns down the ones that are
 * mostly room — mid-word as readily as mid-pause. On steady noise (a fan, rain, traffic) it's
 * worth about 14dB with no measurable cost to the speech; on a sudden non-stationary noise (a
 * motorcycle going past, a door) it's worth rather less, because there's nothing to have
 * learned yet and it takes a moment to catch up.
 *
 * ## How much of it, though
 *
 * `high` is a *range*, not a setting — {@link SUPPRESSION_STRENGTH_RANGE}. Every number in this
 * file's cleanup is a trade against your own voice, and where the good trade sits depends on
 * how loud your room actually is: subtract harder and the fan goes, but so does some of the
 * body of your voice, and the gate starts clipping the quiet end of your words. A room with a
 * fan and a road outside wants a different point on that curve than a room with a laptop in it.
 * So the level chooses the chain and the strength chooses how hard it works — see
 * {@link suppressionSettings} for what actually moves, and note that it moves *live*: these are
 * AudioParams, so the slider is audible while you're talking rather than on the next call.
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

export const NOISE_SUPPRESSION_LEVELS = ['off', 'standard', 'high', 'voice'] as const
export type NoiseSuppression = (typeof NOISE_SUPPRESSION_LEVELS)[number]

export const DEFAULT_NOISE_SUPPRESSION: NoiseSuppression = 'standard'

/**
 * The rate the neural model is defined at, exported because the *context* has to be built at it.
 *
 * RNNoise's features are computed against fixed frequency bands over a 480-sample frame; at any
 * other rate those bands land on the wrong frequencies and the network is being asked about a
 * signal it has never seen. It doesn't fail — it just denoises badly and confidently. Since an
 * AudioContext's rate is fixed at construction and defaults to whatever the output device wants
 * (44.1kHz on plenty of machines), the call's context has to ask for this explicitly.
 */
export const RNNOISE_SAMPLE_RATE = 48000

export const NOISE_SUPPRESSION_OPTIONS: { value: NoiseSuppression, label: string, hint: string }[] = [
  { value: 'standard', label: 'Standard', hint: 'Your browser\'s echo and noise cleanup' },
  { value: 'high', label: 'Aggressive', hint: 'Removes fans, rain and traffic from under your voice, and silences the gaps' },
  { value: 'voice', label: 'Voice isolation', hint: 'Sends your voice and nothing else — keyboards, claps and barking go. Not for music' },
  { value: 'off', label: 'Off', hint: 'Raw microphone — for music, or a good mic in a quiet room' },
]

/**
 * How the microphone is captured, in one place so a fresh join and a mid-call swap can't
 * drift apart. `channelCount: 1` is the mono capture the bitrate budget is built around.
 *
 * `off` turns the browser's processing off at the *constraint*, which is the only place it
 * can be turned off — nothing downstream can put back what echo cancellation removed.
 *
 * `voice` is the one level that splits them apart. Echo cancellation stays on and has to: it
 * needs the far end's signal as a reference, which only the browser has, and no denoiser can
 * substitute for it. Auto gain stays on because the model was trained on speech at a sensible
 * level. But the browser's *noise suppression* comes off, because leaving it on means two
 * denoisers in series, the first one handing the second a signal that has already had holes cut
 * in it — and a network trained on real speech does measurably worse on speech that has been
 * through a spectral subtractor first.
 */
export function micConstraints(level: NoiseSuppression): MediaTrackConstraints {
  const processed = level !== 'off'

  return {
    echoCancellation: processed,
    noiseSuppression: processed && level !== 'voice',
    autoGainControl: processed,
    channelCount: 1,
  }
}

/**
 * How hard `high` works, 0…1, and where it starts.
 *
 * The default is deliberately nearer the gentle end. The strongest settings are audible on your
 * own voice — a spectral subtraction that removes a fan removes some of what shares a band with
 * it, and a gate tight enough to shut on a keyboard is tight enough to shave a soft word's tail
 * — and someone who turns cleanup on and immediately sounds processed concludes the feature is
 * broken rather than that it's turned up. Better to start where the room noise is noticeably
 * reduced and the voice is untouched, and let anyone with a genuinely loud room push it.
 */
export const SUPPRESSION_STRENGTH_RANGE = { min: 0, max: 1, step: 0.05 } as const
export const DEFAULT_SUPPRESSION_STRENGTH = 0.35

/** Clamp a strength that came from localStorage or a URL to something the curve can take. */
export function clampStrength(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_SUPPRESSION_STRENGTH
  return Math.min(SUPPRESSION_STRENGTH_RANGE.max, Math.max(SUPPRESSION_STRENGTH_RANGE.min, value))
}

const lerp = (from: number, to: number, t: number) => from + (to - from) * t

/**
 * The whole curve, in one place: what each worklet parameter is at a given strength.
 *
 * Both ends are meant to be usable, which is the point of the endpoints chosen here.
 *
 * At **0** the chain is present but polite: the suppressor subtracts a little and is allowed to
 * leave a third of the noise behind (`floor`), and the gate ducks rather than shuts, with a long
 * hold so it never lands inside a sentence. This is roughly "take the edge off", and it is what
 * someone who finds the old fixed behaviour too much is reaching for.
 *
 * At **1** it is the aggressive chain: subtract hard, leave almost nothing, and gate to near
 * silence quickly. Right for a loud room, and it will colour a voice — that's the trade being
 * made, made deliberately.
 *
 * `hold` and `release` *shorten* as strength rises, which is the counter-intuitive one. A long
 * hold is the gentle choice: it keeps the gate open across the gaps inside speech, so the cost
 * of being wrong is some room noise rather than a missing syllable.
 */
export function suppressionSettings(strength: number) {
  const t = clampStrength(strength)

  return {
    suppressor: {
      // Oversubtraction. Below ~0.2 there is little point, above ~0.9 the artefacts arrive.
      amount: lerp(0.15, 0.9, t),
      // How much of the noise a suppressed band may keep. High floor = gentler, less "underwater".
      floor: lerp(0.35, 0.05, t),
      // How hard keystrokes, mouse clicks and claps are ducked. Unlike everything else here the
      // gentle end is not near-zero: an impulse getting through is what drives every envelope
      // downstream into the gust that reads as wind, and that is a complaint at any strength.
      transient: lerp(0.5, 1, t),
    },
    gate: {
      // What counts as speech. Low threshold = opens for almost anything, including the room.
      threshold: lerp(0.006, 0.03, t),
      // How far down it goes when shut. At the gentle end this is a duck, not a cut.
      floor: lerp(0.4, 0.02, t),
      // Seconds held open after the envelope drops, then faded over `release`.
      hold: lerp(0.5, 0.15, t),
      release: lerp(0.4, 0.12, t),
    },
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
  /**
   * Re-aim the cleanup at a new strength, live. Cheap and glitch-free — these are k-rate
   * AudioParams, so it's a value assignment on the audio thread, not a rebuilt graph. A no-op
   * on a chain that has no cleanup in it (`off`, `standard`, or a passthrough fallback), so
   * callers never have to ask which kind of chain they're holding.
   */
  setStrength: (strength: number) => void
  /** Stop the chain. Does *not* stop the capture it was built from; the caller owns that. */
  destroy: () => void
}

/** The unprocessed capture, wearing the same interface so callers have one code path. */
function passthrough(raw: MediaStream): MicChain {
  return { stream: raw, output: null, setStrength: () => {}, destroy: () => {} }
}

const workletLoaded = new Map<string, Promise<boolean>>()

/**
 * Load a worklet module once per AudioContext-lifetime. Cached as the *promise*, so two calls
 * that race (a join and a device swap) share one fetch, and cached as a boolean result so a
 * browser that can't do worklets isn't asked again every call.
 */
function loadWorklet(ctx: AudioContext, name: string): Promise<boolean> {
  let loading = workletLoaded.get(name)
  if (!loading) {
    loading = ctx.audioWorklet
      ? ctx.audioWorklet.addModule(`/worklets/${name}.js`).then(() => true).catch(() => false)
      : Promise.resolve(false)
    workletLoaded.set(name, loading)
  }

  return loading
}

/** The wasm the model lives in. Compiled once per page, not per call. */
let rnnoiseModule: Promise<WebAssembly.Module | null> | null = null

/**
 * Fetch and compile the denoiser, here on the main thread.
 *
 * This is not a stylistic choice about where to put a fetch. An `AudioWorkletGlobalScope` has no
 * `fetch` and no `XMLHttpRequest` at all, so the bytes genuinely cannot be obtained from inside
 * the worklet — the only way in is to compile out here and hand the resulting
 * `WebAssembly.Module` down through `processorOptions`, which is structured-cloneable. That
 * clone is legal because a worklet shares an agent cluster with the window; the identical
 * postMessage to a cross-origin worker would be rejected.
 *
 * Cached as the promise so a join and a device swap racing each other share one fetch, and
 * cached including its `null` so a browser or a network that failed once isn't asked on every
 * subsequent call.
 */
function loadRnnoiseModule(): Promise<WebAssembly.Module | null> {
  rnnoiseModule ??= (async () => {
    try {
      if (typeof WebAssembly?.compileStreaming !== 'function') return null
      // Streaming compilation needs the server to send application/wasm; the non-streaming
      // path is the fallback for the dev servers and proxies that don't.
      const response = await fetch('/worklets/rnnoise.wasm')
      if (!response.ok) return null

      return await WebAssembly.compileStreaming(response.clone())
        .catch(async () => WebAssembly.compile(await response.arrayBuffer()))
    } catch {
      return null
    }
  })()

  return rnnoiseModule
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
 * Two independent things can put us in WebAudio — the `high` cleanup and normalisation — so the
 * chain is assembled from whichever are asked for, and skipped entirely when neither is (a
 * round trip through WebAudio to do nothing is latency for free).
 *
 * The order is the conventional one and it matters: cut the rumble first, so nothing downstream
 * makes a decision on a desk bump or a truck outside; suppress, then gate (see the note at that
 * step for why that way round); normalise after the gate, so the level being measured is speech
 * rather than the room; compress last, so what the far end hears is level rather than earlier
 * decisions being made on a squashed signal.
 */
export async function buildMicChain(
  ctx: AudioContext,
  raw: MediaStream,
  level: NoiseSuppression,
  normalize = false,
  strength = DEFAULT_SUPPRESSION_STRENGTH,
): Promise<MicChain> {
  if (!raw.getAudioTracks().length) return passthrough(raw)

  // `off` is the raw capture and means it — someone playing an instrument doesn't want their
  // dynamics levelled any more than they want them gated.
  const wantsNormalizer = normalize && level !== 'off'
  // Without the worklet there's nothing in the gate half the browser isn't already doing.
  // Each is asked for separately: a browser that can load one and not the other should get the
  // one it can, and losing the suppressor is not a reason to lose the gate as well.
  const cleaning = level === 'high' || level === 'voice'
  const wantsGate = cleaning && await loadWorklet(ctx, 'noise-gate')
  const wantsSuppressor = level === 'high' && await loadWorklet(ctx, 'noise-suppressor')
  // Three things have to be true and any of them can fail independently: the worklet loads, the
  // wasm compiles, and the context is running at the 48kHz the model is defined at. A context
  // at another rate is the interesting one — the processor would run and produce confident
  // nonsense — so it is checked here as well as inside, and the level quietly degrades to the
  // `high`-shaped chain around it rather than to nothing.
  const rnnoiseModule = level === 'voice' && ctx.sampleRate === RNNOISE_SAMPLE_RATE
    && await loadWorklet(ctx, 'rnnoise')
    ? await loadRnnoiseModule()
    : null

  if (!wantsGate && !wantsSuppressor && !rnnoiseModule && !wantsNormalizer) return passthrough(raw)

  const built: AudioNode[] = []
  let stopNormalizer: (() => void) | null = null
  let gateNode: AudioWorkletNode | null = null
  let suppressorNode: AudioWorkletNode | null = null

  /** Push a strength onto whichever worklets this chain ended up with. */
  const setStrength = (value: number) => {
    const settings = suppressionSettings(value)

    // Named parameters, fetched by name and skipped if absent: a worklet built from an older
    // cached copy of the module may not have every one of them, and a missing parameter is a
    // reason to set the others rather than to throw in the middle of a call.
    const apply = (node: AudioWorkletNode | null, values: Record<string, number>) => {
      if (!node) return
      for (const [name, value] of Object.entries(values)) {
        const param = node.parameters.get(name)
        if (param) param.value = value
      }
    }

    apply(suppressorNode, settings.suppressor)
    apply(gateNode, settings.gate)
  }

  try {
    const source = ctx.createMediaStreamSource(raw)
    built.push(source)
    let tail: AudioNode = source

    if (wantsGate || wantsSuppressor || rnnoiseModule) {
      // Below ~85Hz there is no speech, only rumble: desk knocks, footsteps, breath on the
      // capsule, mains hum. Removing it is the single cheapest thing in this chain.
      //
      // Two biquads, not one, and that is the whole point of them. A single one rolls off at
      // 12dB/octave, so at 30Hz — where the blast of air from a `p` or `b` hitting the capsule
      // actually lands — it is only about 14dB down, and a plosive is 30dB up. What survives is
      // a low thud that the compressor then ducks the following syllable around: the "popping"
      // or "exploding" people report on an unshielded mic. Cascaded into a 4th-order Butterworth
      // (these Q values are what make the pair maximally flat rather than peaky at the corner)
      // the same corner is ~48dB down at 30Hz, which puts the pop under the voice instead of
      // over it — while everything from 100Hz up, including the lowest speaking fundamentals,
      // passes untouched.
      for (const q of [0.5412, 1.3066]) {
        const highpass = ctx.createBiquadFilter()
        highpass.type = 'highpass'
        highpass.frequency.value = 85
        highpass.Q.value = q

        built.push(highpass)
        tail = tail.connect(highpass)
      }
    }

    if (wantsSuppressor) {
      // The band-by-band cleanup, *before* the gate. This is the half that works while you are
      // talking — the gate can only choose between sending the room and sending nothing, so a
      // fan or rain under your voice survives it untouched. See the worklet.
      //
      // Ahead of the gate for two reasons: the gate then makes its open/shut decision on a
      // signal whose noise floor is already ~14dB lower, which is what lets a fixed threshold
      // work in a room that isn't quiet; and gating first would hand the suppressor long
      // stretches of near-silence to estimate the room from, which is exactly the wrong sample.
      suppressorNode = new AudioWorkletNode(ctx, 'noise-suppressor', {
        numberOfInputs: 1,
        numberOfOutputs: 1,
        outputChannelCount: [1],
      })
      const suppressor = suppressorNode

      built.push(suppressor)
      tail = tail.connect(suppressor)
    }

    if (rnnoiseModule) {
      // The suppressor's slot, never alongside it — see the note on `voice` at the top.
      const rnnoise = new AudioWorkletNode(ctx, 'rnnoise', {
        numberOfInputs: 1,
        numberOfOutputs: 1,
        outputChannelCount: [1],
        processorOptions: { module: rnnoiseModule },
      })

      // Every failure inside the processor is a *passthrough*, which is the right behaviour and
      // an awful thing to debug: picking "Voice isolation" and getting the raw microphone looks
      // exactly like picking it and having it not work very well. The processor says which
      // happened; without this nobody could ever tell.
      rnnoise.port.onmessage = (event) => {
        const data = event.data
        if (typeof data?.ready !== 'boolean') return

        if (data.ready) console.info('[mic] voice isolation active')
        else console.warn(`[mic] voice isolation inactive, passing audio through: ${data.reason}`)
      }

      built.push(rnnoise)
      tail = tail.connect(rnnoise)
    }

    if (wantsGate) {
      gateNode = new AudioWorkletNode(ctx, 'noise-gate', {
        numberOfInputs: 1,
        numberOfOutputs: 1,
        outputChannelCount: [1],
      })
      const gate = gateNode

      built.push(gate)
      tail = tail.connect(gate)
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

    // The worklets are born on their defaults; this is what puts them on the chosen strength.
    setStrength(strength)

    return {
      stream: destination.stream,
      output: compressor,
      setStrength,
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

/** Forget the cached worklet loads. Contexts are per-call, and a module belongs to a context. */
export function resetMicProcessing() {
  workletLoaded.clear()
}
