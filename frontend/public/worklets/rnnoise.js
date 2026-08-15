/**
 * RNNoise on the audio thread.
 *
 * ## What this is, next to the suppressor we already have
 *
 * {@link noise-suppressor.js} measures the room and subtracts it. That is exactly right for a
 * fan, rain or traffic — things that are *always there*, which is what makes them learnable —
 * and it is structurally unable to touch a keystroke, a clap or a door, which are over before
 * any estimate has moved. The duck it applies to impulses is a heuristic standing in for the
 * thing it cannot do.
 *
 * This is the thing it cannot do. A small recurrent network, trained on what speech sounds
 * like, decides band by band how much of each frame is a voice — so it removes a clap the first
 * time it hears one, mid-sentence, without having to have learned the room first. It replaces
 * the spectral suppressor in the chain rather than joining it: two estimators subtracting from
 * each other's output is how you get the underwater sound both of them are tuned to avoid.
 *
 * The model is `nnnoiseless`, a Rust port of Xiph's RNNoise, compiled to a freestanding wasm
 * module with no imports and no glue — see `native/rnnoise/`.
 *
 * ## Why the module arrives through processorOptions
 *
 * An `AudioWorkletGlobalScope` has no `fetch`, no `XMLHttpRequest` and no
 * `WebAssembly.instantiateStreaming` worth the name, so the bytes cannot be loaded from in here.
 * They are fetched and compiled on the main thread, and the resulting `WebAssembly.Module` is
 * passed down through `processorOptions`, which is structured-cloned. That clone is only legal
 * because a worklet lives in the same agent cluster as the window that made it — the same
 * postMessage to a cross-origin worker would be rejected.
 *
 * Instantiating is then synchronous (`new WebAssembly.Instance`), which is what makes it legal
 * in a constructor that cannot await anything.
 *
 * ## 480 against 128
 *
 * RNNoise's frame is 480 samples and the model's recurrence is defined in terms of it, so it
 * cannot be reframed. A worklet is handed 128. The ratio is 3.75 — not an integer, so there is
 * no arrangement that avoids buffering: samples accumulate until a frame is full, a frame comes
 * back, and output is drained 128 at a time from what has accumulated. That costs ~10ms of
 * latency, which is roughly what the FFT suppressor it replaces already cost.
 *
 * ## Two ways this goes silently wrong, both handled here
 *
 * - **Scale.** RNNoise deals in i16 range (±32768); Web Audio deals in ±1. Feed it ±1 and it
 *   does not fail, it just decides the entire signal is silence — a passthrough that looks
 *   like a working denoiser until you notice nothing is ever removed.
 * - **48kHz.** The model is only valid at 48kHz. The context is forced to it by the host; this
 *   checks anyway and bypasses rather than emitting something confidently wrong, because a
 *   sample rate we did not expect is not a reason to make someone inaudible.
 */

/** The context rate the model is defined at. Anything else and we bypass. */
const REQUIRED_SAMPLE_RATE = 48000
/** ±1 to ±32768 and back. */
const I16_SCALE = 32768

class RnnoiseProcessor extends AudioWorkletProcessor {
  constructor(options) {
    super()

    /** Set false by the host to bypass without tearing the graph down. */
    this.enabled = true
    /** Nothing works until the instance is up; until then every block is a passthrough. */
    this.ready = false

    this.port.onmessage = (event) => {
      if (typeof event.data?.enabled === 'boolean') this.enabled = event.data.enabled
    }

    // A model that cannot run is a stage that passes audio through, never a call that fails.
    // The host is told so it can stop offering the level, but the mic keeps working meanwhile.
    if (sampleRate !== REQUIRED_SAMPLE_RATE) {
      this.port.postMessage({ ready: false, reason: `sampleRate ${sampleRate}` })
      return
    }

    try {
      const module = options?.processorOptions?.module
      if (!module) throw new Error('no module')

      // No imports — the whole point of the freestanding build. If this ever starts throwing,
      // a dependency has pulled in something that wants a host function; see build.sh.
      const instance = new WebAssembly.Instance(module, {})
      const api = instance.exports

      api.rnnoise_init()

      this.api = api
      this.frameSize = api.rnnoise_frame_size()

      // Views straight into the module's linear memory. Safe to hold for the processor's whole
      // life *only* because nothing in there ever allocates, so the memory never grows and the
      // buffer is never detached. See the note in lib.rs.
      const memory = api.memory.buffer
      this.wasmIn = new Float32Array(memory, api.rnnoise_input_ptr(), this.frameSize)
      this.wasmOut = new Float32Array(memory, api.rnnoise_output_ptr(), this.frameSize)

      // Samples waiting to make up a frame.
      this.pending = new Float32Array(this.frameSize)
      this.pendingFill = 0

      // Denoised samples waiting to be handed out 128 at a time. Two frames is comfortably
      // above the high-water mark: at most one frame is added per block and 128 drained, so
      // the fill peaks a little over one frame and stays there.
      this.done = new Float32Array(this.frameSize * 2)
      this.doneFill = 0

      this.ready = true
      this.port.postMessage({ ready: true })
    } catch (err) {
      this.port.postMessage({ ready: false, reason: String(err) })
    }
  }

  process(inputs, outputs) {
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

    if (!this.ready || !this.enabled) {
      for (let c = 0; c < output.length; c++) output[c].set(input[c] ?? channel)
      return true
    }

    const { pending, done, wasmIn, wasmOut, frameSize } = this
    const blockSize = channel.length

    // Accumulate. A block is 128 and a frame is 480, so this usually just fills.
    for (let i = 0; i < blockSize; i++) {
      pending[this.pendingFill++] = channel[i] * I16_SCALE

      if (this.pendingFill === frameSize) {
        wasmIn.set(pending)
        this.api.rnnoise_process()

        for (let k = 0; k < frameSize; k++) {
          done[this.doneFill + k] = wasmOut[k] / I16_SCALE
        }
        this.doneFill += frameSize
        this.pendingFill = 0
      }
    }

    // Drain a block. Empty only while the very first frame is filling — a handful of blocks at
    // the start of a call, where silence is the honest answer and nobody is talking yet.
    const out0 = output[0]
    if (this.doneFill >= blockSize) {
      out0.set(done.subarray(0, blockSize))
      done.copyWithin(0, blockSize, this.doneFill)
      this.doneFill -= blockSize
    } else {
      out0.fill(0)
    }

    // Mono in, mono out; anything downstream asking for more channels gets the same signal
    // rather than silence on channel 1.
    for (let c = 1; c < output.length; c++) output[c].set(out0)

    return true
  }
}

registerProcessor('rnnoise', RnnoiseProcessor)
