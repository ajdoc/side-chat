//! RNNoise, wrapped for an AudioWorklet.
//!
//! ## What this is for
//!
//! The spectral suppressor in `noise-suppressor.js` learns what is *always there* and subtracts
//! it, which is the right tool for a fan, rain or traffic and structurally the wrong one for a
//! keystroke, a clap or a dog. Those are gone before any estimate has moved. This is the other
//! approach: a small recurrent network that was trained on what speech sounds like, so it can
//! decide a clap is not speech the first time it hears one.
//!
//! The model and the DSP are `nnnoiseless`, a safe-Rust port of Xiph's RNNoise. Nothing in this
//! file is signal processing — it is the seam between that crate and a worklet, and it exists
//! because the worklet cannot allocate, cannot import, and cannot call anything that looks like
//! an operating system.
//!
//! ## Why there is no allocator traffic and no imports
//!
//! Everything is a `static`: one denoiser, one input frame, one output frame, all reachable by
//! pointer. The worklet writes samples into `input_ptr()`, calls `process()`, reads from
//! `output_ptr()`. No arguments, no return buffers, nothing to free — the audio thread must not
//! be waiting on an allocator, and a module that never grows its memory is a module whose
//! `Float32Array` views stay valid for the lifetime of the instance.
//!
//! That matters more than it sounds like: a `WebAssembly.Memory` that grows detaches every view
//! into it, and the failure mode is a processor that silently emits zeroes halfway through a
//! call. Allocating once, at construction, makes it unrepresentable.
//!
//! ## One instance is one denoiser
//!
//! The statics here look like a singleton, and they are — per *instance*. Each
//! `AudioWorkletProcessor` builds its own `WebAssembly.Instance` with its own linear memory, so
//! two of them share nothing. We only ever run this over our own microphone, never over the
//! peers', so in practice there is exactly one.
//!
//! ## Scale
//!
//! RNNoise wants samples in i16 range (±32768), not the ±1 the Web Audio API deals in. That
//! conversion is left to the caller on purpose: it is a multiply the worklet is already walking
//! the buffer to do, and doing it here would mean walking it twice.

use nnnoiseless::DenoiseState;

/// Samples per call. RNNoise's frame, and not negotiable — the model's recurrence is defined in
/// terms of it. The worklet is handed 128 samples at a time and has to buffer up to this.
pub const FRAME_SIZE: usize = DenoiseState::FRAME_SIZE;

static mut INPUT: [f32; FRAME_SIZE] = [0.0; FRAME_SIZE];
static mut OUTPUT: [f32; FRAME_SIZE] = [0.0; FRAME_SIZE];
static mut STATE: Option<Box<DenoiseState<'static>>> = None;

/// Build the denoiser. Called once from the processor's constructor — on the main thread's
/// timeline, before any audio is flowing, which is the only place the allocation may happen.
///
/// Idempotent: calling it twice keeps the first state rather than dropping a denoiser whose
/// recurrent memory is mid-sentence.
#[no_mangle]
pub extern "C" fn rnnoise_init() {
    unsafe {
        let state = &mut *core::ptr::addr_of_mut!(STATE);
        if state.is_none() {
            *state = Some(DenoiseState::new());
        }
    }
}

/// Where the worklet writes its {@link FRAME_SIZE} input samples, in i16 scale.
#[no_mangle]
pub extern "C" fn rnnoise_input_ptr() -> *mut f32 {
    core::ptr::addr_of_mut!(INPUT) as *mut f32
}

/// Where the worklet reads the denoised frame from, in the same scale it wrote.
#[no_mangle]
pub extern "C" fn rnnoise_output_ptr() -> *mut f32 {
    core::ptr::addr_of_mut!(OUTPUT) as *mut f32
}

/// The frame size, asked for rather than hard-coded on the JS side. One definition of 480, here,
/// so a future model with a different frame can't disagree with the ring buffer feeding it.
#[no_mangle]
pub extern "C" fn rnnoise_frame_size() -> usize {
    FRAME_SIZE
}

/// Denoise the frame currently in `INPUT` into `OUTPUT`.
///
/// Returns the model's own voice-activity probability, 0…1. That is a genuinely better speech
/// detector than the envelope threshold our gate uses — it is the network's opinion, formed on
/// the same features it denoises with — and it is returned so the chain can eventually gate on
/// it instead of on level. Ignoring it is fine; it costs nothing to hand back.
///
/// Returns -1 if `rnnoise_init` was never called, so the worklet can tell "silent because the
/// model said so" from "silent because it was never built" rather than shipping a dead mic.
#[no_mangle]
pub extern "C" fn rnnoise_process() -> f32 {
    unsafe {
        let Some(state) = (&mut *core::ptr::addr_of_mut!(STATE)).as_mut() else {
            return -1.0;
        };

        let input = &*core::ptr::addr_of!(INPUT);
        let output = &mut *core::ptr::addr_of_mut!(OUTPUT);

        state.process_frame(output, input)
    }
}
