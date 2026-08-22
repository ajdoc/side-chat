//! Things that happen and then stop happening.
//!
//! ## Why this exists
//!
//! The server already sends everything needed to make a fight legible — who cast what, who hit
//! whom for how much, who died. The first playable build drew none of it, and the result was a
//! game that was simulating correctly and showing almost nothing: you could not tell whether an
//! ability had fired, whether a creep died to your hit, or that the tower was the thing killing
//! you.
//!
//! An event is instantaneous and a player is not. Every one of these gets a lifetime measured in
//! tenths of a second, so a hit that happened between two frames is still on screen long enough
//! to be read.

use moba_proto::{NetEvent, NetId};

/// How long each kind of feedback stays up. Short enough not to clutter a teamfight, long enough
/// to survive a dropped frame.
const DAMAGE_NUMBER_SECONDS: f32 = 0.9;
const HIT_LINE_SECONDS: f32 = 0.15;
const CAST_RING_SECONDS: f32 = 0.45;
/// Longer than the ring: a name has to be *read*, not merely noticed.
const CAST_NAME_SECONDS: f32 = 1.1;
const DEATH_MARK_SECONDS: f32 = 0.6;

#[derive(Clone, Copy, Debug, PartialEq)]
pub enum EffectKind {
    /// A number floating up from whatever was hit.
    DamageNumber {
        amount: i32,
        on_self: bool,
    },
    /// A line from attacker to victim, so an autoattack is visible at all — and so a tower
    /// shooting you is distinguishable from a creep shooting you.
    HitLine {
        from: NetId,
        to: NetId,
    },
    /// An expanding ring on whoever cast something.
    CastRing {
        ability: u16,
    },
    /// The ability's name, floating above the caster.
    ///
    /// The single highest-value piece of feedback in the game right now: a player who cannot see
    /// which key did what cannot learn a hero, and a name on screen teaches it in one cast.
    CastName {
        ability: u16,
    },
    Death,
}

#[derive(Clone, Copy, Debug)]
pub struct Effect {
    pub kind: EffectKind,
    /// Where the effect belongs in the world, when that is not simply the anchor's position.
    ///
    /// A ground-targeted ability covers the ground it was aimed at; drawing it on the caster
    /// puts Cinder's blast several hundred units from the fire it started.
    pub at: Option<(f32, f32)>,
    /// Who it is drawn on. A hit line is drawn on its victim and reaches back to its source.
    pub anchor: NetId,
    /// Seconds remaining.
    pub remaining: f32,
    /// Seconds it started with, so the renderer can compute progress without a second field.
    pub total: f32,
}

impl Effect {
    /// 0.0 at birth, 1.0 at death. What the renderer fades and rises by.
    pub fn progress(&self) -> f32 {
        if self.total <= 0.0 {
            return 1.0;
        }
        (1.0 - self.remaining / self.total).clamp(0.0, 1.0)
    }
}

/// Every live effect.
#[derive(Default)]
pub struct Effects {
    live: Vec<Effect>,
}

/// A hard ceiling on live effects.
///
/// A five-man teamfight with creeps produces a lot of damage events per second, and an unbounded
/// list would turn the worst moment of the game into the slowest. Oldest are dropped first.
const MAX_EFFECTS: usize = 200;

impl Effects {
    pub fn new() -> Effects {
        Effects::default()
    }

    /// Ingest one snapshot's events.
    ///
    /// `own` is the viewer's hero, which is the only thing that distinguishes "someone took 40"
    /// from "**you** took 40" — and that distinction is most of what makes a fight readable.
    pub fn ingest(&mut self, events: &[NetEvent], own: Option<NetId>) {
        for event in events {
            match *event {
                NetEvent::Damaged {
                    source,
                    target,
                    amount,
                } => {
                    // Q16.16 on the wire; a player wants a whole number.
                    let whole = amount / 65536;
                    if whole > 0 {
                        self.push(
                            EffectKind::DamageNumber {
                                amount: whole,
                                on_self: Some(target) == own,
                            },
                            target,
                            DAMAGE_NUMBER_SECONDS,
                        );
                    }
                    if let Some(from) = source {
                        self.push(
                            EffectKind::HitLine { from, to: target },
                            target,
                            HIT_LINE_SECONDS,
                        );
                    }
                }
                NetEvent::AbilityCast {
                    entity,
                    ability,
                    x,
                    y,
                } => {
                    let at = (crate::interp::from_fixed(x), crate::interp::from_fixed(y));
                    self.push_at(
                        EffectKind::CastRing { ability },
                        entity,
                        CAST_RING_SECONDS,
                        Some(at),
                    );
                    // The name stays on the caster even when the effect does not: it is a label
                    // for who did something, not for where it happened.
                    self.push(EffectKind::CastName { ability }, entity, CAST_NAME_SECONDS);
                }
                NetEvent::Died { entity } => {
                    self.push(EffectKind::Death, entity, DEATH_MARK_SECONDS);
                }
                // The rest are HUD messages rather than world effects, and are read straight off
                // the snapshot by the renderer.
                _ => {}
            }
        }
    }

    fn push_at(&mut self, kind: EffectKind, anchor: NetId, seconds: f32, at: Option<(f32, f32)>) {
        if self.live.len() >= MAX_EFFECTS {
            self.live.remove(0);
        }
        self.live.push(Effect {
            kind,
            anchor,
            at,
            remaining: seconds,
            total: seconds,
        });
    }

    fn push(&mut self, kind: EffectKind, anchor: NetId, seconds: f32) {
        self.push_at(kind, anchor, seconds, None);
    }

    /// Age everything and drop what has expired.
    pub fn advance(&mut self, dt_seconds: f32) {
        for effect in &mut self.live {
            effect.remaining -= dt_seconds;
        }
        self.live.retain(|e| e.remaining > 0.0);
    }

    pub fn iter(&self) -> impl Iterator<Item = &Effect> {
        self.live.iter()
    }

    pub fn len(&self) -> usize {
        self.live.len()
    }

    pub fn is_empty(&self) -> bool {
        self.live.is_empty()
    }
}
