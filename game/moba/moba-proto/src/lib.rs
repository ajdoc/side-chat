//! Wire types shared by the sim, the server and the client.
//!
//! Deliberately dependency-light: this crate is compiled into a wasm bundle that ships to every
//! player, so anything added here is paid for on every page load.
//!
//! ## Why the wire types are not the sim types
//!
//! It would be less code to derive `Serialize` on `Entity` and send that. It would also mean
//! every internal field — cooldown counters, a dash's remaining distance, `last_damage_from` —
//! travelling to every client, and **a fog of war that leaks**. The client cannot be trusted
//! with what it is not allowed to see, so what it is allowed to see gets its own vocabulary,
//! built per team, and there is no path by which a private field accidentally acquires a
//! `pub` and ends up on the network.

use serde::{Deserialize, Serialize};

/// Bumped whenever the meaning of anything on the wire changes.
///
/// The server rejects a client that does not match. A stale wasm bundle in somebody's cache is
/// otherwise indistinguishable from a desync bug, and one of those is an evening of debugging
/// while the other is a hard refresh.
pub const PROTOCOL_VERSION: u32 = 8;

/// Ticks per second. Fixed, and shared by every crate, because a sim step is only meaningful
/// against a known step duration.
pub const TICK_HZ: u32 = 30;

/// Snapshots per second. Lower than the tick rate on purpose: the client interpolates between
/// snapshots anyway, so sending every tick would be triple the bandwidth for no visible gain.
pub const SNAPSHOT_HZ: u32 = 20;

/// A player's seat in a match. Stable for the match's lifetime, and the thing a reconnecting
/// player is matched back to.
pub type SlotId = u8;

/// An entity's identity on the wire. The sim's generational id, flattened.
pub type NetId = u64;

#[derive(Clone, Copy, PartialEq, Eq, Debug, Serialize, Deserialize)]
pub enum NetTeam {
    Blue,
    Red,
    Neutral,
}

#[derive(Clone, Copy, PartialEq, Eq, Debug, Serialize, Deserialize)]
pub enum NetKind {
    Hero,
    Creep,
    Tower,
    Base,
    Zone,
    /// An autoattack in flight. Drawn as a small bolt, and the reason a ranged hero looks
    /// ranged.
    Projectile,
}

/// One entity as one client is allowed to see it.
///
/// Positions are the raw `Q16.16` integers rather than floats: the client's renderer converts
/// once, and nothing in transit can round differently on two machines.
#[derive(Clone, Copy, Debug, Serialize, Deserialize)]
pub struct NetEntity {
    pub id: NetId,
    pub kind: NetKind,
    pub team: NetTeam,
    pub x: i32,
    pub y: i32,
    pub hp: i32,
    pub max_hp: i32,
    /// Heroes only; zero for everything else. Drawn beside the health bar, because knowing an
    /// enemy is four levels up on you is most of what decides whether to fight them.
    pub level: u32,
    /// Facing, for the renderer. Derived, not authoritative.
    pub facing_x: i32,
    pub facing_y: i32,
}

/// What only the owning player sees about their own hero.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct NetSelf {
    pub id: NetId,
    pub mana: i32,
    pub max_mana: i32,
    pub gold: i32,
    /// Remaining cooldown per slot, in ticks. Ten entries: four abilities then six items.
    pub cooldowns: Vec<u32>,
    /// The ability in each slot, parallel to `cooldowns`. Sent so a button can be labelled with
    /// what it actually casts — a bar reading Q W E R teaches nothing about a hero.
    pub abilities: Vec<u16>,
    /// How each slot is aimed, parallel to `abilities`.
    pub targeting: Vec<NetTargeting>,
    pub items: Vec<u16>,
    pub level: u32,
    /// Experience into the current level, and what the next one costs. Two numbers rather than a
    /// fraction, so the client can draw a bar *and* show the figures without inventing either.
    pub xp_into_level: u32,
    pub xp_for_next: u32,
    /// Your attack range, in `Q16.16` world units. Drawn as a ring under your own hero — a
    /// player who cannot see their reach cannot tell why an attack order walked them forward.
    pub attack_range: i32,
    /// Ticks until this player respawns; zero when alive.
    ///
    /// On `NetSelf` rather than on the entity, because a dead hero is not *in* the snapshot at
    /// all — it is not on the map — and the one person who still needs to know about it is the
    /// one waiting to come back.
    pub respawn_in: u32,
}

/// The world at one instant, filtered for one team.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Snapshot {
    pub tick: u32,
    pub entities: Vec<NetEntity>,
    pub own: Option<NetSelf>,
    /// Events since the previous snapshot, for effects and sound.
    pub events: Vec<NetEvent>,
}

/// Why a cast did not happen.
///
/// Sent to the caster and nobody else. A refusal that produces no feedback is the worst kind of
/// bug to have in a game: the player presses a key, nothing happens, and there is no way to tell
/// a broken ability from an ability on cooldown from an ability out of range. All three feel
/// identical, and all three feel like the game is broken.
#[derive(Clone, Copy, PartialEq, Eq, Debug, Serialize, Deserialize)]
pub enum NetRefusal {
    OnCooldown,
    NotEnoughMana,
    Silenced,
    Stunned,
    OutOfRange,
    BadTarget,
    /// An ultimate before level six.
    NotLearned,
    AlreadyCasting,
    Unknown,
}

/// How an ability is aimed, as the client needs to know it.
///
/// Sent rather than mirrored client-side, because it is a *rule* and not a decoration: getting
/// it wrong means a self-cast that demands a pointless click, or a skillshot that fires at the
/// caster's feet.
#[derive(Clone, Copy, PartialEq, Eq, Debug, Serialize, Deserialize)]
pub enum NetTargeting {
    /// Fires the moment the key is pressed. Nothing to aim.
    SelfCast,
    /// Wants a unit under the cursor.
    Unit,
    /// Wants a place.
    Point,
    /// Wants a direction.
    Vector,
    /// Empty slot.
    None,
}

#[derive(Clone, Copy, Debug, Serialize, Deserialize)]
pub enum NetEvent {
    /// `source` is what makes a hit *legible*: without it a client can show that something took
    /// damage but not what hit it, so a tower shooting you and a creep shooting you look
    /// identical — which was the first thing anyone noticed playing it.
    Damaged {
        source: Option<NetId>,
        target: NetId,
        amount: i32,
    },
    Died {
        entity: NetId,
    },
    StructureDestroyed {
        entity: NetId,
    },
    AbilityCast {
        entity: NetId,
        ability: u16,
        /// Where it was aimed, in `Q16.16` world units. A ground-targeted effect belongs on the
        /// ground it covers, not on whoever cast it.
        x: i32,
        y: i32,
    },
    CastInterrupted {
        entity: NetId,
    },
    GoldGained {
        amount: i32,
    },
    Denied,
    /// Your own cast was declined. Never sent about anyone else — what an enemy tried and failed
    /// to do is not information they should be giving away.
    CastRefused {
        slot: u8,
        reason: NetRefusal,
    },
    MatchEnded {
        winner: NetTeam,
    },
}

/// Where a player aimed.
#[derive(Clone, Copy, Debug, Serialize, Deserialize)]
pub enum NetTarget {
    None,
    Unit(NetId),
    Point { x: i32, y: i32 },
}

/// What a client may say.
///
/// Orders, never positions. A client that could send its own position could send any position,
/// and the whole authoritative model would be decoration.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub enum ClientMessage {
    /// First message on the socket. A mismatched `protocol` is refused immediately.
    Hello {
        protocol: u32,
        ticket: String,
    },
    MoveTo {
        x: i32,
        y: i32,
    },
    Attack {
        target: NetId,
    },
    Cast {
        slot: u8,
        target: NetTarget,
    },
    Buy {
        item: u16,
    },
    Stop,
    /// Round-trip probe. The server echoes the payload back untouched.
    Ping {
        nonce: u32,
    },
}

/// The map, sent once at the handshake.
///
/// Sent rather than compiled into the client for a reason that already bit once: the first
/// client drew a hardcoded diagonal line between two literal coordinates that *happened* to
/// match where the single lane was. It looked correct and was a coincidence — the moment the map
/// gained two more lanes it would still have drawn one stripe through the middle of them.
///
/// Static for a match, so it rides on `Welcome` rather than in every snapshot.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct NetMap {
    /// World units across. The map is square.
    pub size: i32,
    /// One polyline per lane, in world units, ordered from the Blue end.
    pub lanes: Vec<Vec<(i32, i32)>>,
    /// How many terrain cells span the map.
    pub cells_across: u16,
    /// Only the blocked cells, as `(cx, cy)`. A 64×64 grid is 4096 cells and most of a lane map
    /// is walls, but sending the sparse list still beats a bitmap once the JSON encoding is
    /// accounted for — and it is a single message per match either way.
    pub blocked: Vec<(u16, u16)>,
}

/// What a server may say.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub enum ServerMessage {
    /// Accepted. Everything the client needs before the first snapshot arrives.
    Welcome {
        protocol: u32,
        slot: SlotId,
        team: NetTeam,
        hero_id: NetId,
        team_size: u8,
        tick_hz: u32,
        map: NetMap,
    },
    /// Refused, with a reason a human can read.
    Rejected {
        reason: String,
    },
    /// Waiting for the lobby to fill.
    Lobby {
        present: u8,
        needed: u8,
    },
    Started {
        tick: u32,
    },
    Snapshot(Snapshot),
    Pong {
        nonce: u32,
    },
}
