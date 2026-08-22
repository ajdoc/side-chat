//! The browser client.
//!
//! Split so that the parts with rules are testable without a browser:
//!
//! - [`interp`] — the snapshot buffer and the ~100ms render delay. Where "does it feel smooth"
//!   is actually decided.
//! - [`input`] — clicks and keys becoming orders.
//! - [`camera`] — screen ↔ world, in one place.
//! - [`effects`] — the transient feedback that makes a fight legible: damage numbers, hit
//!   lines, cast rings, deaths.
//! - [`spells`] — what each ability *looks* like. Cosmetic only, and the reason a player can
//!   tell Cinder from Pyre.
//! - [`hud`] — the ability bar. A readout on desktop and the *input* on a phone, which is why
//!   its geometry is computed once here rather than twice.
//! - [`web`] — the wasm shim: a canvas, a socket, and a frame loop. Mechanical, and compiled
//!   only for `wasm32`, which is why `cargo test` on the host exercises everything above it.

pub mod camera;
pub mod effects;
pub mod hud;
pub mod input;
pub mod interp;
pub mod spells;

#[cfg(target_arch = "wasm32")]
pub mod web;
