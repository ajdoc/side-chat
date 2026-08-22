//! The authoritative match server.
//!
//! Two layers, kept strictly apart:
//!
//! - [`room`] is the match — filling, ticking, snapshotting — and has never heard of a socket.
//! - [`server`] is the socket, and knows nothing about the game beyond forwarding bytes.
//!
//! The split is not tidiness. MOBA.md leaves the hosting question open on purpose (a VPS, a
//! Cloudflare Durable Object, or one player hosting over WebRTC), and every one of those swaps
//! out [`server`] while leaving [`room`] untouched. It is also what makes the match lifecycle
//! testable without opening a port.

pub mod report;
pub mod room;
pub mod server;
pub mod ticket;
