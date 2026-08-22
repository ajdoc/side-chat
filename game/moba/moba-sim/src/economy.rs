//! Gold, last-hitting, and the shop.
//!
//! ## Why last-hitting is a single field
//!
//! [`Entity::last_damage_from`] is one id, overwritten on every hit that lands, and read exactly
//! once — when the entity dies. That is not a shortcut for a damage log; it is the mechanic. A
//! MOBA's laning phase is built on the *last* hit being worth everything and every hit before it
//! being worth nothing, which is what makes standing in a lane a skill rather than a wait.
//! Nothing in the game asks who dealt the most damage, so nothing stores it.
//!
//! A **deny** falls out of the same field for free: if the killer is on the creep's own team,
//! the enemy simply never gets the credit. No special case, no second code path.

use crate::ability::{AbilitySlots, HERO_SLOTS};
use crate::damage::{Attached, Modifier};
use crate::entity::{EntityId, EntityKind, Team};
use crate::fixed::Fx;
use crate::item::{BuyRefusal, ItemId};
use crate::sim::{Event, Sim};
use moba_proto::TICK_HZ;

/// Passive income, per second, to every living hero. The floor under a player having a bad lane:
/// without it, falling behind early compounds into never buying anything at all.
pub const GOLD_PER_SECOND: i32 = 3;

/// What every hero starts with. Enough for Bootstrap and change.
pub const STARTING_GOLD: i32 = 600;

impl Sim {
    /// Pay every living hero their passive income.
    pub(crate) fn tick_income(&mut self) {
        if !self.tick.is_multiple_of(TICK_HZ) {
            return;
        }
        for id in self.entities.ids() {
            if let Some(e) = self.entities.get_mut(id) {
                if e.kind == EntityKind::Hero && e.is_alive() {
                    e.gold += Fx::from_int(GOLD_PER_SECOND);
                }
            }
        }
    }

    /// Award the bounty for something that just died.
    ///
    /// Called from the reap phase, once, before the corpse leaves the arena — which is the only
    /// moment both the victim's bounty and its killer are still known.
    pub(crate) fn award_bounty(&mut self, victim: EntityId, events: &mut Vec<Event>) {
        let Some(dead) = self.entities.get(victim) else {
            return;
        };
        let bounty = dead.stats.bounty;
        let victim_team = dead.team;
        let Some(killer) = dead.last_damage_from else {
            return;
        };
        if bounty <= Fx::ZERO {
            return;
        }

        let Some(hero) = self.entities.get(killer) else {
            return;
        };
        if hero.kind != EntityKind::Hero {
            // A creep or a tower landing the last hit denies the gold to everyone, which is
            // exactly what should happen — the gold is for the player who timed it.
            return;
        }
        // The deny. Killing your own creep pays nothing and, more to the point, means the enemy
        // laner gets nothing either.
        if !hero.team.hostile_to(victim_team) {
            events.push(Event::Denied { by: killer, victim });
            return;
        }

        // Ledger's hook: extra gold per creep, and only per creep.
        let bonus = if dead.kind == EntityKind::Creep {
            self.item_gold_bonus(killer)
        } else {
            Fx::ZERO
        };
        let total = bounty + bonus;

        if let Some(hero) = self.entities.get_mut(killer) {
            hero.gold += total;
        }
        // Earned, not held: spending it in the shop should not shrink the scoreboard.
        self.scores.record_gold(killer, total);
        events.push(Event::GoldGained {
            hero: killer,
            amount: total,
            from: victim,
        });
    }

    fn item_gold_bonus(&self, hero: EntityId) -> Fx {
        let Some(e) = self.entities.get(hero) else {
            return Fx::ZERO;
        };
        e.items
            .iter()
            .filter_map(|id| self.items.get(*id))
            .map(|spec| spec.gold_per_creep)
            .fold(Fx::ZERO, |a, b| a + b)
    }

    /// Buy an item.
    ///
    /// Attaches the item's bonuses as modifiers sourced to its active, so a future sell detaches
    /// exactly those — the same mechanism Bulwark's toggle uses.
    pub fn buy(&mut self, hero: EntityId, item: ItemId) -> Result<usize, BuyRefusal> {
        let tick = self.tick;
        let spec = self.items.get(item).ok_or(BuyRefusal::NoSuchItem)?.clone();
        let entity = self.entities.get(hero).ok_or(BuyRefusal::NotAHero)?;

        if entity.kind != EntityKind::Hero {
            return Err(BuyRefusal::NotAHero);
        }
        if entity.items.contains(&item) {
            return Err(BuyRefusal::AlreadyOwned);
        }
        if entity.gold < spec.cost {
            return Err(BuyRefusal::CannotAfford);
        }
        let slot = entity
            .abilities
            .free_item_slot()
            .ok_or(BuyRefusal::InventoryFull)?;

        let entity = self.entities.get_mut(hero).unwrap();
        entity.gold -= spec.cost;
        entity.items.push(item);
        // The slot is claimed whether or not the item has an active: an inventory is six slots,
        // not six castables, and a stat stick still takes one up.
        entity.abilities.slots[slot] = spec.active;
        entity.abilities.state[slot] = Default::default();

        for modifier in &spec.modifiers {
            // Sourced to the item's own id space so that two items granting armour do not
            // overwrite each other — `Entity::attach` matches on source *and* kind.
            let source = spec
                .active
                .unwrap_or(crate::ability::AbilityId(1000 + item.0));
            entity.attach(Attached::from(*modifier, source), tick);
        }

        // Buying max health should give you the health, not leave you at a smaller fraction of
        // a bigger bar. Buying armour should not.
        let gained: Fx = spec
            .modifiers
            .iter()
            .filter_map(|m| match *m {
                Modifier::MaxHealthFlat(v) => Some(v),
                _ => None,
            })
            .fold(Fx::ZERO, |a, b| a + b);
        entity.hp += gained;

        Ok(slot)
    }

    /// Project every aura item onto everyone in range, and apply regeneration.
    ///
    /// Auras are re-applied every tick and granted for two, exactly as toggles are: it means an
    /// entity walking out of range simply stops being refreshed rather than needing anyone to
    /// notice it left. `Entity::attach` replaces rather than stacks, so this is a refresh and
    /// not a thousand copies.
    pub(crate) fn tick_auras(&mut self) {
        let tick = self.tick;

        let sources: Vec<(EntityId, Team, ItemId)> = self
            .entities
            .iter()
            .filter(|(_, e)| e.is_alive())
            .flat_map(|(id, e)| e.items.iter().map(move |item| (id, e.team, *item)))
            .filter(|(_, _, item)| self.items.get(*item).is_some_and(|s| s.aura.is_some()))
            .collect();

        for (holder, team, item) in sources {
            let Some(spec) = self.items.get(item) else {
                continue;
            };
            let Some(aura) = spec.aura else { continue };
            let Some(origin) = self.entities.get(holder).map(|e| e.pos) else {
                continue;
            };
            let source = crate::ability::AbilityId(1000 + item.0);

            let recipients: Vec<EntityId> = self
                .entities
                .iter()
                .filter(|(_, e)| {
                    e.is_alive()
                        && e.kind != EntityKind::Zone
                        && (e.team == team) == aura.friendly
                        && (e.pos - origin).len_sq() <= aura.radius.sq()
                })
                .map(|(id, _)| id)
                .collect();

            for id in recipients {
                let modifier = crate::cast::rebase_public(aura.modifier, tick);
                if let Some(e) = self.entities.get_mut(id) {
                    e.attach(Attached::from(modifier, source), tick);
                }
            }
        }

        // Regeneration, once, after every aura has had its say.
        for id in self.entities.ids() {
            let Some(e) = self.entities.get(id) else {
                continue;
            };
            if !e.is_alive() {
                continue;
            }
            let regen: Fx = e
                .modifiers
                .iter()
                .filter(|a| !a.is_expired(tick))
                .filter_map(|a| match a.modifier {
                    Modifier::Regen { per_tick, .. } => Some(per_tick),
                    _ => None,
                })
                .fold(Fx::ZERO, |a, b| a + b);
            if regen <= Fx::ZERO {
                continue;
            }
            let max = e.effective_stats(tick).max_hp;
            if let Some(e) = self.entities.get_mut(id) {
                e.hp = (e.hp + regen).min(max);
            }
        }
    }

    /// Null Pointer's hook: whatever the caster's items say to attach to anyone they hit with an
    /// ability.
    pub(crate) fn apply_on_ability_damage(&mut self, caster: EntityId, victim: EntityId) {
        let tick = self.tick;
        let Some(e) = self.entities.get(caster) else {
            return;
        };
        if e.items.is_empty() {
            return;
        }
        let hooks: Vec<(Modifier, ItemId)> = e
            .items
            .iter()
            .filter_map(|id| self.items.get(*id).map(|s| (s, *id)))
            .filter_map(|(s, id)| s.on_ability_damage.map(|m| (m, id)))
            .collect();

        for (modifier, item) in hooks {
            let rebased = crate::cast::rebase_public(modifier, tick);
            if let Some(v) = self.entities.get_mut(victim) {
                v.attach(
                    Attached::from(rebased, crate::ability::AbilityId(1000 + item.0)),
                    tick,
                );
            }
        }
    }

    /// The magic damage bonus this entity's items contribute to what it casts.
    pub(crate) fn magic_power(&self, caster: EntityId) -> Fx {
        self.entities
            .get(caster)
            .map(|e| e.effective_stats(self.tick).magic_damage)
            .unwrap_or(Fx::ZERO)
    }
}

/// Whether a slot index belongs to the hero or to the inventory. Used by the client to draw the
/// right bar; kept here so the layout constant has exactly one owner.
pub fn is_item_slot(slot: usize) -> bool {
    slot >= HERO_SLOTS && slot < AbilitySlots::default().slots.len()
}
