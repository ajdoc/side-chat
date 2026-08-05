/**
 * Take the call back out of a shared screen's sound.
 *
 * ## The problem this exists for
 *
 * "Share the sound too" on a whole screen is, on every desktop platform, a capture of the
 * machine's entire output — there is no per-application audio capture Electron can reach. On a
 * call that output includes the other people in the call, coming out of your own speakers. So
 * their voices ride back up the share to them, and everybody hears themselves a fraction of a
 * second late. Headphones don't help: the loopback is taken digitally, before anything reaches
 * an output device.
 *
 * The signal we need to remove is one we happen to know exactly — we are the thing playing it.
 * So this processor takes two inputs: the capture (input 0) and the mix of everything the call
 * is playing (input 1), and subtracts an adaptively-estimated version of the second from the
 * first. What's left is the machine's *other* sound — the video, the game, the music — which is
 * the half of the capture the share was for.
 *
 * ## Why an adaptive filter and not a subtraction
 *
 * The reference is not the echo. Between us playing a sample and it arriving back in the
 * capture sits an unknown delay (the render pipeline, tens of milliseconds and different on
 * every machine), an unknown gain, and whatever the OS mixer does on the way. That chain is
 * linear and roughly time-invariant, which is precisely the thing an adaptive filter can learn:
 * it estimates the impulse response from reference to capture and keeps re-estimating it as the
 * volume, the device or the panning move underneath it.
 *
 * ## The shape of it
 *
 * Partitioned-block frequency-domain NLMS, overlap-save. A time-domain filter long enough to
 * cover ~85ms at 48kHz is 4096 taps, which is ~800M multiply-accumulates a second per channel —
 * not something to run on the audio thread. Cutting the same filter into {@link PARTITIONS}
 * blocks and doing each one as a complex multiply in the frequency domain costs a couple of
 * FFTs per block instead, which is roughly a thousandth of the work.
 *
 * The update is left *unconstrained* (no gradient projection back through an FFT pair per
 * partition), which is the standard trade: slightly slower convergence for a third of the cost.
 *
 * ## Double-talk
 *
 * The hard part isn't the filter, it's that the near end is never silent here — the whole point
 * of the capture is that it also contains media we are *not* trying to remove. An adaptive
 * filter that treats that media as error will happily diverge chasing it. Two guards:
 *
 *  - The step size is scaled by how much of the capture the current estimate says is echo. When
 *    the shared media dominates, adaptation nearly stops, and the filter coasts on what it has
 *    already learnt rather than being pulled apart by a signal it can't predict.
 *  - A gentle Wiener-style post-gain on the residual, so what the filter couldn't cancel is at
 *    least ducked while it is being drowned in echo, and left alone when it isn't.
 */

/** Samples per adaptation block. Two render quanta — small enough not to add audible delay. */
const BLOCK = 128 * 2
/** FFT length. Twice the block, which is what overlap-save needs to avoid circular wrap. */
const FFT_SIZE = BLOCK * 2
/** How many blocks of filter. PARTITIONS × BLOCK taps ≈ 85ms at 48kHz — a generous playout path. */
const PARTITIONS = 16
/** Base step size. Conservative: this filter is allowed to be slow, never to blow up. */
const MU = 0.35
/** Keeps every divide honest on silence. */
const EPS = 1e-8
/** How fast the residual post-gain moves. Per block, so ~5ms a step at 48kHz. */
const GAIN_SMOOTHING = 0.3
/** The post-gain never closes past this — a duck, not a gate. */
const MIN_GAIN = 0.05

/** Bit-reversal permutation and twiddles for one FFT length, computed once. */
function tables(n) {
  const reverse = new Uint32Array(n)
  const bits = Math.log2(n)
  for (let i = 0; i < n; i++) {
    let r = 0
    for (let b = 0; b < bits; b++) r |= ((i >> b) & 1) << (bits - 1 - b)
    reverse[i] = r
  }

  const cos = new Float32Array(n / 2)
  const sin = new Float32Array(n / 2)
  for (let i = 0; i < n / 2; i++) {
    cos[i] = Math.cos((-2 * Math.PI * i) / n)
    sin[i] = Math.sin((-2 * Math.PI * i) / n)
  }

  return { reverse, cos, sin, n }
}

const TABLES = tables(FFT_SIZE)

/**
 * In-place iterative radix-2 FFT. `inverse` runs the same butterflies with conjugated twiddles
 * and scales by 1/n, so the pair round-trips exactly.
 */
function fft(re, im, inverse) {
  const { reverse, cos, sin, n } = TABLES

  for (let i = 0; i < n; i++) {
    const j = reverse[i]
    if (j > i) {
      let t = re[i]; re[i] = re[j]; re[j] = t
      t = im[i]; im[i] = im[j]; im[j] = t
    }
  }

  for (let size = 2; size <= n; size <<= 1) {
    const half = size >> 1
    const step = n / size
    for (let i = 0; i < n; i += size) {
      for (let j = 0, k = 0; j < half; j++, k += step) {
        const wr = cos[k]
        const wi = inverse ? -sin[k] : sin[k]
        const a = i + j
        const b = a + half
        const xr = re[b] * wr - im[b] * wi
        const xi = re[b] * wi + im[b] * wr
        re[b] = re[a] - xr
        im[b] = im[a] - xi
        re[a] += xr
        im[a] += xi
      }
    }
  }

  if (inverse) {
    for (let i = 0; i < n; i++) {
      re[i] /= n
      im[i] /= n
    }
  }
}

/** One channel's filter: its history, its weights, and the running reference power. */
function channelState() {
  const partitions = []
  const weights = []
  for (let p = 0; p < PARTITIONS; p++) {
    partitions.push({ re: new Float32Array(FFT_SIZE), im: new Float32Array(FFT_SIZE) })
    weights.push({ re: new Float32Array(FFT_SIZE), im: new Float32Array(FFT_SIZE) })
  }

  return {
    /** The last FFT_SIZE reference samples — the overlap-save window. */
    history: new Float32Array(FFT_SIZE),
    /** Spectra of the last PARTITIONS reference blocks, newest at `cursor`. */
    partitions,
    weights,
    cursor: 0,
    /** Σ|X|² per bin over the stored partitions — the "normalized" in NLMS. */
    power: new Float32Array(FFT_SIZE),
    /** Scratch, so the audio thread never allocates. */
    re: new Float32Array(FFT_SIZE),
    im: new Float32Array(FFT_SIZE),
    errRe: new Float32Array(FFT_SIZE),
    errIm: new Float32Array(FFT_SIZE),
    residual: new Float32Array(BLOCK),
    gain: 1,
  }
}

class EchoCanceller extends AudioWorkletProcessor {
  constructor() {
    super()
    this.states = []
    /** Captured samples waiting for a full block, and cleaned ones waiting to go out. */
    this.pending = []
    this.enabled = true

    // The graph is built before the share exists and torn down after it, so the node can be
    // told to stand aside rather than being spliced in and out of a live chain.
    this.port.onmessage = (event) => {
      if (event.data?.enabled !== undefined) this.enabled = !!event.data.enabled
    }
  }

  /** Process one aligned block: estimate the echo, subtract it, adapt. Returns the residual. */
  runBlock(state, capture, reference) {
    const { history, re, im, power } = state

    // Slide the overlap-save window: the previous block's samples become the tail context for
    // this one, which is what makes the circular convolution a linear one.
    history.copyWithin(0, BLOCK)
    history.set(reference, BLOCK)

    re.set(history)
    im.fill(0)
    fft(re, im, false)

    // Replace the oldest partition, keeping the running power sum in step rather than
    // recomputing it over all sixteen every block.
    state.cursor = (state.cursor + 1) % PARTITIONS
    const slot = state.partitions[state.cursor]
    for (let i = 0; i < FFT_SIZE; i++) {
      power[i] -= slot.re[i] * slot.re[i] + slot.im[i] * slot.im[i]
      slot.re[i] = re[i]
      slot.im[i] = im[i]
      power[i] += re[i] * re[i] + im[i] * im[i]
      if (power[i] < 0) power[i] = 0 // float drift over a long call
    }

    // Y = Σ W[p]·X[p], the estimated echo, accumulated across the partitions.
    const { errRe: yRe, errIm: yIm } = state
    yRe.fill(0)
    yIm.fill(0)
    for (let p = 0; p < PARTITIONS; p++) {
      const x = state.partitions[(state.cursor - p + PARTITIONS) % PARTITIONS]
      const w = state.weights[p]
      for (let i = 0; i < FFT_SIZE; i++) {
        yRe[i] += w.re[i] * x.re[i] - w.im[i] * x.im[i]
        yIm[i] += w.re[i] * x.im[i] + w.im[i] * x.re[i]
      }
    }
    fft(yRe, yIm, true)

    // Overlap-save: only the second half of the inverse transform is the linear convolution.
    let echoEnergy = 0
    let captureEnergy = 0
    let residualEnergy = 0
    const residual = state.residual
    for (let i = 0; i < BLOCK; i++) {
      const echo = yRe[BLOCK + i]
      const e = capture[i] - echo
      residual[i] = e
      echoEnergy += echo * echo
      captureEnergy += capture[i] * capture[i]
      residualEnergy += e * e
    }

    // How much of what we just heard do we believe was echo? That fraction is the step size.
    // Media playing over the top pushes it towards zero, which freezes the filter instead of
    // letting it chase a signal it has no reference for. See the double-talk note above.
    const confidence = echoEnergy / (captureEnergy + EPS)
    const mu = MU * Math.min(1, confidence)

    if (mu > 0.001) {
      const { re: eRe, im: eIm } = state
      eRe.fill(0)
      eIm.fill(0)
      eRe.set(residual, BLOCK) // the error belongs in the same half the output came from
      fft(eRe, eIm, false)

      for (let p = 0; p < PARTITIONS; p++) {
        const x = state.partitions[(state.cursor - p + PARTITIONS) % PARTITIONS]
        const w = state.weights[p]
        for (let i = 0; i < FFT_SIZE; i++) {
          const norm = mu / (power[i] + EPS)
          // conj(X)·E, scaled per bin by the reference's own power — the whole point of NLMS:
          // a loud bin and a quiet one converge at the same rate.
          w.re[i] += norm * (x.re[i] * eRe[i] + x.im[i] * eIm[i])
          w.im[i] += norm * (x.re[i] * eIm[i] - x.im[i] * eRe[i])
        }
      }
    }

    // What the filter couldn't remove is ducked in proportion to how buried it is. Whenever the
    // capture is mostly its own media this is ~1 and does nothing at all.
    const target = Math.max(MIN_GAIN, residualEnergy / (residualEnergy + echoEnergy + EPS))
    state.gain += (target - state.gain) * GAIN_SMOOTHING
    for (let i = 0; i < BLOCK; i++) residual[i] *= state.gain

    return residual
  }

  process(inputs, outputs) {
    const capture = inputs[0]
    const reference = inputs[1]
    const output = outputs[0]
    if (!capture?.length || !output?.length) return true

    for (let c = 0; c < output.length; c++) {
      const near = capture[Math.min(c, capture.length - 1)]
      const out = output[c]
      if (!near) continue

      // No reference (nothing is playing, or the mix hasn't been connected) means nothing to
      // cancel — and equally, nothing to get wrong. Pass the capture straight through.
      const far = this.enabled && reference?.length
        ? reference[Math.min(c, reference.length - 1)]
        : null
      if (!far) {
        out.set(near)
        continue
      }

      if (!this.states[c]) {
        this.states[c] = channelState()
        this.pending[c] = { capture: new Float32Array(BLOCK), reference: new Float32Array(BLOCK), out: new Float32Array(BLOCK), filled: 0 }
      }

      const buffer = this.pending[c]
      const state = this.states[c]

      // A render quantum is 128 samples and a block is two of them, so this fills, processes on
      // the second quantum, and emits the block one quantum late — a fixed ~2.7ms of delay on
      // the shared audio, which is inaudible and, unlike the echo, harmless.
      buffer.capture.set(near, buffer.filled)
      buffer.reference.set(far, buffer.filled)
      out.set(buffer.out.subarray(buffer.filled, buffer.filled + near.length))
      buffer.filled += near.length

      if (buffer.filled >= BLOCK) {
        buffer.out.set(this.runBlock(state, buffer.capture, buffer.reference))
        buffer.filled = 0
      }
    }

    return true
  }
}

registerProcessor('echo-canceller', EchoCanceller)
