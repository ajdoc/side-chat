//! The scoreboard.
//!
//! Kept as a side table keyed by hero rather than as fields on the entity, for one reason: a
//! hero's stats have to survive its death, and `Entity` is a thing that dies. Heroes are not
//! despawned today — the reap phase leaves them for the respawn timer — but making the
//! scoreboard depend on that would be depending on a detail that could reasonably change.
//!
//! Everything here is derived from events the sim already produces. Nothing new is simulated to
//! keep score, which is what keeps a scoreboard from quietly becoming a second source of truth
//! about what happened.

use std::collections::BTreeMap;

use crate::entity::EntityId;
use crate::fixed::Fx;

/// How long after damaging someone you still get an assist for their death.
///
/// Ten seconds is the genre's usual figure and about right: long enough that a stun which set up
/// a kill counts, short enough that a poke on the way past a lane does not.
pub const ASSIST_WINDOW_TICKS: u32 = moba_proto::TICK_HZ * 10;

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct HeroStats {
    pub kills: u32,
    pub deaths: u32,
    pub assists: u32,
    /// Total gold *earned*, not gold in hand — spending it should not shrink your scoreboard.
    pub gold: u32,
    /// Damage dealt to enemy heroes only.
    ///
    /// Counting damage to creeps would make a farming carry look like a fighter and turn the
    /// number into a measure of last-hitting, which the gold column already is.
    pub damage: u32,
}

/// Everyone's stats, keyed by hero entity.
///
/// `BTreeMap` rather than `HashMap`, like everywhere else in this crate: iteration order reaches
/// the wire when the scoreboard is reported, and hash order is not specified.
#[derive(Default)]
pub struct Scoreboard {
    rows: BTreeMap<EntityId, HeroStats>,
}

impl Scoreboard {
    pub fn new() -> Scoreboard {
        Scoreboard::default()
    }

    pub fn get(&self, hero: EntityId) -> HeroStats {
        self.rows.get(&hero).copied().unwrap_or_default()
    }

    pub fn entry(&mut self, hero: EntityId) -> &mut HeroStats {
        self.rows.entry(hero).or_default()
    }

    pub fn iter(&self) -> impl Iterator<Item = (&EntityId, &HeroStats)> {
        self.rows.iter()
    }

    pub fn record_damage(&mut self, source: EntityId, amount: Fx) {
        let whole = amount.floor_int().max(0) as u32;
        self.entry(source).damage += whole;
    }

    pub fn record_gold(&mut self, hero: EntityId, amount: Fx) {
        self.entry(hero).gold += amount.floor_int().max(0) as u32;
    }

    /// Credit a kill: one killer, and everyone else who helped inside the window.
    pub fn record_kill(
        &mut self,
        victim: EntityId,
        killer: Option<EntityId>,
        assists: &[EntityId],
    ) {
        self.entry(victim).deaths += 1;
        if let Some(killer) = killer {
            self.entry(killer).kills += 1;
        }
        for assist in assists {
            // The killer does not also get an assist for their own kill.
            if Some(*assist) != killer {
                self.entry(*assist).assists += 1;
            }
        }
    }
}
