//! The damage pipeline.
//!
//! **This is the first of the three findings in MOBA.md, and the reason the roster was designed
//! before the engine.** Damage is never `target.hp -= n`, because by the time the roster is
//! complete there are seven different things with an opinion about a damage event in flight:
//!
//! | Source | What it wants |
//! | --- | --- |
//! | Armour | reduce physical damage |
//! | Emberwitch's Heat | amplify magic damage taken, per stack |
//! | Ghostuser's Read Receipt | amplify damage *from one particular source* |
//! | Overclock's Vent | drop it to zero for half a second |
//! | Firewall (item) | absorb it into a shield |
//! | Relay's Link | send 30% of it somewhere else entirely |
//! | Null Pointer (item) | attach a debuff as a side effect |
//!
//! So a damage event is *built*, then walked through ordered stages that each get to change it,
//! and only then applied. Retrofitting this shape later would touch all twenty-four abilities
//! and all five items, which is exactly the rewrite the spec-first order was meant to avoid.

use crate::ability::AbilityId;
use crate::entity::{Entity, EntityId};
use crate::fixed::Fx;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum DamageKind {
    /// Reduced by armour.
    Physical,
    /// Reduced by nothing yet; amplified by Heat.
    Magical,
    /// Ignores armour and shields — but *not* immunity. Overclock's Meltdown self-damage, so
    /// that stacking armour cannot make his drawback disappear while Vent can still answer it.
    Pure,
}

/// A damage event under construction.
#[derive(Clone, Copy, Debug)]
pub struct Damage {
    /// `None` for damage the world deals to you — Meltdown's upkeep, a burning ground zone
    /// whose caster has since died. A pipeline stage that needs a source must handle its
    /// absence rather than assume one.
    pub source: Option<EntityId>,
    pub amount: Fx,
    pub kind: DamageKind,
}

impl Damage {
    pub fn new(source: Option<EntityId>, amount: Fx, kind: DamageKind) -> Damage {
        Damage {
            source,
            amount,
            kind,
        }
    }
}

/// The ordered phases a damage event passes through.
///
/// Order is a balance decision as much as a technical one, and it is written down here because
/// it is otherwise invisible and will be argued about later:
///
/// 1. **Amplify** before mitigate, so Heat stacks scale the raw hit rather than the leftovers —
///    otherwise armour would quietly nerf every amplifier in the game.
/// 2. **Immunity** early, so Vent is a genuine "no" that later stages cannot re-open.
/// 3. **Mitigate**, the armour curve.
/// 4. **Redirect** after mitigation, so Relay takes a share of what *actually landed*. Sharing
///    pre-mitigation damage would make Link worse the tankier the ally was, which is backwards.
/// 5. **Absorb** last, so a shield spends itself on the real number and a 200-point shield
///    stops 200 points of incoming health loss rather than 200 points of raw hit.
#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Debug)]
pub enum Stage {
    Amplify,
    Immunity,
    Mitigate,
    Redirect,
    Absorb,
}

/// Something attached to an entity that changes what happens to it.
///
/// Buffs, debuffs, item passives and item auras are all this one type. They differ in where
/// they came from, not in what they are, and giving each its own type would mean four parallel
/// pipelines that must be kept in the same order.
#[derive(Clone, Copy, Debug)]
pub enum Modifier {
    /// Flat armour from an item. Base armour lives in `Stats` and is added on top.
    Armour(Fx),
    /// Emberwitch's Heat: fractional amplification of magic damage taken, per stack.
    HeatStacks {
        stacks: u32,
        until_tick: u32,
    },
    /// Ghostuser's Read Receipt: amplify damage taken, but only from `by`.
    Marked {
        by: EntityId,
        amp: Fx,
        until_tick: u32,
    },
    /// Firewall's active. Absorbs until spent or expired.
    Shield {
        remaining: Fx,
        until_tick: u32,
    },
    /// Overclock's Vent. A hard zero.
    Immune {
        until_tick: u32,
    },
    /// Relay's Link. Sends `share` of post-mitigation damage to `to`.
    Redirect {
        to: EntityId,
        share: Fx,
        until_tick: u32,
    },
    /// Null Pointer. Not a damage stage at all — it is read by the healing path — but it lives
    /// here because it is applied and expired by the same machinery.
    HealReduction {
        pct: Fx,
        until_tick: u32,
    },

    // ── Statuses ────────────────────────────────────────────────────────────────────────
    //
    // These take no part in the damage pipeline (`stage()` returns `None` for all of them) and
    // are read by the tick phases instead. They live in this enum anyway, because they are
    // applied, stacked and expired by exactly the same machinery as the damage modifiers are,
    // and a parallel `Vec<Status>` would mean two lifetimes to keep in step and two places to
    // remember when writing an ability.
    /// Cannot act, cannot move, and any cast in progress is cancelled.
    Stun {
        until_tick: u32,
    },
    /// Cannot cast. Attacking and moving are unaffected — that distinction is the whole of
    /// Jukebox's Feedback.
    Silence {
        until_tick: u32,
    },
    /// Fractional move-speed change. Negative for a slow, positive for a haste; one variant so
    /// that they cancel by addition instead of by precedence rules.
    MoveSpeedPct {
        pct: Fx,
        until_tick: u32,
    },
    /// Immune to [`Modifier::MoveSpeedPct`] reductions. Jukebox's Encore.
    SlowImmune {
        until_tick: u32,
    },
    /// Fractional attack-speed change. Overclock's Spool Up and Meltdown.
    AttackSpeedPct {
        pct: Fx,
        until_tick: u32,
    },
    /// Emberwitch's Flashstep leaves this behind: the next cast within the window is free.
    FreeCast {
        until_tick: u32,
    },
    // ── Flat stat grants ────────────────────────────────────────────────────────────────
    //
    // How items contribute. Buying an item attaches its bonuses as modifiers sourced to that
    // item, so selling it detaches exactly those and `Entity::effective_stats` needs no
    // knowledge of the item catalogue at all — it folds whatever is attached, whatever put it
    // there.
    MaxHealthFlat(Fx),
    MoveSpeedFlat(Fx),
    AttackDamageFlat(Fx),
    /// Ability power. Added to the magic damage of anything this entity casts.
    MagicDamageFlat(Fx),
    /// Health per tick. Broadcast's aura.
    Regen {
        per_tick: Fx,
        until_tick: u32,
    },

    /// Ghostuser's Idle: invisible to the enemy team.
    ///
    /// Enforced in `moba_sim::net`, not here — a stealthed hero is *filtered out of the enemy's
    /// snapshot entirely*, exactly as a hero in the fog is. Drawing it client-side and trusting
    /// the client not to peek would make stealth the one mechanic in the game a hacked client
    /// could simply switch off.
    Stealth {
        until_tick: u32,
    },
    /// Ghostuser's Ban: off the map. Neither alive-and-present nor dead — cannot act, cannot be
    /// targeted, cannot be hit, and comes back.
    Banished {
        until_tick: u32,
    },
    /// Overclock's Spool Up, tracked as a modifier so it expires on its own if he stops
    /// attacking rather than needing a timer somewhere else.
    AttackChain {
        stacks: u32,
        until_tick: u32,
    },

    /// Ironclad's Bulwark, while toggled on. No mechanics yet — there are no projectiles to
    /// block until abilities gain travel time — but it is declared so the toggle has something
    /// to attach and the renderer has something to draw.
    ProjectileBlock {
        until_tick: u32,
    },
}

impl Modifier {
    fn stage(&self) -> Option<Stage> {
        match self {
            Modifier::HeatStacks { .. } | Modifier::Marked { .. } => Some(Stage::Amplify),
            Modifier::Immune { .. } => Some(Stage::Immunity),
            Modifier::Armour(_) => Some(Stage::Mitigate),
            Modifier::Redirect { .. } => Some(Stage::Redirect),
            Modifier::Shield { .. } => Some(Stage::Absorb),
            Modifier::HealReduction { .. }
            | Modifier::Stun { .. }
            | Modifier::Silence { .. }
            | Modifier::MoveSpeedPct { .. }
            | Modifier::SlowImmune { .. }
            | Modifier::AttackSpeedPct { .. }
            | Modifier::FreeCast { .. }
            | Modifier::ProjectileBlock { .. }
            | Modifier::MaxHealthFlat(_)
            | Modifier::MoveSpeedFlat(_)
            | Modifier::AttackDamageFlat(_)
            | Modifier::MagicDamageFlat(_)
            | Modifier::Regen { .. }
            | Modifier::Stealth { .. }
            | Modifier::Banished { .. }
            | Modifier::AttackChain { .. } => None,
        }
    }

    /// The tick this stops applying on, if it is temporary.
    pub fn until_tick(&self) -> Option<u32> {
        match *self {
            Modifier::Armour(_)
            | Modifier::MaxHealthFlat(_)
            | Modifier::MoveSpeedFlat(_)
            | Modifier::AttackDamageFlat(_)
            | Modifier::MagicDamageFlat(_) => None,
            Modifier::Regen { until_tick, .. } => Some(until_tick),
            Modifier::HeatStacks { until_tick, .. }
            | Modifier::Marked { until_tick, .. }
            | Modifier::Shield { until_tick, .. }
            | Modifier::Immune { until_tick }
            | Modifier::Redirect { until_tick, .. }
            | Modifier::HealReduction { until_tick, .. }
            | Modifier::Stun { until_tick }
            | Modifier::Silence { until_tick }
            | Modifier::MoveSpeedPct { until_tick, .. }
            | Modifier::SlowImmune { until_tick }
            | Modifier::AttackSpeedPct { until_tick, .. }
            | Modifier::FreeCast { until_tick }
            | Modifier::ProjectileBlock { until_tick }
            | Modifier::Stealth { until_tick }
            | Modifier::Banished { until_tick }
            | Modifier::AttackChain { until_tick, .. } => Some(until_tick),
        }
    }

    /// Has this stopped applying, or spent itself?
    pub fn is_expired(&self, tick: u32) -> bool {
        if let Modifier::Shield { remaining, .. } = self {
            if *remaining <= Fx::ZERO {
                return true;
            }
        }
        self.until_tick().is_some_and(|until| tick >= until)
    }
}

/// A modifier together with where it came from.
///
/// The provenance is not decoration. Bulwark is a toggle that grants three modifiers, and
/// turning it off has to remove *those three* — not every `Armour` on the hero, which would
/// silently strip whatever an item contributed. The same field is what lets Vent dispel only
/// slows, and what lets a client show which buff icon belongs to which ability.
///
/// `None` is for modifiers the world attached rather than an ability: a zone's burn, a debuff
/// from an item passive whose source is the item rather than a castable.
#[derive(Clone, Copy, Debug)]
pub struct Attached {
    pub modifier: Modifier,
    pub source: Option<AbilityId>,
}

impl Attached {
    pub fn new(modifier: Modifier) -> Attached {
        Attached {
            modifier,
            source: None,
        }
    }

    pub fn from(modifier: Modifier, source: AbilityId) -> Attached {
        Attached {
            modifier,
            source: Some(source),
        }
    }

    pub fn is_expired(&self, tick: u32) -> bool {
        self.modifier.is_expired(tick)
    }
}

/// What a resolved damage event actually did.
#[derive(Clone, Debug, Default)]
pub struct Resolved {
    /// Health actually removed from the target.
    pub dealt: Fx,
    /// Damage the pipeline handed to somebody else. The caller must apply these — the pipeline
    /// borrows one entity and cannot reach a second.
    pub redirected: Vec<(EntityId, Fx)>,
}

/// The armour curve.
///
/// The standard diminishing form: each point of armour removes a shrinking slice, so armour
/// never reaches immunity and never goes negative-infinite. `k` is the constant that sets how
/// much a point is worth; 0.06 gives roughly the feel of the genre's usual numbers.
fn armour_multiplier(armour: Fx) -> Fx {
    let k = Fx::ratio(6, 100);
    let scaled = k * armour;
    if armour >= Fx::ZERO {
        // 1 - a/(1+a): asymptotic to zero damage taken, never reaching it.
        Fx::ONE - (scaled / (Fx::ONE + scaled))
    } else {
        // Negative armour amplifies, symmetrically and with the same diminishing shape.
        Fx::from_int(2) - (Fx::ONE - (scaled / (Fx::ONE - scaled)))
    }
}

/// Walk a damage event through the stages and apply what survives.
///
/// Takes the target alone because that is all it can safely borrow; anything involving a second
/// entity leaves through [`Resolved::redirected`] for the caller to apply. The caller is also
/// responsible for capping recursion — see `Sim::deal_damage`, where two Relays Linked to each
/// other would otherwise bounce a single autoattack between them forever.
pub fn resolve(target: &mut Entity, damage: Damage, tick: u32) -> Resolved {
    let mut out = Resolved::default();
    let mut amount = damage.amount;
    if amount <= Fx::ZERO {
        return out;
    }

    // Stage order is `Stage`'s ordering, and modifiers within a stage run in attachment order.
    // Both are deterministic, which is the requirement; neither is arbitrary, which is why the
    // sort is stable.
    let mut staged: Vec<(Stage, usize)> = target
        .modifiers
        .iter()
        .enumerate()
        .filter(|(_, a)| !a.is_expired(tick))
        .filter_map(|(i, a)| a.modifier.stage().map(|s| (s, i)))
        .collect();
    staged.sort_by_key(|(stage, _)| *stage);

    // Base armour is not a modifier — it is a stat — so it is folded in at the mitigate stage
    // alongside whatever items contributed.
    let mut bonus_armour = Fx::ZERO;
    let mut mitigated = false;

    for (stage, index) in staged {
        // Pure damage skips the two stages that *reduce* a number — armour and shields — and
        // no others. It still amplifies (Meltdown should hurt more while you are Marked), it
        // still redirects (a tether shares whatever lands), and it is emphatically still
        // stopped by immunity: Meltdown is Pure and Vent is the only answer to it, so an
        // immunity that pure damage ignored would leave Overclock's ultimate with no off
        // switch and the hero unplayable.
        if damage.kind == DamageKind::Pure && matches!(stage, Stage::Mitigate | Stage::Absorb) {
            continue;
        }

        match stage {
            Stage::Amplify => match target.modifiers[index].modifier {
                Modifier::HeatStacks { stacks, .. } if damage.kind == DamageKind::Magical => {
                    amount = amount * (Fx::ONE + Fx::ratio(8, 100) * Fx::from_int(stacks as i32));
                }
                Modifier::Marked { by, amp, .. } if damage.source == Some(by) => {
                    amount = amount * (Fx::ONE + amp);
                }
                _ => {}
            },
            Stage::Immunity => {
                if matches!(target.modifiers[index].modifier, Modifier::Immune { .. }) {
                    return out;
                }
            }
            Stage::Mitigate => {
                if let Modifier::Armour(bonus) = target.modifiers[index].modifier {
                    bonus_armour += bonus;
                }
                mitigated = true;
            }
            Stage::Redirect => {
                // Mitigation is folded in before the first redirect so that the share is taken
                // from the number that would really have landed. Doing it inside the loop
                // rather than after keeps the stage order honest even when no armour modifier
                // exists to trigger the branch above.
                if !mitigated && damage.kind == DamageKind::Physical {
                    amount = amount * armour_multiplier(target.stats.armour + bonus_armour);
                    mitigated = true;
                }
                if let Modifier::Redirect { to, share, .. } = target.modifiers[index].modifier {
                    let moved = amount * share;
                    amount -= moved;
                    out.redirected.push((to, moved));
                }
            }
            Stage::Absorb => {
                if let Modifier::Shield {
                    remaining,
                    until_tick,
                } = target.modifiers[index].modifier
                {
                    let absorbed = if remaining < amount {
                        remaining
                    } else {
                        amount
                    };
                    amount -= absorbed;
                    target.modifiers[index].modifier = Modifier::Shield {
                        remaining: remaining - absorbed,
                        until_tick,
                    };
                    if amount <= Fx::ZERO {
                        break;
                    }
                }
            }
        }
    }

    // The common case: no armour modifier and no redirect, so nothing above ran the curve.
    if !mitigated && damage.kind == DamageKind::Physical {
        amount = amount * armour_multiplier(target.stats.armour + bonus_armour);
    }

    if amount <= Fx::ZERO {
        return out;
    }

    let dealt = if amount > target.hp {
        target.hp
    } else {
        amount
    };
    target.hp -= dealt;
    out.dealt = dealt;
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::entity::{Entity, EntityKind, Stats, Team};
    use crate::fixed::Vec2;

    fn dummy(armour: Fx) -> Entity {
        let mut stats = Stats::melee_creep();
        stats.max_hp = Fx::from_int(1000);
        stats.armour = armour;
        Entity::new(EntityKind::Hero, Team::Blue, Vec2::ZERO, stats)
    }

    fn hit(amount: i32, kind: DamageKind) -> Damage {
        Damage::new(None, Fx::from_int(amount), kind)
    }

    /// Assert a damage figure to within a point.
    ///
    /// `Fx::ratio(8, 100)` is not exactly 0.08 — Q16.16 has no exact eighth-of-a-hundred — so a
    /// 24% amplifier on 100 lands at 123.99 and floors to 123. That truncation is *correct* and,
    /// more importantly, identical on every machine, which is the only property the sim needs.
    /// Asserting exact integers here would be asserting a rounding accident.
    fn assert_close(actual: Fx, expected: i32) {
        let diff = (actual - Fx::from_int(expected)).abs();
        assert!(diff <= Fx::ONE, "expected ~{expected}, got {actual:?}");
    }

    #[test]
    fn zero_armour_takes_full_physical_damage() {
        let mut e = dummy(Fx::ZERO);
        let out = resolve(&mut e, hit(100, DamageKind::Physical), 0);
        assert_eq!(out.dealt.floor_int(), 100);
        assert_eq!(e.hp.floor_int(), 900);
    }

    #[test]
    fn armour_reduces_but_never_eliminates() {
        // The point of the diminishing curve: even an absurd armour value still lets damage in.
        let mut e = dummy(Fx::from_int(1000));
        let out = resolve(&mut e, hit(100, DamageKind::Physical), 0);
        assert!(out.dealt > Fx::ZERO, "armour reached immunity");
        assert!(out.dealt < Fx::from_int(5));
    }

    #[test]
    fn magic_damage_ignores_armour() {
        let mut e = dummy(Fx::from_int(50));
        let out = resolve(&mut e, hit(100, DamageKind::Magical), 0);
        assert_eq!(out.dealt.floor_int(), 100);
    }

    #[test]
    fn heat_stacks_amplify_only_magic() {
        let mut e = dummy(Fx::ZERO);
        e.modifiers.push(Attached::new(Modifier::HeatStacks {
            stacks: 3,
            until_tick: 100,
        }));
        // 3 stacks * 8% == +24%.
        let magic = resolve(&mut e, hit(100, DamageKind::Magical), 0);
        assert_close(magic.dealt, 124);

        let mut e = dummy(Fx::ZERO);
        e.modifiers.push(Attached::new(Modifier::HeatStacks {
            stacks: 3,
            until_tick: 100,
        }));
        let physical = resolve(&mut e, hit(100, DamageKind::Physical), 0);
        assert_eq!(physical.dealt.floor_int(), 100);
    }

    #[test]
    fn a_mark_amplifies_only_its_own_source() {
        use crate::entity::Entities;
        let mut entities = Entities::new();
        let ghostuser = entities.spawn(dummy(Fx::ZERO));
        let someone_else = entities.spawn(dummy(Fx::ZERO));

        let mut e = dummy(Fx::ZERO);
        e.modifiers.push(Attached::new(Modifier::Marked {
            by: ghostuser,
            amp: Fx::ratio(20, 100),
            until_tick: 100,
        }));

        let from_ghost = resolve(
            &mut e,
            Damage::new(Some(ghostuser), Fx::from_int(100), DamageKind::Magical),
            0,
        );
        assert_close(from_ghost.dealt, 120);

        let from_other = resolve(
            &mut e,
            Damage::new(Some(someone_else), Fx::from_int(100), DamageKind::Magical),
            0,
        );
        assert_eq!(from_other.dealt.floor_int(), 100);
    }

    #[test]
    fn immunity_is_a_hard_zero() {
        let mut e = dummy(Fx::ZERO);
        e.modifiers
            .push(Attached::new(Modifier::Immune { until_tick: 100 }));
        // Pure specifically, because Meltdown's self-damage is Pure and Vent is its only
        // counterplay. An earlier cut had Pure skip every stage but Amplify, which silently
        // made Overclock's ultimate impossible to survive.
        let out = resolve(&mut e, hit(9999, DamageKind::Pure), 0);
        assert_eq!(out.dealt, Fx::ZERO);
        assert_eq!(e.hp.floor_int(), 1000);
    }

    #[test]
    fn a_shield_spends_itself_and_then_expires() {
        let mut e = dummy(Fx::ZERO);
        e.modifiers.push(Attached::new(Modifier::Shield {
            remaining: Fx::from_int(60),
            until_tick: 100,
        }));

        let first = resolve(&mut e, hit(100, DamageKind::Magical), 0);
        assert_eq!(
            first.dealt.floor_int(),
            40,
            "shield should eat 60 of the 100"
        );
        assert!(e.modifiers[0].is_expired(0), "a spent shield is expired");

        let second = resolve(&mut e, hit(100, DamageKind::Magical), 0);
        assert_eq!(second.dealt.floor_int(), 100);
    }

    #[test]
    fn pure_damage_ignores_shields_and_armour_but_not_marks() {
        let mut e = dummy(Fx::from_int(100));
        e.modifiers.push(Attached::new(Modifier::Shield {
            remaining: Fx::from_int(500),
            until_tick: 100,
        }));
        let out = resolve(&mut e, hit(100, DamageKind::Pure), 0);
        assert_eq!(out.dealt.floor_int(), 100);
    }

    #[test]
    fn a_tether_moves_its_share_after_mitigation() {
        use crate::entity::Entities;
        let mut entities = Entities::new();
        let relay = entities.spawn(dummy(Fx::ZERO));

        let mut e = dummy(Fx::ZERO);
        e.modifiers.push(Attached::new(Modifier::Redirect {
            to: relay,
            share: Fx::ratio(30, 100),
            until_tick: 100,
        }));
        let out = resolve(&mut e, hit(100, DamageKind::Magical), 0);

        assert_close(out.dealt, 70);
        assert_eq!(out.redirected.len(), 1);
        assert_eq!(out.redirected[0].0, relay);
        assert_close(out.redirected[0].1, 30);
    }

    #[test]
    fn expired_modifiers_do_not_apply() {
        let mut e = dummy(Fx::ZERO);
        e.modifiers
            .push(Attached::new(Modifier::Immune { until_tick: 10 }));
        // Tick 10 is when it stops, not the last tick it works.
        let out = resolve(&mut e, hit(100, DamageKind::Magical), 10);
        assert_eq!(out.dealt.floor_int(), 100);
    }

    #[test]
    fn damage_never_takes_hp_below_zero() {
        let mut e = dummy(Fx::ZERO);
        let out = resolve(&mut e, hit(999_999, DamageKind::Magical), 0);
        assert_eq!(e.hp, Fx::ZERO);
        assert_eq!(
            out.dealt.floor_int(),
            1000,
            "overkill is not counted as dealt"
        );
    }
}
