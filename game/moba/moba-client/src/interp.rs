//! The snapshot buffer, and rendering the past.
//!
//! ## Why the client draws ~100ms behind
//!
//! Snapshots arrive at 20Hz — one every 50ms — over a network that does not deliver them evenly.
//! Drawing the newest one the instant it lands means every entity teleports 50ms of movement,
//! then waits, then teleports again, and a packet arriving late means a visible stall.
//!
//! So the client renders at a time *between* two snapshots it already holds, deliberately behind
//! the newest. Movement becomes continuous, and a late packet is invisible as long as it arrives
//! within the delay. The cost is that everything on screen is fractionally stale — which for a
//! MOBA is free, because the atom of input is "go there" and "cast that", not a one-frame parry.
//!
//! ## Floats are fine here
//!
//! The sim is fixed-point because two machines must agree exactly. Nothing in this module feeds
//! back into the sim — it produces pixels — so `f32` is the right type and its rounding cannot
//! desync anything. That distinction is worth stating plainly, because "we use fixed-point" read
//! as a blanket rule would make the renderer needlessly awkward.

use moba_proto::{NetEntity, NetId, NetKind, NetTeam, Snapshot, SNAPSHOT_HZ, TICK_HZ};

/// How far behind the newest snapshot to render, in ticks.
///
/// Three ticks at 30Hz is 100ms — two snapshot intervals plus a little. Less than two intervals
/// and an ordinary jittery packet arrives after the time it was needed, which is the stall this
/// exists to prevent.
pub const INTERP_DELAY_TICKS: f32 = 3.0;

/// How many snapshots to keep. Enough to cover the delay several times over, so a burst of late
/// packets still has something to interpolate between.
const BUFFER_LEN: usize = 16;

/// An entity as the renderer wants it: floats, in world units.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct RenderEntity {
    pub id: NetId,
    pub kind: NetKind,
    pub team: NetTeam,
    pub x: f32,
    pub y: f32,
    pub hp_fraction: f32,
    /// Heroes only; zero for everything else.
    pub level: u32,
    /// Which of a kind this is — a tower's tier, or whether a creep is ranged. Cosmetic.
    pub variant: u8,
    /// Where it is pointing, in world units. Not normalised; the renderer only needs the angle.
    pub facing_x: f32,
    pub facing_y: f32,
    /// How far this sees, in world units. Drives the fog, for your own team's entities.
    pub vision: f32,
}

/// Convert a `Q16.16` raw integer to world units.
#[inline]
pub fn from_fixed(raw: i32) -> f32 {
    raw as f32 / 65536.0
}

/// Holds recent snapshots and answers "what did the world look like at time T".
pub struct SnapshotBuffer {
    /// Ordered oldest-first. A `Vec` rather than a ring because it holds sixteen items and the
    /// clarity is worth more than the two pointer updates a ring would save.
    snapshots: Vec<Snapshot>,
    /// The client's estimate of the current server tick, in fractional ticks.
    clock: f32,
    started: bool,
}

impl Default for SnapshotBuffer {
    fn default() -> SnapshotBuffer {
        SnapshotBuffer::new()
    }
}

impl SnapshotBuffer {
    pub fn new() -> SnapshotBuffer {
        SnapshotBuffer {
            snapshots: Vec::new(),
            clock: 0.0,
            started: false,
        }
    }

    /// Accept a snapshot from the server.
    ///
    /// Out-of-order and duplicate arrivals are dropped rather than inserted. A snapshot older
    /// than one already held describes a past that has been superseded, and splicing it in would
    /// make entities jump backwards for one frame — the exact artefact the buffer exists to
    /// remove.
    pub fn push(&mut self, snapshot: Snapshot) {
        if let Some(newest) = self.snapshots.last() {
            if snapshot.tick <= newest.tick {
                return;
            }
        }
        if !self.started {
            self.clock = snapshot.tick as f32;
            self.started = true;
        } else {
            // **The correction happens on arrival, not per frame.**
            //
            // An earlier cut nudged the clock 10% toward the newest tick on every rendered
            // frame. Because the target only moves when a snapshot lands, that is a controller
            // correcting sixty times a second against something that changes twenty times a
            // second, and it settles at a fixed *offset* rather than at zero — measured at 4.5
            // ticks ahead of the server. That silently consumed the whole 3-tick interpolation
            // budget and left the client extrapolating off the end of its own buffer, which is
            // the stutter this module exists to prevent.
            //
            // Correcting once per arrival makes the clock and the target advance at the same
            // rate, so the error stays where it started — near zero — instead of accumulating.
            let error = snapshot.tick as f32 - self.clock;
            if error.abs() > TICK_HZ as f32 * 2.0 {
                // A backgrounded tab or a reconnect. Easing a thousand ticks at 20% a step would
                // take a minute of visibly wrong time; snapping is one bad frame.
                self.clock = snapshot.tick as f32;
            } else {
                // Partial, so ordinary packet jitter is absorbed over a few snapshots rather
                // than moving the render time by the full amount of every wobble.
                self.clock += error * 0.2;
            }
        }
        self.snapshots.push(snapshot);
        if self.snapshots.len() > BUFFER_LEN {
            self.snapshots.remove(0);
        }
    }

    /// Advance the clock by a frame.
    ///
    /// Pure free-running time. All correction toward the server happens in [`push`], on arrival —
    /// see the note there for why doing it here instead produces a permanent offset.
    ///
    /// If the server goes quiet the clock keeps running, which is deliberate: time has not
    /// stopped, only the data has, and [`staleness`] is what says so.
    pub fn advance(&mut self, dt_seconds: f32) {
        if !self.started {
            return;
        }
        self.clock += dt_seconds * TICK_HZ as f32;
    }

    /// The time being rendered — the clock, less the interpolation delay.
    pub fn render_tick(&self) -> f32 {
        self.clock - INTERP_DELAY_TICKS
    }

    /// The newest snapshot's own-hero block, which is not interpolated.
    ///
    /// Gold, mana and cooldowns are numbers on a UI, not positions in a world: showing them
    /// 100ms late would make a button look available for three frames after it was pressed.
    pub fn own(&self) -> Option<&moba_proto::NetSelf> {
        self.snapshots.last()?.own.as_ref()
    }

    pub fn latest_tick(&self) -> Option<u32> {
        self.snapshots.last().map(|s| s.tick)
    }

    pub fn oldest_tick(&self) -> Option<u32> {
        self.snapshots.first().map(|s| s.tick)
    }

    /// Whether the time being rendered sits inside the data actually held.
    ///
    /// **The invariant the delay exists to buy.** Outside this range the client is guessing:
    /// past the newest snapshot it is extrapolating into a future the server has not sent, and
    /// before the oldest it is drawing a moment it has already thrown away. Either shows up as
    /// stutter, and neither is visible by inspecting one frame — which is why it is asserted
    /// continuously across a simulated stream rather than sampled at the end.
    pub fn is_interpolating(&self) -> bool {
        match (self.oldest_tick(), self.latest_tick()) {
            (Some(oldest), Some(latest)) => {
                let now = self.render_tick();
                now >= oldest as f32 && now <= latest as f32
            }
            _ => false,
        }
    }

    pub fn len(&self) -> usize {
        self.snapshots.len()
    }

    pub fn is_empty(&self) -> bool {
        self.snapshots.is_empty()
    }

    /// The world as it looked at [`render_tick`].
    ///
    /// Entities present in both bracketing snapshots are interpolated. An entity present in only
    /// one is drawn at that one's position without extrapolation: a creep that just spawned has
    /// no history to interpolate from, and a guess at where it is "going" would be a visible
    /// twitch on every spawn.
    pub fn sample(&self) -> Vec<RenderEntity> {
        let now = self.render_tick();
        let Some((before, after)) = self.bracket(now) else {
            // Not enough history yet — draw the newest thing we have rather than nothing.
            return self
                .snapshots
                .last()
                .map(|s| s.entities.iter().map(render_one).collect())
                .unwrap_or_default();
        };

        let span = (after.tick - before.tick) as f32;
        let t = if span <= 0.0 {
            0.0
        } else {
            ((now - before.tick as f32) / span).clamp(0.0, 1.0)
        };

        after
            .entities
            .iter()
            .map(|entity| {
                match before.entities.iter().find(|e| e.id == entity.id) {
                    Some(previous) => lerp_entity(previous, entity, t),
                    // New this snapshot: no history, so no extrapolation.
                    None => render_one(entity),
                }
            })
            .collect()
    }

    /// The two snapshots either side of `tick`.
    fn bracket(&self, tick: f32) -> Option<(&Snapshot, &Snapshot)> {
        if self.snapshots.len() < 2 {
            return None;
        }
        // Walk newest-first: the answer is almost always the last pair, so this is one comparison
        // in the common case.
        for pair in self.snapshots.windows(2).rev() {
            let (before, after) = (&pair[0], &pair[1]);
            if (before.tick as f32) <= tick && tick <= after.tick as f32 {
                return Some((before, after));
            }
        }
        // Behind everything held (a long stall) — pin to the oldest pair rather than drawing
        // nothing, so the world freezes instead of disappearing.
        let first = self.snapshots.first()?;
        let second = self.snapshots.get(1)?;
        if tick < first.tick as f32 {
            return Some((first, second));
        }
        // Ahead of everything held: the newest pair, clamped by `t` above.
        let len = self.snapshots.len();
        Some((&self.snapshots[len - 2], &self.snapshots[len - 1]))
    }

    /// How stale the newest snapshot is, in seconds. For a connection-quality indicator.
    pub fn staleness(&self) -> f32 {
        match self.snapshots.last() {
            Some(newest) => (self.clock - newest.tick as f32) / TICK_HZ as f32,
            None => 0.0,
        }
    }

    /// Whether the buffer is healthy enough to be rendering smoothly.
    pub fn is_healthy(&self) -> bool {
        self.snapshots.len() >= 2 && self.staleness().abs() < (2.0 / SNAPSHOT_HZ as f32) + 0.05
    }
}

fn render_one(entity: &NetEntity) -> RenderEntity {
    RenderEntity {
        id: entity.id,
        kind: entity.kind,
        team: entity.team,
        x: from_fixed(entity.x),
        y: from_fixed(entity.y),
        hp_fraction: hp_fraction(entity),
        level: entity.level,
        variant: entity.variant,
        facing_x: from_fixed(entity.facing_x),
        facing_y: from_fixed(entity.facing_y),
        vision: from_fixed(entity.vision),
    }
}

fn hp_fraction(entity: &NetEntity) -> f32 {
    if entity.max_hp <= 0 {
        return 0.0;
    }
    (entity.hp as f32 / entity.max_hp as f32).clamp(0.0, 1.0)
}

fn lerp_entity(before: &NetEntity, after: &NetEntity, t: f32) -> RenderEntity {
    let lerp = |a: f32, b: f32| a + (b - a) * t;
    RenderEntity {
        id: after.id,
        kind: after.kind,
        team: after.team,
        x: lerp(from_fixed(before.x), from_fixed(after.x)),
        y: lerp(from_fixed(before.y), from_fixed(after.y)),
        // Health is interpolated too, so a health bar slides rather than stepping.
        hp_fraction: lerp(hp_fraction(before), hp_fraction(after)),
        // Not interpolated: a level is a whole number and half a level means nothing.
        level: after.level,
        variant: after.variant,
        // Nor is facing. Interpolating a direction linearly is wrong at the wrap — a unit
        // turning past due north would swing the long way round through every other heading —
        // and the correct version is a slerp for something nobody can see at forty pixels.
        facing_x: from_fixed(after.facing_x),
        facing_y: from_fixed(after.facing_y),
        vision: from_fixed(after.vision),
    }
}
