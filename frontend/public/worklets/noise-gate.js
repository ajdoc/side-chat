/**
 * A noise gate, running on the audio thread.
 *
 * ## Why a worklet and not a rAF loop
 *
 * A gate is a gain that has to move in step with the waveform: open within a few
 * milliseconds of a word starting, or the first consonant is eaten. The obvious
 * implementation — read an AnalyserNode from requestAnimationFrame and ramp a GainNode —
 * runs on the main thread, which means it moves at 60Hz on a good day, stalls whenever a
 * render is expensive, and is *throttled to roughly once a second in a background tab*.
 * A gate frozen shut for a second is the worst possible failure here: you are talking and
 * nobody can hear you. On the audio thread it is neither throttled nor jittery, and 128
 * samples is ~2.7ms of resolution for free.
 *
 * ## What it does
 *
 * Envelope-follow the input, and hold the gain open while that envelope is above a
 * threshold. Two details make it sound like a gate rather than a chopper:
 *
 * - **Hysteresis.** It opens at `threshold` but doesn't close until the envelope falls
 *   below a fraction of it. A single threshold makes speech that hovers right at the line
 *   flutter — the classic broken-gate sound.
 * - **Hold, then release.** Speech is full of short gaps (stops, the pause inside "t-t"),
 *   so the gate stays open for `hold` seconds after the envelope drops and then fades out
 *   over `release` rather than cutting.
 *
 * Closed is quiet but not silent (`floor`): a room that drops to *digital* zero between
 * words sounds like the connection died. A whisper of the original noise floor is what
 * tells the far end the line is still up.
 */

const FRAME = 128

class NoiseGateProcessor extends AudioWorkletProcessor {
  static get parameterDescriptors() {
    return [
      // Envelope level at which the gate opens. Tuned for a mono speech capture that has
      // already been through the browser's own noise suppression.
      { name: 'threshold', defaultValue: 0.015, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
      // Close only below threshold × this. See hysteresis, above.
      { name: 'ratio', defaultValue: 0.55, minValue: 0.05, maxValue: 1, automationRate: 'k-rate' },
      // Seconds. Attack is short enough not to clip a word's onset; release is long enough
      // that the tail of a word isn't sheared off.
      { name: 'attack', defaultValue: 0.006, minValue: 0.001, maxValue: 0.2, automationRate: 'k-rate' },
      { name: 'release', defaultValue: 0.18, minValue: 0.01, maxValue: 2, automationRate: 'k-rate' },
      { name: 'hold', defaultValue: 0.25, minValue: 0, maxValue: 2, automationRate: 'k-rate' },
      // How loud the input is allowed to be while the gate is shut.
      { name: 'floor', defaultValue: 0.06, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
    ]
  }

  constructor() {
    super()
    /** The smoothed input level the gate decides on. */
    this.envelope = 0
    /** The gain actually applied, ramped towards the target so it never steps. */
    this.gain = 0
    /** Seconds of "open" left on the clock after the envelope fell back under. */
    this.holdLeft = 0
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

    const threshold = parameters.threshold[0]
    const closeAt = threshold * parameters.ratio[0]
    const floor = parameters.floor[0]
    const frameSeconds = FRAME / sampleRate

    // One decision per block rather than per sample: 2.7ms is already finer than any
    // envelope worth tracking here, and it keeps this loop cheap enough to be invisible.
    let sum = 0
    for (let i = 0; i < channel.length; i++) sum += channel[i] * channel[i]
    const rms = Math.sqrt(sum / channel.length)

    // Rise fast, fall slow: the follower should see a word start immediately but not chase
    // every dip inside one.
    this.envelope = rms > this.envelope
      ? this.envelope + (rms - this.envelope) * 0.5
      : this.envelope + (rms - this.envelope) * 0.05

    if (this.envelope > threshold) this.holdLeft = parameters.hold[0]
    else if (this.envelope < closeAt) this.holdLeft = Math.max(0, this.holdLeft - frameSeconds)

    const target = this.holdLeft > 0 || this.envelope > threshold ? 1 : floor
    // Exponential approach, with the time constant chosen by which way we're heading —
    // a gate that opens as slowly as it closes swallows the start of every sentence.
    const tau = target > this.gain ? parameters.attack[0] : parameters.release[0]
    const coeff = 1 - Math.exp(-frameSeconds / Math.max(tau, 0.001))
    const from = this.gain
    this.gain += (target - this.gain) * coeff

    for (let c = 0; c < output.length; c++) {
      const inChannel = input[c] ?? channel
      const outChannel = output[c]
      // Ramp the gain *across* the block instead of applying one value to all 128 samples.
      // A gain that steps between blocks is a discontinuity, and a discontinuity is a click.
      for (let i = 0; i < outChannel.length; i++) {
        outChannel[i] = inChannel[i] * (from + (this.gain - from) * (i / outChannel.length))
      }
    }

    return true
  }
}

registerProcessor('noise-gate', NoiseGateProcessor)
