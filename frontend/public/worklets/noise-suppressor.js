/**
 * Spectral noise suppression, running on the audio thread.
 *
 * ## The thing the gate can't do
 *
 * A noise gate ({@link noise-gate.js}) is a decision about the *whole* signal: open, or shut.
 * That makes the silences between words genuinely silent, which is most of a call and well
 * worth having — but while you are actually talking the gate is open, and everything else in
 * your room comes through with you. A fan, rain on a window, a motorcycle going past: the
 * complaint is never that they're audible in the gaps, it's that they're audible *under your
 * voice*, and no amount of gate tuning reaches that.
 *
 * This does. It splits the signal into frequency bands several hundred times a second, works
 * out how much of each band is noise rather than speech, and turns down the bands that are
 * mostly noise while leaving the bands carrying your voice alone. Because it's a per-band
 * decision it applies just as much mid-word as mid-pause, and because the estimate is learned
 * from your actual room it doesn't need to know in advance whether the problem is a fan or the
 * weather.
 *
 * ## How the noise estimate is learned
 *
 * Per bin, and by tracking downward fast but upward slowly. Noise is the part of the signal
 * that is *always there*; speech is what rises above it and then goes away again. A tracker
 * that falls quickly onto the floor and climbs back only reluctantly therefore settles on the
 * noise and ignores the speech on top of it — no "please be quiet for five seconds"
 * calibration step, and no voice-activity detector to get wrong.
 *
 * The one failure that shape does have is a long uninterrupted sentence, over which the
 * estimate can creep up into the speech and start eating it. So the upward climb is allowed to
 * be brisk only when the frame as a whole looks like noise, and is made ten times slower when
 * it doesn't — see `NOISE_UP_*` below.
 *
 * ## Two things stop it sounding like a bad phone
 *
 * Naive spectral subtraction produces "musical noise": isolated bins flickering above the
 * threshold from frame to frame, which sounds like wind chimes in the background. The fixes are
 * both here and both standard — smooth the gain curve *across* frequency (a lone loud bin
 * between two quiet ones is almost always an artefact, not a harmonic), and smooth it *across
 * time*, asymmetrically, so a band opens quickly for a word onset but closes gently.
 *
 * The other is the floor: bands are attenuated toward `floor`, never to zero. Total removal is
 * what makes a suppressor sound like it's swallowing the speaker; leaving a trace of the room
 * under everything is what makes it sound like a clean line instead of a dead one.
 *
 * ## Cost and latency
 *
 * A 512-point transform per 128-sample block — 75% overlap, which is what lets the gain curve
 * move this fast without the frame edges clicking. That's ~8ms of added latency at 48kHz and a
 * few million operations a second, both of which are cheap next to the call itself.
 */

const FFT_SIZE = 512
const HOP = 128
const BINS = FFT_SIZE / 2 + 1

/** Iterative in-place radix-2 FFT, with the twiddles and bit-reversal computed once. */
class FFT {
  constructor(n) {
    this.n = n
    this.levels = Math.round(Math.log2(n))

    this.cos = new Float32Array(n / 2)
    this.sin = new Float32Array(n / 2)
    for (let i = 0; i < n / 2; i++) {
      this.cos[i] = Math.cos((2 * Math.PI * i) / n)
      this.sin[i] = Math.sin((2 * Math.PI * i) / n)
    }

    this.rev = new Uint16Array(n)
    for (let i = 0; i < n; i++) {
      let r = 0
      for (let b = 0; b < this.levels; b++) r |= ((i >> b) & 1) << (this.levels - 1 - b)
      this.rev[i] = r
    }
  }

  /** Forward transform, in place, twiddle `e^-2πik/n`. */
  transform(re, im) {
    const { n, cos, sin, rev } = this

    for (let i = 0; i < n; i++) {
      const j = rev[i]
      if (j > i) {
        let t = re[i]; re[i] = re[j]; re[j] = t
        t = im[i]; im[i] = im[j]; im[j] = t
      }
    }

    for (let size = 2; size <= n; size *= 2) {
      const half = size / 2
      const step = n / size
      for (let i = 0; i < n; i += size) {
        for (let j = i, k = 0; j < i + half; j++, k += step) {
          const l = j + half
          const tre = re[l] * cos[k] + im[l] * sin[k]
          const tim = -re[l] * sin[k] + im[l] * cos[k]
          re[l] = re[j] - tre
          im[l] = im[j] - tim
          re[j] += tre
          im[j] += tim
        }
      }
    }
  }

  /**
   * Inverse transform, in place.
   *
   * `IFFT(x) = conj(FFT(conj(x))) / n`, and conjugating either side of a forward transform is
   * the same as handing it its real and imaginary parts the other way round — so the inverse
   * is the forward transform with the arguments swapped, scaled. No second set of tables.
   */
  inverse(re, im) {
    this.transform(im, re)
    const inv = 1 / this.n
    for (let i = 0; i < this.n; i++) {
      re[i] *= inv
      im[i] *= inv
    }
  }
}

/**
 * How fast the estimate averages the magnitude of a frame that looks like noise. ~0.2s.
 *
 * A plain average, deliberately, and this is the detail the whole thing turns on. Tracking
 * asymmetrically — fall fast, climb slowly — is the obvious way to ignore speech without a
 * detector, and it does converge; but it converges on roughly the *minimum* of a magnitude
 * that fluctuates frame to frame, not its mean, which is several dB low. Subtract that and the
 * result is a suppressor that measurably does almost nothing (about 5dB on steady noise, which
 * is the difference between "the fan is gone" and "the fan is still there"). So the frames we
 * believe are noise get an honest mean, and the job of not learning the speech is given to the
 * check below instead.
 */
const NOISE_ADAPT = 0.015
/**
 * How fast it may fall during speech.
 *
 * Downward only: the room genuinely going quieter mid-sentence must be followed, but nothing
 * about a frame containing speech is evidence that the noise floor has *risen*.
 */
const NOISE_DOWN = 0.35
/** Frame energy over estimated noise energy, above which we call the frame speech. */
const SPEECH_RATIO = 2.2
/** Frames treated as pure noise on startup, to converge in ~50ms instead of seconds. */
const PRIME_FRAMES = 20

/** Gain smoothing across time: open quickly for a word, close gently to avoid chattering. */
const GAIN_OPEN = 0.7
const GAIN_CLOSE = 0.3

/**
 * ## Impulses, which the estimate above is blind to by construction
 *
 * Everything so far learns what is *always there* and subtracts it. A keystroke is the exact
 * opposite: five milliseconds of broadband energy 30dB over the room, gone before the estimate
 * has moved a bin. So it passes through at full strength — and then, because it passed through,
 * every envelope downstream reacts to it. The spectral gains all snap open and close again over
 * ~10ms, the gate takes it for a word onset and holds the room open for a quarter second after
 * it, and the compressor ducks and recovers. What arrives at the far end is not a click; it is
 * a gust — the sound people describe as wind or a small explosion behind the typing.
 *
 * Detecting one needs two facts together, because either alone has a common false positive:
 *
 * - **A sudden rise** over a slow energy reference. Speech rises fast too, so on its own this
 *   fires on every word onset.
 * - **A flat spectrum.** Voiced speech is harmonic — energy in peaks with gaps between them,
 *   spectral flatness around 0.01–0.1. A click, a clap, a mouse button is broadband hash near
 *   0.3–0.6. On its own this fires on `s` and `sh`, which are also broadband — but those
 *   neither rise this fast nor carry this much energy.
 *
 * What's done about one is deliberately a *duck* and not a removal, and this is the honest limit
 * of the approach. A `t` or `k` release burst is itself a broadband impulse: it is a click that
 * happens to be part of a word. Removing impulses outright takes the edge off consonants and
 * makes speech sound mushy, so this takes ~12dB off at full strength — enough that a keystroke
 * stops driving the envelopes downstream and lands as a soft tick, little enough that a plosive
 * keeps its shape. Telling the two apart properly means knowing what speech *is*, which is a
 * model, not a heuristic; see the note on `high` in micProcessing.ts.
 */
/** Frame energy over its slow reference, above which a frame is a candidate impulse. */
const TRANSIENT_RISE = 8
/** Spectral flatness above which a frame is broadband hash rather than a voice. */
const TRANSIENT_FLATNESS = 0.22
/** How fast the reference the rise is measured against moves. ~135ms — slower than any impulse,
 *  faster than the noise estimate, so it tracks speech level rather than the room. */
const TRANSIENT_REFERENCE = 0.02
/** Frames the duck is held after the last impulse frame, so the ring-out is ducked too. */
const TRANSIENT_HOLD = 3
/** Duck envelope: down fast enough to catch a 5ms impulse, back up over ~40ms. */
const TRANSIENT_DOWN = 0.6
const TRANSIENT_UP = 0.12
/** The band flatness is measured over. Below this is rumble the highpass owns, above it is
 *  hiss that is flat whatever made it. */
const TRANSIENT_LO_HZ = 200
const TRANSIENT_HI_HZ = 8000

class NoiseSuppressorProcessor extends AudioWorkletProcessor {
  static get parameterDescriptors() {
    return [
      // How hard to subtract. Scales the oversubtraction factor — 1 removes the estimated
      // noise and no more, which in practice leaves an audible residue because the estimate is
      // an average and the noise is not; more than that trades a little speech colour for a
      // quieter background.
      { name: 'amount', defaultValue: 0.8, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
      // The quietest a suppressed band is allowed to get. Not zero — see the note above.
      { name: 'floor', defaultValue: 0.08, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
      // How hard to duck a detected impulse — 0 leaves keystrokes and claps alone, 1 takes
      // ~12dB off them. See the note on impulses above for why it is never more than that.
      { name: 'transient', defaultValue: 0.6, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
    ]
  }

  constructor() {
    super()

    this.fft = new FFT(FFT_SIZE)

    // Hann, used for both analysis and synthesis. At 75% overlap the squared window sums to a
    // constant, which is what makes overlap-add reconstruct the input exactly when every gain
    // is 1. Measured rather than hard-coded: it's the one number a wrong FFT_SIZE/HOP pairing
    // would silently get wrong, and a constant that's off by a factor is a volume bug.
    this.window = new Float32Array(FFT_SIZE)
    for (let i = 0; i < FFT_SIZE; i++) this.window[i] = 0.5 - 0.5 * Math.cos((2 * Math.PI * i) / FFT_SIZE)

    let cola = 0
    for (let i = 0; i < FFT_SIZE; i += HOP) cola += this.window[i] * this.window[i]
    this.colaScale = 1 / cola

    /** The last FFT_SIZE input samples, oldest first. */
    this.inBuf = new Float32Array(FFT_SIZE)
    /** Overlap-add accumulator; its first HOP samples are the next output block. */
    this.outBuf = new Float32Array(FFT_SIZE)

    this.re = new Float32Array(FFT_SIZE)
    this.im = new Float32Array(FFT_SIZE)
    this.mag = new Float32Array(BINS)
    this.noise = new Float32Array(BINS)
    this.gain = new Float32Array(BINS).fill(1)
    this.raw = new Float32Array(BINS)

    this.frames = 0

    // Impulse detection. The band is resolved once here rather than per frame; `sampleRate` is
    // fixed for the processor's lifetime, and a bin index is a divide we don't need 375 times a
    // second. Clamped so an unusual rate can't walk the loop off the end of the array.
    const binHz = sampleRate / FFT_SIZE
    this.flatLo = Math.max(1, Math.min(BINS - 2, Math.round(TRANSIENT_LO_HZ / binHz)))
    this.flatHi = Math.max(this.flatLo + 1, Math.min(BINS - 1, Math.round(TRANSIENT_HI_HZ / binHz)))
    /** Slow energy reference an impulse is a rise *over*. */
    this.slowEnergy = 0
    /** Frames of duck left on the clock. */
    this.transientHold = 0
    /** The duck actually applied, ramped so it never steps. 1 = not ducking. */
    this.transientGain = 1

    /** Set false by the host to bypass without tearing the graph down. */
    this.enabled = true

    this.port.onmessage = (event) => {
      if (typeof event.data?.enabled === 'boolean') this.enabled = event.data.enabled
    }
  }

  process(inputs, outputs, parameters) {
    const input = inputs[0]
    const output = outputs[0]
    if (!output) return true

    const channel = input?.[0]
    // No input yet (the source hasn't started, or the track was stopped): emit silence and
    // stay alive. Returning false here would retire the processor for good.
    if (!channel) {
      for (const out of output) out.fill(0)
      return true
    }

    if (!this.enabled) {
      for (let c = 0; c < output.length; c++) output[c].set(input[c] ?? channel)
      return true
    }

    const { inBuf, outBuf, re, im, mag, noise, gain, raw, window } = this
    const amount = parameters.amount[0]
    const floor = parameters.floor[0]
    // How much noise a band must be carrying before it's turned down at all, as a multiple of
    // the estimate. Above 1 because the estimate is a mean and the noise is not: a band sitting
    // at exactly its average is still noise, and so is one a little above it.
    const over = 1 + 3 * amount

    // Slide the analysis window along by one block.
    inBuf.copyWithin(0, HOP)
    inBuf.set(channel.subarray(0, HOP), FFT_SIZE - HOP)

    for (let i = 0; i < FFT_SIZE; i++) {
      re[i] = inBuf[i] * window[i]
      im[i] = 0
    }
    this.fft.transform(re, im)

    let frameEnergy = 0
    let noiseEnergy = 0
    for (let k = 0; k < BINS; k++) {
      const m = Math.hypot(re[k], im[k])
      mag[k] = m
      frameEnergy += m * m
      noiseEnergy += noise[k] * noise[k]
    }

    // Is this frame speech, or just the room? Only used to decide how eagerly the estimate is
    // allowed to climb — being wrong costs a little tracking speed, never a decision about
    // what to send.
    this.frames++
    const priming = this.frames <= PRIME_FRAMES
    const isSpeech = !priming && frameEnergy > noiseEnergy * SPEECH_RATIO * SPEECH_RATIO

    // Impulse? Rise first, because it's a comparison and the flatness below is a log per bin —
    // on the overwhelming majority of frames nothing has risen and the sum is never paid.
    const rise = frameEnergy / (this.slowEnergy + 1e-20)
    if (!priming && rise > TRANSIENT_RISE) {
      let logSum = 0
      let linSum = 0
      for (let k = this.flatLo; k <= this.flatHi; k++) {
        const power = mag[k] * mag[k] + 1e-20
        logSum += Math.log(power)
        linSum += power
      }
      const count = this.flatHi - this.flatLo + 1
      // Geometric mean over arithmetic mean: 1 for a perfectly flat spectrum, near 0 for one
      // that is all peaks. The exp/log pair is how you take a geometric mean without underflow.
      const flatness = Math.exp(logSum / count) / (linSum / count)
      if (flatness > TRANSIENT_FLATNESS) this.transientHold = TRANSIENT_HOLD
    }

    // Slow reference, updated after the test so an impulse isn't measured against itself.
    this.slowEnergy += (frameEnergy - this.slowEnergy) * TRANSIENT_REFERENCE

    const ducking = this.transientHold > 0
    if (ducking) this.transientHold--
    // Full strength is ~12dB off; the parameter scales that back toward untouched.
    const duckTarget = ducking ? 1 - 0.75 * parameters.transient[0] : 1
    this.transientGain += (duckTarget - this.transientGain)
      * (duckTarget < this.transientGain ? TRANSIENT_DOWN : TRANSIENT_UP)
    const duck = this.transientGain

    for (let k = 0; k < BINS; k++) {
      const m = mag[k]

      // Startup: assume the room is the room, and converge on it fast. Joining a call and
      // talking immediately is the case this gets wrong, and it corrects within a second.
      if (priming) noise[k] += (m - noise[k]) * NOISE_DOWN
      // A frame with no speech in it is the only honest measurement of the room there is.
      else if (!isSpeech) noise[k] += (m - noise[k]) * NOISE_ADAPT
      // …and during speech, follow the floor down but never up. See NOISE_DOWN.
      else if (m < noise[k]) noise[k] += (m - noise[k]) * NOISE_DOWN

      // A Wiener gain — `snr / (snr + 1)` — rather than subtracting the estimated noise
      // magnitude outright. Subtraction is the more obvious rule and it is the wrong one here:
      // it charges every band the same absolute amount, so a band carrying speech ten times
      // louder than the room still loses a third of itself, and a whole sentence arrives dull.
      // The Wiener gain scales with how much of the band is *actually* noise, so it leaves a
      // strong band essentially untouched while still collapsing a weak one to the floor.
      const n = noise[k]
      const snr = Math.max(0, (m * m) / (n * n + 1e-20) - over)
      raw[k] = snr / (snr + 1)
    }

    for (let k = 0; k < BINS; k++) {
      // Across frequency: a single bin standing above its neighbours is far more often an
      // artefact of the estimate than a real harmonic, and leaving those in is what "musical
      // noise" is. Edges clamp to themselves rather than wrapping into nothing.
      const lo = raw[k > 0 ? k - 1 : 0]
      const hi = raw[k < BINS - 1 ? k + 1 : BINS - 1]
      let g = 0.25 * lo + 0.5 * raw[k] + 0.25 * hi
      if (g < floor) g = floor

      // Across time, asymmetrically — see GAIN_OPEN / GAIN_CLOSE.
      const prev = gain[k]
      gain[k] = prev + (g - prev) * (g > prev ? GAIN_OPEN : GAIN_CLOSE)

      // The duck multiplies the smoothed gain rather than feeding into it: it's a decision about
      // this instant, and folding it into `gain` would leave the smoother crawling back up from
      // it for tens of milliseconds after the impulse — the tail that made a keystroke a gust.
      const applied = gain[k] * duck
      re[k] *= applied
      im[k] *= applied
      // The upper half of the spectrum is the conjugate mirror of the lower. Keeping it that
      // way is what makes the inverse transform come back real; bin 0 and Nyquist are their
      // own mirror and must not be written twice.
      if (k > 0 && k < BINS - 1) {
        const mirror = FFT_SIZE - k
        re[mirror] *= applied
        im[mirror] *= applied
      }
    }

    this.fft.inverse(re, im)

    // Synthesis window + overlap-add. The tail was zeroed on the way out last time, so the
    // block sliding into it starts clean.
    for (let i = 0; i < FFT_SIZE; i++) outBuf[i] += re[i] * window[i] * this.colaScale

    for (let c = 0; c < output.length; c++) {
      const out = output[c]
      for (let i = 0; i < HOP && i < out.length; i++) out[i] = outBuf[i]
    }

    outBuf.copyWithin(0, HOP)
    outBuf.fill(0, FFT_SIZE - HOP)

    return true
  }
}

registerProcessor('noise-suppressor', NoiseSuppressorProcessor)
