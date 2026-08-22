//! The MOBA simulation — the whole game, and nothing else.
//!
//! Pure and deterministic by construction, because the same crate runs in three places: the
//! authoritative server, the browser client as wasm, and a headless test harness that replays a
//! command log and asserts an identical end state. See MOBA.md for the design this implements.
//!
//! The rules that keep it that way, all of which are load-bearing:
//!
//! - **No `std::time`.** Time is the tick count, which is state.
//! - **No ambient randomness.** The generator lives *in* the state and is seeded per match.
//! - **No iteration over a `HashMap`** in anything that can affect the outcome — hash order is
//!   not specified and differs between builds.
//! - **No floating point.** See [`fixed`].

pub mod ability;
pub mod cast;
pub mod damage;
pub mod economy;
pub mod entity;
pub mod fixed;
pub mod item;
pub mod level;
pub mod map;
pub mod net;
pub mod score;
pub mod sim;
