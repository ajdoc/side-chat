//! One match: the lobby that fills it, the tick loop that runs it, and the per-player snapshots
//! that leave it.
//!
//! Deliberately free of any networking. A `Room` is fed commands and asked for snapshots; it has
//! never heard of a WebSocket. That is what lets the whole of the match lifecycle — filling,
//! starting, ending, a player dropping — be tested without opening a port, and what would let
//! this same type run inside a Cloudflare Durable Object instead of a socket server if the
//! hosting question lands there. See MOBA.md.

use moba_proto::{NetTarget, ServerMessage, SlotId, SNAPSHOT_HZ, TICK_HZ};
use moba_sim::ability::{heroes, Target};
use moba_sim::entity::{EntityId, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::item::ItemId;
use moba_sim::map::Map;
use moba_sim::sim::{Command, Event, MatchConfig, Sim};

/// Which hero a seat picked. Only two exist so far; the rest of MOBA.md's roster slots in here.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum HeroChoice {
    Ironclad,
    Emberwitch,
    Jukebox,
    Ghostuser,
    Overclock,
    Relay,
}

impl HeroChoice {
    pub const ALL: [HeroChoice; 6] = [
        HeroChoice::Ironclad,
        HeroChoice::Emberwitch,
        HeroChoice::Jukebox,
        HeroChoice::Ghostuser,
        HeroChoice::Overclock,
        HeroChoice::Relay,
    ];

    /// Look up a hero by the id PHP uses.
    ///
    /// The ids are the same strings in both languages — see `App\Support\Moba\Heroes` — and
    /// this is the only place that mapping is spent. An unknown id means the two rosters have
    /// drifted, which a PHP test also checks for, so the fallback here is a safety net rather
    /// than the guard.
    pub fn from_id(id: &str) -> Option<HeroChoice> {
        Some(match id {
            "ironclad" => HeroChoice::Ironclad,
            "emberwitch" => HeroChoice::Emberwitch,
            "jukebox" => HeroChoice::Jukebox,
            "ghostuser" => HeroChoice::Ghostuser,
            "overclock" => HeroChoice::Overclock,
            "relay" => HeroChoice::Relay,
            _ => return None,
        })
    }

    /// The hero for a seat, when nobody has picked.
    ///
    /// Rotating through the roster rather than defaulting everyone to Ironclad, so a test match
    /// exercises more than one hero without anyone choosing.
    pub fn for_slot(slot: SlotId) -> HeroChoice {
        HeroChoice::ALL[slot as usize % HeroChoice::ALL.len()]
    }
}

impl HeroChoice {
    fn spawn(self, sim: &mut Sim, team: Team) -> EntityId {
        match self {
            HeroChoice::Ironclad => sim.spawn_named_hero(team, heroes::ironclad()),
            HeroChoice::Emberwitch => sim.spawn_named_hero(team, heroes::emberwitch()),
            HeroChoice::Jukebox => sim.spawn_named_hero(team, heroes::jukebox()),
            HeroChoice::Ghostuser => sim.spawn_named_hero(team, heroes::ghostuser()),
            HeroChoice::Overclock => sim.spawn_named_hero(team, heroes::overclock()),
            HeroChoice::Relay => sim.spawn_named_hero(team, heroes::relay()),
        }
    }
}

/// One seat in the match.
pub struct Seat {
    pub slot: SlotId,
    pub team: Team,
    pub hero: Option<EntityId>,
    pub choice: HeroChoice,
    /// A seat whose socket has gone away. The hero stays on the map — walking away from a MOBA
    /// should not delete your team's carry — and the seat can be reclaimed by a reconnect.
    pub connected: bool,
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Phase {
    /// Waiting for both teams to fill.
    Filling,
    Running,
    Over,
}

pub struct Room {
    pub sim: Sim,
    pub seats: Vec<Seat>,
    pub phase: Phase,
    config: MatchConfig,
    /// Fractional accumulator for the snapshot clock.
    ///
    /// **Not a tick countdown.** `TICK_HZ / SNAPSHOT_HZ` is 30/20, which in integer arithmetic
    /// is 1 — so a countdown fires every tick and the snapshot rate silently becomes the tick
    /// rate. Accumulating `SNAPSHOT_HZ` per tick and subtracting `TICK_HZ` on each fire gives
    /// the real 2-in-3 cadence without floating point.
    snapshot_accumulator: u32,
    /// Events accumulated since the last snapshot. Snapshots are sent at 20Hz over a 30Hz tick,
    /// so an event produced between snapshots must be held rather than dropped — otherwise a
    /// third of the game's hit effects never reach a client.
    pending: Vec<Event>,
}

impl Room {
    /// Start over with an empty room.
    ///
    /// ## Why a long-lived process needs this
    ///
    /// The design is one process per match — see MOBA.md — and under that rule a finished room
    /// is simply the end of the program. But a *development* server is started once and played
    /// against all afternoon, and without a reset the second match reconnects into the first
    /// one: a room in `Over` no longer ticks, so the hero does not move, no snapshots arrive,
    /// and the map never redraws. It looks like three separate bugs and is one.
    ///
    /// Recreating the sim rather than clearing it, because "reset" that misses one field is how
    /// a second match inherits the first one's dead towers.
    pub fn reset(&mut self) {
        *self = Room::new(self.config);
    }

    pub fn new(config: MatchConfig) -> Room {
        Room {
            sim: Sim::new(Map::three_lane(), config),
            seats: Vec::new(),
            phase: Phase::Filling,
            config,
            snapshot_accumulator: 0,
            pending: Vec::new(),
        }
    }

    /// Seats needed before the match starts.
    pub fn capacity(&self) -> usize {
        self.config.team_size as usize * 2
    }

    pub fn is_full(&self) -> bool {
        self.seats.len() >= self.capacity()
    }

    /// Seat a player into the exact slot a signed ticket named.
    ///
    /// **The ticketed path**, and the one a real deployment uses. Matchmaking already decided
    /// who plays which hero on which side; the server's job is to honour that, not to re-derive
    /// it. Arrival order is irrelevant — the tenth player to connect may hold slot 0.
    ///
    /// Returns `None` if the slot is out of range for this match size or is already occupied by
    /// a live connection. A *disconnected* occupant is reclaimed, because that is a reconnect.
    pub fn claim(&mut self, slot: SlotId, choice: HeroChoice) -> Option<SlotId> {
        if (slot as usize) >= self.capacity() {
            return None;
        }

        if let Some(seat) = self.seats.iter_mut().find(|s| s.slot == slot) {
            if seat.connected {
                return None;
            }
            seat.connected = true;
            return Some(slot);
        }

        // Even slots Blue, odd slots Red — the same rule PHP seats by, so neither side has to
        // know the other's.
        let team = if slot.is_multiple_of(2) {
            Team::Blue
        } else {
            Team::Red
        };
        self.seats.push(Seat {
            slot,
            team,
            hero: None,
            choice,
            connected: true,
        });
        Some(slot)
    }

    /// Everyone's stats, for the result report.
    ///
    /// Keyed by slot rather than by user, because the game server has never been told who is
    /// behind a seat — a ticket names a user id but the room deliberately does not keep it. Slot
    /// is the handle both halves already agree on.
    pub fn scoreboard(&self) -> Vec<(SlotId, moba_sim::score::HeroStats)> {
        self.seats
            .iter()
            .filter_map(|seat| seat.hero.map(|hero| (seat.slot, self.sim.scores.get(hero))))
            .collect()
    }

    /// Seat a player, alternating sides so that a partly-filled room is always as even as it can
    /// be — which matters when the room is two people and one leaves.
    pub fn join(&mut self, choice: HeroChoice) -> Option<SlotId> {
        if self.is_full() || self.phase != Phase::Filling {
            return None;
        }
        let slot = self.seats.len() as SlotId;
        let team = if slot.is_multiple_of(2) {
            Team::Blue
        } else {
            Team::Red
        };
        self.seats.push(Seat {
            slot,
            team,
            hero: None,
            choice,
            connected: true,
        });
        Some(slot)
    }

    pub fn seat(&self, slot: SlotId) -> Option<&Seat> {
        self.seats.iter().find(|s| s.slot == slot)
    }

    /// Mark a seat's player as gone, without removing them from the match.
    ///
    /// The hero stays on the map. Walking away from a MOBA — or, far more often, refreshing the
    /// tab — must not delete your team's carry, and the four other people still playing are
    /// entitled to the body even if nobody is driving it.
    pub fn disconnect(&mut self, slot: SlotId) {
        if let Some(seat) = self.seats.iter_mut().find(|s| s.slot == slot) {
            seat.connected = false;
        }
    }

    /// Hand out the lowest-numbered seat whose player has dropped, if there is one.
    ///
    /// **This is what makes a refresh survivable.** Without it, `connected` was a flag nothing
    /// read: a dropped player left a ghost holding the seat, the room stayed permanently full,
    /// and the next connection was refused — which the browser reports as "WebSocket is already
    /// in CLOSING or CLOSED state" on the next click, a message that names the symptom and gives
    /// no hint of the cause.
    ///
    /// Lowest-numbered rather than longest-absent: with several seats free it makes no
    /// difference who gets which, but it must be the *same* choice on every machine given the
    /// same history, and slot order is the one ordering that is already canonical.
    pub fn reclaim(&mut self) -> Option<SlotId> {
        let seat = self
            .seats
            .iter_mut()
            .filter(|s| !s.connected)
            .min_by_key(|s| s.slot)?;
        seat.connected = true;
        Some(seat.slot)
    }

    /// Whether anyone is still holding this match open.
    ///
    /// A room nobody is connected to is one a supervisor can reap — relevant the moment there is
    /// more than one match per process.
    pub fn is_abandoned(&self) -> bool {
        !self.seats.is_empty() && self.seats.iter().all(|s| !s.connected)
    }

    /// Put every seat's hero on the map and begin.
    ///
    /// Refuses a room that is not full. The socket layer already checks, but a match that can
    /// start half-empty is a 5v5 that quietly becomes a 3v2 the moment anything races, and the
    /// rule belongs with the thing it is a rule about.
    pub fn start(&mut self) {
        if self.phase != Phase::Filling || !self.is_full() {
            return;
        }
        // Ticketed seats arrive in whatever order their players connect, so sort before
        // spawning: two servers given the same roster must build the same arena in the same
        // order, since entity ids are part of the wire format.
        self.seats.sort_by_key(|seat| seat.slot);
        // Spawned in slot order so that two servers given the same roster build the same arena
        // in the same order — the ids are part of the wire format, and an arena that differed
        // by seating order would make a recorded match unreplayable.
        for index in 0..self.seats.len() {
            let (team, choice) = (self.seats[index].team, self.seats[index].choice);
            let hero = choice.spawn(&mut self.sim, team);
            self.seats[index].hero = Some(hero);
        }
        self.phase = Phase::Running;
    }

    /// Advance one tick, and report whether a snapshot is due.
    pub fn tick(&mut self, commands: &[Command]) -> bool {
        if self.phase != Phase::Running {
            return false;
        }
        self.pending.extend(self.sim.step(commands));

        if self.sim.winner().is_some() {
            self.phase = Phase::Over;
        }

        self.snapshot_accumulator += SNAPSHOT_HZ;
        if self.snapshot_accumulator >= TICK_HZ {
            self.snapshot_accumulator -= TICK_HZ;
            return true;
        }
        false
    }

    /// The snapshot for one seat, and clearing of the events it carried.
    ///
    /// Built per seat rather than per team because `own` — mana, gold, cooldowns — differs
    /// between two players on the same side.
    pub fn snapshot_for(&self, slot: SlotId) -> Option<moba_proto::Snapshot> {
        let seat = self.seat(slot)?;
        Some(self.sim.snapshot(seat.team, seat.hero, &self.pending))
    }

    /// Drop the events that the snapshots just sent have carried.
    ///
    /// Separate from [`snapshot_for`] because every seat must see the same batch; clearing
    /// inside the per-seat call would give the first player the events and everyone else none.
    pub fn clear_pending(&mut self) {
        self.pending.clear();
    }

    pub fn lobby_message(&self) -> ServerMessage {
        ServerMessage::Lobby {
            present: self.seats.len() as u8,
            needed: self.capacity() as u8,
        }
    }

    /// Translate one client message into a sim command for `slot`.
    ///
    /// **The only place a client's words become the sim's.** Every command is rewritten to name
    /// the sender's own hero, so a message claiming to move somebody else's hero cannot: the
    /// entity id is supplied by the server from the seat, never read from the wire.
    pub fn command_from(&self, slot: SlotId, message: ClientIntent) -> Option<Command> {
        let hero = self.seat(slot)?.hero?;
        Some(match message {
            ClientIntent::MoveTo { x, y } => Command::MoveTo {
                hero,
                pos: Vec2::new(Fx::from_raw(x), Fx::from_raw(y)),
            },
            ClientIntent::Attack { target } => Command::Attack {
                hero,
                target: self.resolve(target)?,
            },
            ClientIntent::Stop => Command::Stop { hero },
            ClientIntent::Cast {
                slot: ability_slot,
                target,
            } => Command::CastAbility {
                hero,
                slot: ability_slot as usize,
                target: self.resolve_target(target)?,
            },
            ClientIntent::Buy { item } => Command::BuyItem {
                hero,
                item: ItemId(item),
            },
        })
    }

    /// Find the entity a client named.
    ///
    /// Returns `None` for anything that is not on the map, which silently drops the order. That
    /// is correct rather than lenient: an id the server cannot resolve is either a client acting
    /// on a stale snapshot — normal, and not worth an error — or a client inventing ids, which
    /// deserves nothing at all.
    fn resolve(&self, id: moba_proto::NetId) -> Option<EntityId> {
        self.sim
            .entities
            .iter()
            .map(|(id, _)| id)
            .find(|e| e.to_net() == id)
    }

    fn resolve_target(&self, target: NetTarget) -> Option<Target> {
        Some(match target {
            NetTarget::None => Target::None,
            NetTarget::Unit(id) => Target::Unit(self.resolve(id)?),
            NetTarget::Point { x, y } => Target::Point(Vec2::new(Fx::from_raw(x), Fx::from_raw(y))),
        })
    }
}

/// A client message stripped of the parts the server supplies itself.
///
/// The wire type carries no entity id for the *actor*, and this mirrors that: there is no field
/// here into which a client could put someone else's hero.
#[derive(Clone, Copy, Debug)]
pub enum ClientIntent {
    MoveTo { x: i32, y: i32 },
    Attack { target: moba_proto::NetId },
    Cast { slot: u8, target: NetTarget },
    Buy { item: u16 },
    Stop,
}

impl ClientIntent {
    pub fn from_message(message: &moba_proto::ClientMessage) -> Option<ClientIntent> {
        use moba_proto::ClientMessage as M;
        Some(match *message {
            M::MoveTo { x, y } => ClientIntent::MoveTo { x, y },
            M::Attack { target } => ClientIntent::Attack { target },
            M::Cast { slot, target } => ClientIntent::Cast { slot, target },
            M::Buy { item } => ClientIntent::Buy { item },
            M::Stop => ClientIntent::Stop,
            M::Hello { .. } | M::Ping { .. } => return None,
        })
    }
}
