//! Running abilities: the tick phases that turn an [`AbilitySpec`] into things happening.
//!
//! Split from `sim.rs` because the tick loop is a short, readable list of phases and this is the
//! long part; `sim.rs` calls into here and stays legible.
//!
//! ## A note on durations in specs
//!
//! A `Modifier` in a spec carries its `until_tick` field as a **duration in ticks**, because a
//! catalogue built once at startup cannot know what tick anyone will cast on. [`rebase`] turns
//! it into an absolute tick at the moment of application. A duration of zero means "for as long
//! as the toggle is on", which the toggle phase refreshes every tick.
//!
//! This is the one place in the crate where a field means two different things depending on
//! where you read it, and it is called out here rather than discovered later.

use crate::ability::{
    AbilityId, AbilitySpec, Cast, CastRefusal, Dash, Effect, Selection, Target, Targeting, Zone,
};
use crate::damage::{Attached, DamageKind, Modifier};
use crate::entity::{Entity, EntityId, EntityKind, Order, Stats, Team};
use crate::fixed::{Fx, Vec2};
use crate::sim::{Event, Sim};
use moba_proto::TICK_HZ;

/// Ticks a toggle's modifiers are granted for. Refreshed every tick while the toggle is on, so
/// it only has to outlive one tick — but two, so that expiry and refresh cannot race at the
/// boundary.
const TOGGLE_GRANT: u32 = 2;

/// Turn a spec's duration-shaped modifier into an absolute one.
///
/// Public alias below for the economy module, which applies aura and item-hook modifiers that
/// were written in the same duration-shaped way.
pub fn rebase_public(modifier: Modifier, tick: u32) -> Modifier {
    rebase(modifier, tick)
}

fn rebase(modifier: Modifier, tick: u32) -> Modifier {
    let shift = |duration: u32| {
        tick + if duration == 0 {
            TOGGLE_GRANT
        } else {
            duration
        }
    };
    match modifier {
        Modifier::Armour(v) => Modifier::Armour(v),
        Modifier::HeatStacks { stacks, until_tick } => Modifier::HeatStacks {
            stacks,
            until_tick: shift(until_tick),
        },
        Modifier::Marked {
            by,
            amp,
            until_tick,
        } => Modifier::Marked {
            by,
            amp,
            until_tick: shift(until_tick),
        },
        Modifier::Shield {
            remaining,
            until_tick,
        } => Modifier::Shield {
            remaining,
            until_tick: shift(until_tick),
        },
        Modifier::Immune { until_tick } => Modifier::Immune {
            until_tick: shift(until_tick),
        },
        Modifier::Redirect {
            to,
            share,
            until_tick,
        } => Modifier::Redirect {
            to,
            share,
            until_tick: shift(until_tick),
        },
        Modifier::HealReduction { pct, until_tick } => Modifier::HealReduction {
            pct,
            until_tick: shift(until_tick),
        },
        Modifier::Stun { until_tick } => Modifier::Stun {
            until_tick: shift(until_tick),
        },
        Modifier::Silence { until_tick } => Modifier::Silence {
            until_tick: shift(until_tick),
        },
        Modifier::MoveSpeedPct { pct, until_tick } => Modifier::MoveSpeedPct {
            pct,
            until_tick: shift(until_tick),
        },
        Modifier::SlowImmune { until_tick } => Modifier::SlowImmune {
            until_tick: shift(until_tick),
        },
        Modifier::AttackSpeedPct { pct, until_tick } => Modifier::AttackSpeedPct {
            pct,
            until_tick: shift(until_tick),
        },
        Modifier::FreeCast { until_tick } => Modifier::FreeCast {
            until_tick: shift(until_tick),
        },
        Modifier::ProjectileBlock { until_tick } => Modifier::ProjectileBlock {
            until_tick: shift(until_tick),
        },
        // Flat stat grants are permanent while attached — an item is not on a timer — so they
        // pass through untouched.
        Modifier::MaxHealthFlat(v) => Modifier::MaxHealthFlat(v),
        Modifier::MoveSpeedFlat(v) => Modifier::MoveSpeedFlat(v),
        Modifier::AttackDamageFlat(v) => Modifier::AttackDamageFlat(v),
        Modifier::MagicDamageFlat(v) => Modifier::MagicDamageFlat(v),
        Modifier::Regen {
            per_tick,
            until_tick,
        } => Modifier::Regen {
            per_tick,
            until_tick: shift(until_tick),
        },
        Modifier::Stealth { until_tick } => Modifier::Stealth {
            until_tick: shift(until_tick),
        },
        Modifier::Banished { until_tick } => Modifier::Banished {
            until_tick: shift(until_tick),
        },
        Modifier::AttackChain { stacks, until_tick } => Modifier::AttackChain {
            stacks,
            until_tick: shift(until_tick),
        },
    }
}

impl Sim {
    /// Ability power added to a magical effect.
    ///
    /// Only magical: a pure-damage drawback like Meltdown's upkeep must not scale with the
    /// items you bought, or the drawback quietly becomes a reason not to build damage.
    fn ability_power_bonus(&self, caster: EntityId, kind: DamageKind) -> Fx {
        if kind == DamageKind::Magical {
            self.magic_power(caster)
        } else {
            Fx::ZERO
        }
    }

    /// How much an ability's damage grows with the caster's level.
    ///
    /// Abilities scale with level rather than with a chosen rank — the simplification `level.rs`
    /// explains. Returns a multiplier, so a level-one hero is unaffected.
    fn level_scale(&self, caster: EntityId) -> Fx {
        let level = self
            .entities
            .get(caster)
            .filter(|e| e.kind == EntityKind::Hero)
            .map(|e| e.level())
            .unwrap_or(1);
        Fx::ONE + crate::level::bonus_for(level).ability_scale
    }

    /// Attempt a cast, in the order a player would expect to be told about a refusal.
    ///
    /// The checks are sequenced deliberately: "you are stunned" is more useful than "that is on
    /// cooldown" when both are true, and range is checked last because it is the one the player
    /// can fix by walking.
    pub fn try_cast(
        &mut self,
        caster: EntityId,
        slot: usize,
        target: Target,
        events: &mut Vec<Event>,
    ) -> Result<(), CastRefusal> {
        let tick = self.tick;
        let entity = self
            .entities
            .get(caster)
            .ok_or(CastRefusal::NoSuchAbility)?;

        if entity.is_incapacitated(tick) {
            return Err(CastRefusal::Stunned);
        }
        if entity.is_silenced(tick) {
            return Err(CastRefusal::Silenced);
        }
        if entity.casting.is_some() {
            return Err(CastRefusal::AlreadyCasting);
        }

        // The ultimate is locked until level six. The one rank rule in the game — `level.rs`
        // explains why choosing *which* ability to raise is deliberately absent, and why this
        // one is not: an ultimate available at minute zero is a different game.
        if slot == crate::level::ULTIMATE_SLOT
            && entity.kind == EntityKind::Hero
            && entity.level() < crate::level::ULTIMATE_LEVEL
        {
            return Err(CastRefusal::NotLearned);
        }

        let id = entity
            .abilities
            .id(slot)
            .ok_or(CastRefusal::NoSuchAbility)?;
        let spec = self
            .abilities
            .get(id)
            .ok_or(CastRefusal::NoSuchAbility)?
            .clone();

        if entity.abilities.state[slot].cooldown > 0 {
            return Err(CastRefusal::OnCooldown);
        }

        if spec.toggle {
            let on = entity.abilities.state[slot].toggled_on;
            let entity = self.entities.get_mut(caster).unwrap();
            entity.abilities.state[slot].toggled_on = !on;
            if on {
                // Turning off removes exactly what this ability granted, and nothing an item
                // or another ability contributed. That is the whole reason `Attached` carries a
                // source.
                entity.detach_from(id);
            }
            // Toggles announce themselves like everything else. They returned early before the
            // event was pushed, so Bulwark and Meltdown produced *no* feedback at all — no
            // effect, no name, nothing — and were indistinguishable from a key that did not
            // work. A toggle is the one ability where the player most needs to know its state.
            events.push(Event::AbilityCast {
                entity: caster,
                ability: id,
                at: self
                    .entities
                    .get(caster)
                    .map(|e| e.pos)
                    .unwrap_or(Vec2::ZERO),
            });
            return Ok(());
        }

        // Emberwitch's Flashstep makes the *next* spell free, which is checked here rather than
        // inside the effect list because it has to suppress the cost, not refund it.
        let free = entity.has_status(tick, |m| matches!(m, Modifier::FreeCast { .. }));
        if !free && entity.mana < spec.mana_cost {
            return Err(CastRefusal::NotEnoughMana);
        }

        let target = self.validate_target(caster, &spec, target)?;

        let entity = self.entities.get_mut(caster).unwrap();
        if free {
            entity
                .modifiers
                .retain(|a| !matches!(a.modifier, Modifier::FreeCast { .. }));
        } else {
            entity.mana -= spec.mana_cost;
        }
        entity.abilities.state[slot].cooldown = spec.cooldown;
        entity.last_action_tick = tick;
        entity.casting = Some(Cast {
            ability: id,
            slot,
            target,
            ticks_remaining: spec.cast_time,
            channel_remaining: spec.channel_time,
            fired: false,
        });

        // An instant with no cast time fires within the same tick it was ordered, rather than
        // waiting for the next one. A player pressing a 0s ability and seeing nothing happen for
        // 33ms reads as input lag, and this is the cheapest place to not have any.
        if spec.cast_time == 0 {
            self.advance_one_cast(caster, events);
        }
        Ok(())
    }

    /// Check the aim against the ability's targeting mode, normalising it on the way.
    fn validate_target(
        &self,
        caster: EntityId,
        spec: &AbilitySpec,
        target: Target,
    ) -> Result<Target, CastRefusal> {
        let origin = self.entities.get(caster).ok_or(CastRefusal::BadTarget)?.pos;

        let aim = match spec.targeting {
            Targeting::SelfCast => return Ok(Target::None),
            Targeting::Unit => {
                let id = target.unit().ok_or(CastRefusal::BadTarget)?;
                let t = self.entities.get(id).ok_or(CastRefusal::BadTarget)?;
                if !t.is_alive() {
                    return Err(CastRefusal::BadTarget);
                }
                t.pos
            }
            Targeting::Point | Targeting::Vector | Targeting::Skillshot => {
                target.point().ok_or(CastRefusal::BadTarget)?
            }
        };

        if spec.range > Fx::ZERO && (aim - origin).len_sq() > spec.range.sq() {
            return Err(CastRefusal::OutOfRange);
        }
        Ok(target)
    }

    /// Advance every cast and channel in progress by one tick.
    pub(crate) fn advance_casts(&mut self, events: &mut Vec<Event>) {
        for id in self.entities.ids() {
            self.advance_one_cast(id, events);
        }
    }

    fn advance_one_cast(&mut self, id: EntityId, events: &mut Vec<Event>) {
        let tick = self.tick;
        let Some(entity) = self.entities.get(id) else {
            return;
        };
        let Some(cast) = entity.casting else { return };

        // A stun cancels a cast outright — including a channel already in progress, which is
        // exactly what makes Ironclad's Last Stand and Jukebox's Encore interruptible and
        // therefore counterable.
        if entity.is_incapacitated(tick) {
            if let Some(e) = self.entities.get_mut(id) {
                e.casting = None;
            }
            events.push(Event::CastInterrupted {
                entity: id,
                ability: cast.ability,
            });
            return;
        }

        if cast.ticks_remaining > 0 {
            if let Some(e) = self.entities.get_mut(id) {
                if let Some(c) = e.casting.as_mut() {
                    c.ticks_remaining -= 1;
                }
            }
            return;
        }

        let Some(spec) = self.abilities.get(cast.ability).cloned() else {
            return;
        };

        // A channel fires on the tick it lands and once per tick thereafter; an ordinary cast
        // fires exactly once. Both go through the same path so a channelled damage effect and
        // an instant one cannot diverge.
        if !cast.fired || spec.channel_time > 0 {
            self.fire_effects(id, &spec, cast.target, events);
            if !cast.fired {
                // The aim point rides along so a ground-targeted effect is drawn where it
                // actually landed, rather than on the caster — which is where Cinder's blast was
                // being drawn, several hundred units from the ground it was burning.
                let at = match cast.target {
                    Target::Point(p) => p,
                    Target::Unit(unit) => self
                        .entities
                        .get(unit)
                        .map(|e| e.pos)
                        .or_else(|| self.entities.get(id).map(|e| e.pos))
                        .unwrap_or(Vec2::ZERO),
                    Target::None => self.entities.get(id).map(|e| e.pos).unwrap_or(Vec2::ZERO),
                };
                events.push(Event::AbilityCast {
                    entity: id,
                    ability: cast.ability,
                    at,
                });
            }
        }

        let Some(entity) = self.entities.get_mut(id) else {
            return;
        };
        let Some(c) = entity.casting.as_mut() else {
            return;
        };
        c.fired = true;
        if c.channel_remaining > 0 {
            c.channel_remaining -= 1;
        } else {
            entity.casting = None;
        }
    }

    /// Resolve an ability's effect list against the world.
    pub(crate) fn fire_effects(
        &mut self,
        caster: EntityId,
        spec: &AbilitySpec,
        target: Target,
        events: &mut Vec<Event>,
    ) {
        for (selection, effect) in &spec.effects {
            let selected = self.select(caster, *selection, target);
            self.apply_effect(caster, spec.id, &selected, *effect, target, events);
        }
    }

    /// Who an effect lands on.
    ///
    /// Always returns ids in arena order, which is what makes a multi-target effect resolve
    /// identically on two machines — a pierce that damages three creeps must damage them in the
    /// same sequence, since the first one to die changes what the rest are worth.
    fn select(&self, caster: EntityId, selection: Selection, target: Target) -> Vec<EntityId> {
        let Some(source) = self.entities.get(caster) else {
            return Vec::new();
        };
        let origin = source.pos;
        let team = source.team;
        let aim = match target {
            Target::Point(p) => p,
            Target::Unit(id) => self.entities.get(id).map(|e| e.pos).unwrap_or(origin),
            Target::None => origin,
        };

        match selection {
            Selection::Caster => vec![caster],
            Selection::TargetUnit => target.unit().into_iter().collect(),
            Selection::EnemiesInCircle { radius } => self
                .entities
                .iter()
                .filter(|(_, e)| e.is_alive() && team.hostile_to(e.team) && !e.kind.is_ethereal())
                .filter(|(_, e)| (e.pos - aim).len_sq() <= radius.sq())
                .map(|(id, _)| id)
                .collect(),
            Selection::AlliesAroundCaster { radius } => self
                .entities
                .iter()
                .filter(|(_, e)| e.is_alive() && e.team == team && !e.kind.is_ethereal())
                .filter(|(_, e)| (e.pos - origin).len_sq() <= radius.sq())
                .map(|(id, _)| id)
                .collect(),
            Selection::EnemiesInLine { length, width } => {
                let direction = (aim - origin).normalized();
                if direction == Vec2::ZERO {
                    return Vec::new();
                }
                self.entities
                    .iter()
                    .filter(|(_, e)| {
                        e.is_alive() && team.hostile_to(e.team) && !e.kind.is_ethereal()
                    })
                    .filter(|(_, e)| {
                        let to = e.pos - origin;
                        // Distance along the line, then distance from it. Both from the dot and
                        // cross products, so no trigonometry and nothing to diverge.
                        let along = to.x * direction.x + to.y * direction.y;
                        if along < Fx::ZERO || along > length {
                            return false;
                        }
                        let across = (to.x * direction.y - to.y * direction.x).abs();
                        across <= width
                    })
                    .map(|(id, _)| id)
                    .collect()
            }
        }
    }

    fn apply_effect(
        &mut self,
        caster: EntityId,
        source_ability: AbilityId,
        selected: &[EntityId],
        effect: Effect,
        target: Target,
        events: &mut Vec<Event>,
    ) {
        let tick = self.tick;
        match effect {
            Effect::Damage { amount, kind } => {
                let amount =
                    amount * self.level_scale(caster) + self.ability_power_bonus(caster, kind);
                for id in selected {
                    self.deal_damage(Some(caster), *id, amount, kind, events);
                    self.apply_on_ability_damage(caster, *id);
                }
            }
            Effect::DamageByMissingHealth { per_missing, kind } => {
                let Some(c) = self.entities.get(caster) else {
                    return;
                };
                let missing = c.stats.max_hp - c.hp;
                let amount = missing * per_missing + self.ability_power_bonus(caster, kind);
                for id in selected {
                    self.deal_damage(Some(caster), *id, amount, kind, events);
                    self.apply_on_ability_damage(caster, *id);
                }
            }
            Effect::Heal { amount } => {
                for id in selected {
                    let Some(e) = self.entities.get_mut(*id) else {
                        continue;
                    };
                    // Null Pointer's whole reason for existing.
                    let reduction = e
                        .modifiers
                        .iter()
                        .filter(|a| !a.is_expired(tick))
                        .find_map(|a| match a.modifier {
                            Modifier::HealReduction { pct, .. } => Some(pct),
                            _ => None,
                        })
                        .unwrap_or(Fx::ZERO);
                    let healed = amount * (Fx::ONE - reduction);
                    e.hp = (e.hp + healed).min(e.stats.max_hp);
                }
            }
            Effect::Apply(modifier) => {
                // A catalogue entry cannot know who will cast it, so Ghostuser's mark and
                // Relay's tether are written naming a placeholder and the real caster is
                // substituted here. See `AbilityId::PLACEHOLDER_ENTITY`.
                let modifier = match modifier {
                    Modifier::Marked {
                        by,
                        amp,
                        until_tick,
                    } if by == EntityId::PLACEHOLDER => Modifier::Marked {
                        by: caster,
                        amp,
                        until_tick,
                    },
                    Modifier::Redirect {
                        to,
                        share,
                        until_tick,
                    } if to == EntityId::PLACEHOLDER => Modifier::Redirect {
                        to: caster,
                        share,
                        until_tick,
                    },
                    other => other,
                };
                for id in selected {
                    let rebased = rebase(modifier, tick);
                    if let Some(e) = self.entities.get_mut(*id) {
                        e.attach(Attached::from(rebased, source_ability), tick);
                    }
                }
            }
            Effect::AddHeat { stacks, duration } => {
                for id in selected {
                    let Some(e) = self.entities.get_mut(*id) else {
                        continue;
                    };
                    let existing = e
                        .modifiers
                        .iter()
                        .filter(|a| !a.is_expired(tick))
                        .find_map(|a| match a.modifier {
                            Modifier::HeatStacks { stacks, .. } => Some(stacks),
                            _ => None,
                        })
                        .unwrap_or(0);
                    // Capped at three, per the spec. Applying a fourth refreshes the duration
                    // rather than doing nothing, which is what keeps sustained damage from
                    // letting Heat lapse mid-combo.
                    let total = (existing + stacks).min(3);
                    e.attach(
                        Attached::from(
                            Modifier::HeatStacks {
                                stacks: total,
                                until_tick: tick + duration,
                            },
                            source_ability,
                        ),
                        tick,
                    );
                }
            }
            Effect::ConsumeHeat { per_stack } => {
                for id in selected {
                    let Some(e) = self.entities.get_mut(*id) else {
                        continue;
                    };
                    let stacks = e
                        .modifiers
                        .iter()
                        .filter(|a| !a.is_expired(tick))
                        .find_map(|a| match a.modifier {
                            Modifier::HeatStacks { stacks, .. } => Some(stacks),
                            _ => None,
                        })
                        .unwrap_or(0);
                    if stacks == 0 {
                        continue;
                    }
                    e.modifiers
                        .retain(|a| !matches!(a.modifier, Modifier::HeatStacks { .. }));
                    let bonus = per_stack * Fx::from_int(stacks as i32);
                    self.deal_damage(Some(caster), *id, bonus, DamageKind::Magical, events);
                    self.apply_on_ability_damage(caster, *id);
                }
            }
            Effect::Dash {
                speed,
                max_distance,
                stop_on_hero,
            } => {
                let Some(aim) = target.point() else { return };
                let Some(e) = self.entities.get_mut(caster) else {
                    return;
                };
                let direction = (aim - e.pos).normalized();
                if direction == Vec2::ZERO {
                    return;
                }
                e.dash = Some(Dash {
                    direction,
                    speed,
                    remaining: max_distance,
                    stop_on_hero,
                });
            }
            Effect::Blink { max_range } => {
                let Some(aim) = target.point() else { return };
                let Some(e) = self.entities.get_mut(caster) else {
                    return;
                };
                let to = aim - e.pos;
                let distance = to.len().min(max_range);
                e.pos = e.pos + to.normalized().scale(distance);
            }
            Effect::Knockback { distance } => {
                let Some(origin) = self.entities.get(caster).map(|e| e.pos) else {
                    return;
                };
                for id in selected {
                    let Some(e) = self.entities.get_mut(*id) else {
                        continue;
                    };
                    if e.kind.is_structure() {
                        continue;
                    }
                    let away = (e.pos - origin).normalized();
                    // Someone standing exactly on the caster has no "away"; leave them put
                    // rather than picking an arbitrary direction that two machines might
                    // disagree about.
                    if away != Vec2::ZERO {
                        e.pos = e.pos + away.scale(distance);
                    }
                }
            }
            Effect::ForceAttackCaster { duration } => {
                for id in selected {
                    let Some(e) = self.entities.get_mut(*id) else {
                        continue;
                    };
                    if e.kind.is_structure() {
                        continue;
                    }
                    e.order = Order::Forced {
                        target: caster,
                        until_tick: tick + duration,
                    };
                }
            }
            Effect::SpawnZone {
                radius,
                duration,
                damage_per_tick,
                kind,
            } => {
                let Some(aim) = target
                    .point()
                    .or_else(|| self.entities.get(caster).map(|e| e.pos))
                else {
                    return;
                };
                let Some(owner) = self.entities.get(caster) else {
                    return;
                };
                let owner_team = owner.team;
                let mut stats = Stats::base();
                stats.max_hp = Fx::ONE;
                stats.attack_damage = Fx::ZERO;
                stats.move_speed = Fx::ZERO;
                // A zone belongs to no team so that nothing tries to attack it and it never
                // appears as a target; `owner_team` is what it checks damage against.
                let mut zone = Entity::new(EntityKind::Zone, Team::Neutral, aim, stats);
                zone.zone = Some(Zone {
                    radius,
                    expires_tick: tick + duration,
                    damage_per_tick,
                    kind,
                    owner: Some(caster),
                    owner_team,
                });
                self.entities.spawn(zone);
            }
            Effect::HealOverTime { per_tick, duration } => {
                for id in selected {
                    let modifier = Modifier::Regen {
                        per_tick,
                        until_tick: tick + duration,
                    };
                    if let Some(e) = self.entities.get_mut(*id) {
                        e.attach(Attached::from(modifier, source_ability), tick);
                    }
                }
                // The death trigger — "if the target dies while it runs, Jukebox is healed for
                // the remainder instead" — is deliberately not implemented as a callback. The
                // reap phase already walks the dead; making it look for a Regen sourced to
                // Requiem is one check in a loop that runs anyway, against a subscription
                // mechanism that would exist for exactly one ability.
            }
            Effect::Rewind {
                ticks_ago,
                heal_fraction,
            } => {
                let Some(e) = self.entities.get(caster) else {
                    return;
                };
                // Newest last, so `ticks_ago` back from the end. A hero who has been alive for
                // less than the window rewinds as far as the history goes rather than refusing:
                // an ability that silently does nothing early in a match is worse than one that
                // does slightly less.
                let history = &e.position_history;
                let index = history.len().saturating_sub(ticks_ago as usize + 1);
                let Some(was) = history.get(index).copied() else {
                    return;
                };
                let taken = e.stats.max_hp - e.hp;
                let max_hp = e.stats.max_hp;
                if let Some(e) = self.entities.get_mut(caster) {
                    e.pos = was;
                    e.hp = (e.hp + taken * heal_fraction).min(max_hp);
                }
            }
            Effect::Banish { duration } => {
                for id in selected {
                    let modifier = Modifier::Banished {
                        until_tick: tick + duration,
                    };
                    if let Some(e) = self.entities.get_mut(*id) {
                        e.attach(Attached::from(modifier, source_ability), tick);
                        // Whatever they were doing is over. A banished hero returning
                        // mid-channel would be finishing a cast nobody could see start.
                        e.casting = None;
                        e.dash = None;
                    }
                }
            }
            Effect::Dispel { slows } => {
                for id in selected {
                    let Some(e) = self.entities.get_mut(*id) else {
                        continue;
                    };
                    if slows {
                        // Only *reductions*. Stripping every MoveSpeedPct would have Vent
                        // remove Jukebox's haste from its own user, which is a dispel working
                        // against the team that cast it.
                        e.modifiers.retain(|a| {
                            !matches!(a.modifier, Modifier::MoveSpeedPct { pct, .. } if pct < Fx::ZERO)
                        });
                    }
                }
            }
            Effect::DamageByStacks { per_stack, kind } => {
                let stacks = self
                    .entities
                    .get(caster)
                    .map(|e| {
                        e.modifiers
                            .iter()
                            .filter(|a| !a.is_expired(tick))
                            .find_map(|a| match a.modifier {
                                Modifier::AttackChain { stacks, .. } => Some(stacks),
                                _ => None,
                            })
                            .unwrap_or(0)
                    })
                    .unwrap_or(0);
                // Always at least one stack's worth: a skillshot that does nothing because you
                // have not autoattacked recently is an ability the player cannot rely on.
                let amount = per_stack * Fx::from_int(stacks.max(1) as i32);
                for id in selected {
                    self.deal_damage(Some(caster), *id, amount, kind, events);
                    self.apply_on_ability_damage(caster, *id);
                }
            }
            Effect::Summon {
                count,
                duration,
                tether_share,
            } => {
                let Some(origin) = target
                    .point()
                    .or_else(|| self.entities.get(caster).map(|e| e.pos))
                else {
                    return;
                };
                let Some(owner) = self.entities.get(caster) else {
                    return;
                };
                let (team, lane) = (owner.team, owner.lane);

                for index in 0..count {
                    // Fanned out so a Swarm arrives as four drones rather than one stack that
                    // any area effect deletes at once.
                    let offset = Fx::from_int(index as i32 * 70);
                    let mut drone = Entity::new(
                        EntityKind::Creep,
                        team,
                        Vec2::new(origin.x + offset, origin.y - offset),
                        Stats::drone(),
                    );
                    // A drone is a creep that belongs to someone: it uses the same autonomous
                    // behaviour the lane creeps already have rather than needing an AI of its
                    // own, which is why `Stats::drone` is the only new thing here.
                    drone.owner = Some(caster);
                    drone.expires_tick = Some(tick + duration);
                    drone.order = Order::PushLane;
                    drone.lane = lane;
                    let drone_id = self.entities.spawn(drone);

                    if let Some(share) = tether_share {
                        let modifier = Modifier::Redirect {
                            to: caster,
                            share,
                            until_tick: tick + duration,
                        };
                        if let Some(d) = self.entities.get_mut(drone_id) {
                            d.attach(Attached::from(modifier, source_ability), tick);
                        }
                    }
                }
            }
            Effect::Barrier { radius, duration } => {
                let Some(aim) = target
                    .point()
                    .or_else(|| self.entities.get(caster).map(|e| e.pos))
                else {
                    return;
                };
                // **The runtime terrain mutation.** Blocking cells is easy; the hard part is
                // that anything mid-path must survive the ground changing under it, which the
                // movement phase handles by sliding along a blocked axis rather than stopping
                // dead. Creeps walk waypoints rather than a flow field, so a Barrier across a
                // lane makes them squeeze past it instead of routing around — acceptable at
                // three seconds, and the point at which that stops being acceptable is the
                // point at which flow fields earn their place.
                self.map.terrain.block_disc(aim, radius);
                self.barriers.push((aim, radius, tick + duration));
            }
        }
    }

    /// Burn everything standing in a zone, and retire zones whose time is up.
    pub(crate) fn tick_zones(&mut self, events: &mut Vec<Event>) {
        let tick = self.tick;
        let zones: Vec<(EntityId, Zone, Vec2)> = self
            .entities
            .iter()
            .filter_map(|(id, e)| e.zone.map(|z| (id, z, e.pos)))
            .collect();

        for (zone_id, zone, pos) in zones {
            if tick >= zone.expires_tick {
                self.entities.despawn(zone_id);
                continue;
            }
            let victims: Vec<EntityId> = self
                .entities
                .iter()
                .filter(|(_, e)| {
                    e.is_alive()
                        && !e.kind.is_ethereal()
                        && zone.owner_team.hostile_to(e.team)
                        && (e.pos - pos).len_sq() <= zone.radius.sq()
                })
                .map(|(id, _)| id)
                .collect();
            for victim in victims {
                self.deal_damage(zone.owner, victim, zone.damage_per_tick, zone.kind, events);
            }
        }
    }

    /// Move anything mid-dash, and charge toggles their upkeep.
    pub(crate) fn advance_dashes(&mut self) {
        for id in self.entities.ids() {
            let Some(e) = self.entities.get(id) else {
                continue;
            };
            let Some(dash) = e.dash else { continue };

            let step = (dash.speed / Fx::from_int(TICK_HZ as i32)).min(dash.remaining);
            let next = e.pos + dash.direction.scale(step);
            let team = e.team;

            // Shield Charge stops at the first enemy *hero*, riding through creeps. Checked
            // against the position it is about to occupy rather than the one it holds, or a fast
            // dash steps straight past a hero between ticks.
            let blocker = if dash.stop_on_hero {
                self.entities
                    .iter()
                    .find(|(other, e)| {
                        *other != id
                            && e.kind == EntityKind::Hero
                            && e.is_alive()
                            && team.hostile_to(e.team)
                            && (e.pos - next).len_sq() <= Fx::from_int(120).sq()
                    })
                    .map(|(other, _)| other)
            } else {
                None
            };

            let Some(e) = self.entities.get_mut(id) else {
                continue;
            };
            e.pos = next;
            match e.dash.as_mut() {
                Some(d) => {
                    d.remaining -= step;
                    if d.remaining <= Fx::ZERO || blocker.is_some() {
                        e.dash = None;
                    }
                }
                None => continue,
            }

            if let Some(blocker) = blocker {
                let stun = Modifier::Stun {
                    until_tick: self.tick + (TICK_HZ * 12) / 10,
                };
                if let Some(victim) = self.entities.get_mut(blocker) {
                    victim.attach(
                        Attached::from(stun, crate::ability::ids::SHIELD_CHARGE),
                        self.tick,
                    );
                    victim.casting = None;
                }
            }
        }
    }

    /// Tick every ability cooldown down, and charge toggles for the second just elapsed.
    pub(crate) fn tick_abilities(&mut self) {
        let tick = self.tick;
        for id in self.entities.ids() {
            let Some(entity) = self.entities.get(id) else {
                continue;
            };
            let slots = entity.abilities.slots;

            for (slot, ability) in slots
                .iter()
                .enumerate()
                .filter_map(|(i, a)| a.map(|a| (i, a)))
            {
                if let Some(e) = self.entities.get_mut(id) {
                    if e.abilities.state[slot].cooldown > 0 {
                        e.abilities.state[slot].cooldown -= 1;
                    }
                }

                let on = self
                    .entities
                    .get(id)
                    .is_some_and(|e| e.abilities.state[slot].toggled_on);
                if !on {
                    continue;
                }

                let Some(spec) = self.abilities.get(ability).cloned() else {
                    continue;
                };

                // Upkeep is priced per second and charged per tick, so a toggle held for half a
                // second costs half. Charging the whole second on the tick it rolls over would
                // let a player flicker the toggle and pay almost nothing.
                // Meltdown is priced in health rather than mana; everything else in mana.
                let costs_health = spec.health_upkeep > Fx::ZERO;
                let per_tick = if costs_health {
                    spec.health_upkeep / Fx::from_int(TICK_HZ as i32)
                } else {
                    spec.mana_cost / Fx::from_int(TICK_HZ as i32)
                };
                let broke = match self.entities.get_mut(id) {
                    Some(e) if costs_health => {
                        // Never self-kills. A toggle that could finish you would make Meltdown
                        // a suicide button rather than a risk, and "Vent or die" is meant to be
                        // a decision, not an execution.
                        if e.hp > per_tick + Fx::ONE {
                            e.hp -= per_tick;
                            false
                        } else {
                            true
                        }
                    }
                    Some(e) if e.mana >= per_tick => {
                        e.mana -= per_tick;
                        false
                    }
                    Some(_) => true,
                    None => continue,
                };

                if broke {
                    // Out of mana turns the toggle off rather than letting it run free.
                    if let Some(e) = self.entities.get_mut(id) {
                        e.abilities.state[slot].toggled_on = false;
                        e.detach_from(ability);
                    }
                    continue;
                }

                // Re-apply the granted modifiers. `Entity::attach` replaces rather than stacks,
                // so this is a refresh and not a thousand copies of Bulwark's armour.
                self.fire_effects(id, &spec, Target::None, &mut Vec::new());
                let _ = tick;
            }
        }
    }
}
