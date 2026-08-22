//! Everything that exists on the map, and the arena that owns it.
//!
//! One `Entity` type with a `kind` tag rather than a trait object per unit type. A hero, a
//! creep, a tower and Relay's drone differ in their numbers and their behaviour, not in their
//! shape, and a `Vec<Entity>` iterates in a fixed order on every machine — which a collection
//! of boxed trait objects allocated in arrival order does not reliably do.

use crate::ability::{AbilityId, AbilitySlots, Cast, Dash, Zone};
use crate::damage::{Attached, Modifier};
use crate::fixed::{Fx, Vec2};

/// Which side something belongs to.
///
/// `Neutral` is the jungle and, later, Relay's Barrier — things that fight nobody but still
/// occupy the world.
#[derive(Clone, Copy, PartialEq, Eq, Debug, Hash)]
pub enum Team {
    Blue,
    Red,
    Neutral,
}

impl Team {
    /// Whether these two should be trying to kill each other. `Neutral` fights nobody, which is
    /// why this is a method rather than a `!=`.
    #[inline]
    pub fn hostile_to(self, other: Team) -> bool {
        !matches!(self, Team::Neutral) && !matches!(other, Team::Neutral) && self != other
    }

    #[inline]
    pub fn opponent(self) -> Team {
        match self {
            Team::Blue => Team::Red,
            Team::Red => Team::Blue,
            Team::Neutral => Team::Neutral,
        }
    }
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum EntityKind {
    Hero,
    Creep,
    Tower,
    /// An autoattack in flight.
    ///
    /// An entity rather than a side list, for the same reason a zone is one: it moves, it
    /// expires, it has to reach clients, and every one of those is machinery the arena already
    /// has. A parallel list would need its own id space, its own fog filter and its own wire
    /// field.
    Projectile,
    /// A persistent area effect standing on the map — Emberwitch's burning ground, and later
    /// Relay's Barrier. An entity rather than a side table so that it expires, is reaped and is
    /// sent to clients through exactly the machinery everything else already uses.
    Zone,
    /// The thing that, when it falls, ends the match.
    Base,
}

impl EntityKind {
    /// Structures do not move and cannot be pushed, which several tick phases skip on.
    #[inline]
    pub fn is_structure(self) -> bool {
        matches!(self, EntityKind::Tower | EntityKind::Base)
    }

    /// Scenery, in the sense that nothing fights it and nothing collides with it.
    pub fn is_ethereal(self) -> bool {
        matches!(self, EntityKind::Zone | EntityKind::Projectile)
    }
}

/// The numbers an entity fights with.
///
/// Deliberately flat and copyable: item and buff contributions are resolved into a fresh `Stats`
/// each tick rather than mutating a stored one, so a buff expiring can never leak a permanent
/// bonus — the bug that every stat system grows if the base and the total share a field.
#[derive(Clone, Copy, Debug)]
pub struct Stats {
    pub max_hp: Fx,
    pub move_speed: Fx,
    pub attack_damage: Fx,
    pub attack_range: Fx,
    /// Ticks between attacks. An integer because the attack clock is the tick clock; a
    /// fractional interval would need its own accumulator and drift differently per machine.
    pub attack_interval: u32,
    pub armour: Fx,
    /// Ability power — added to the magic damage of anything this entity casts.
    pub magic_damage: Fx,
    /// What killing this is worth to the killer.
    pub bounty: Fx,
}

impl Stats {
    /// A structure that shoots: no movement, long reach, hits hard.
    pub fn tower() -> Stats {
        Stats {
            max_hp: Fx::from_int(1800),
            move_speed: Fx::ZERO,
            attack_damage: Fx::from_int(110),
            attack_range: Fx::from_int(700),
            attack_interval: 30,
            armour: Fx::from_int(15),
            magic_damage: Fx::ZERO,
            bounty: Fx::from_int(160),
        }
    }

    pub fn base() -> Stats {
        Stats {
            max_hp: Fx::from_int(4500),
            move_speed: Fx::ZERO,
            attack_damage: Fx::ZERO,
            attack_range: Fx::ZERO,
            attack_interval: u32::MAX,
            armour: Fx::from_int(10),
            magic_damage: Fx::ZERO,
            bounty: Fx::from_int(0),
        }
    }

    /// A melee hero's opening statline. Heroes differ from each other in abilities far more
    /// than in these numbers, which is why there are two of these rather than six.
    pub fn melee_hero() -> Stats {
        Stats {
            max_hp: Fx::from_int(760),
            move_speed: Fx::from_int(305),
            attack_damage: Fx::from_int(58),
            attack_range: Fx::from_int(150),
            attack_interval: 50,
            armour: Fx::from_int(4),
            magic_damage: Fx::ZERO,
            bounty: Fx::from_int(300),
        }
    }

    pub fn ranged_hero() -> Stats {
        Stats {
            max_hp: Fx::from_int(560),
            move_speed: Fx::from_int(295),
            attack_damage: Fx::from_int(46),
            attack_range: Fx::from_int(600),
            attack_interval: 52,
            armour: Fx::from_int(1),
            magic_damage: Fx::ZERO,
            bounty: Fx::from_int(300),
        }
    }

    /// Relay's drone. Faster and flimsier than a lane creep, and worth nothing to kill — a
    /// summon that paid a bounty would make Swarm a gift to the enemy carry.
    pub fn drone() -> Stats {
        Stats {
            max_hp: Fx::from_int(260),
            move_speed: Fx::from_int(380),
            attack_damage: Fx::from_int(24),
            attack_range: Fx::from_int(420),
            attack_interval: 26,
            armour: Fx::ZERO,
            magic_damage: Fx::ZERO,
            bounty: Fx::ZERO,
        }
    }

    pub fn melee_creep() -> Stats {
        Stats {
            max_hp: Fx::from_int(550),
            move_speed: Fx::from_int(325),
            attack_damage: Fx::from_int(21),
            attack_range: Fx::from_int(100),
            attack_interval: 30,
            armour: Fx::from_int(2),
            magic_damage: Fx::ZERO,
            bounty: Fx::from_int(38),
        }
    }
}

/// An autoattack travelling toward its target.
///
/// ## Why they travel at all
///
/// A ranged autoattack used to land the instant it was fired, which made Emberwitch's attack
/// indistinguishable from Ironclad's — the only difference was how long the hit line was. A
/// projectile with travel time is the whole of what makes a ranged hero *read* as ranged.
///
/// It also creates a real mechanic that did not exist before: a projectile whose target dies in
/// flight is **wasted**. Last-hitting at range now requires leading the creep's health rather
/// than reacting to it, which is the skill that separates a ranged carry from a melee one.
#[derive(Clone, Copy, Debug)]
pub struct Projectile {
    pub target: EntityId,
    pub source: Option<EntityId>,
    pub damage: Fx,
    /// World units per second.
    pub speed: Fx,
    /// Gives up rather than chasing forever — a target that blinks across the map should not be
    /// hit by an autoattack fired before it left.
    pub expires_tick: u32,
}

/// Attack range at or above which a hero is "ranged", and fires a projectile.
///
/// Comfortably above every melee statline and below every ranged one, so it never has to be
/// decided per hero.
pub const RANGED_THRESHOLD: i32 = 300;

/// What an entity is currently trying to do.
///
/// The distinction between [`Order::Attack`] and [`Order::Forced`] is load-bearing: Ironclad's
/// Taunt overrides a hero's orders without stunning them, and when it expires the player's own
/// order must still be there to return to. Collapsing the two would make a taunt permanently
/// erase whatever the player had queued.
#[derive(Clone, Copy, PartialEq, Eq, Debug, Default)]
pub enum Order {
    #[default]
    Idle,
    MoveTo(Vec2),
    Attack(EntityId),
    /// Walk the lane, engaging whatever is in reach on the way. Creeps and drones.
    PushLane,
    /// Keep walking in a direction until told otherwise.
    ///
    /// Distinct from [`Order::MoveTo`] rather than implemented as "move to a point far away",
    /// which is how a keyboard scheme is usually bolted onto a click-to-move game. The
    /// difference shows the moment a wall is involved: a far-off destination makes the hero
    /// slide along the wall toward a point they will never reach, while a *direction* simply
    /// stops when the way is blocked, which is what the key being held actually means.
    ///
    /// It is also far less network traffic — one message when the keys change, rather than a
    /// destination every few frames.
    MoveDirection(Vec2),
    /// Imposed, not chosen. Carries the tick it expires on.
    Forced {
        target: EntityId,
        until_tick: u32,
    },
}

/// A handle to an entity.
///
/// Carries a generation so that a stale id — Ghostuser's mark on someone who has since died and
/// respawned, a tether to a drone that expired — resolves to `None` rather than silently to
/// whoever now occupies that slot. Every long-lived reference in the game is one of these, so
/// the ABA problem is not hypothetical.
#[derive(Clone, Copy, PartialEq, Eq, Debug, Hash, PartialOrd, Ord)]
pub struct EntityId {
    index: u32,
    generation: u32,
}

impl EntityId {
    /// A never-valid id, for catalogue entries that name an entity they cannot yet know.
    /// See [`crate::ability::AbilityId::PLACEHOLDER_ENTITY`].
    pub const PLACEHOLDER: EntityId = EntityId {
        index: u32::MAX,
        generation: u32::MAX,
    };

    /// Flatten to a single integer for the wire.
    ///
    /// The generation rides along in the high half, so a client holding an id for something that
    /// died and was replaced will not match the new occupant either — the same guarantee the
    /// server has, extended across the network.
    pub fn to_net(self) -> u64 {
        ((self.generation as u64) << 32) | self.index as u64
    }
}

pub struct Entity {
    pub kind: EntityKind,
    pub team: Team,
    pub pos: Vec2,
    pub hp: Fx,
    pub stats: Stats,
    pub order: Order,
    /// Ticks until this can attack again.
    pub attack_cooldown: u32,
    /// Who the last attack landed on. Overclock's Spool Up reads it; resetting on a target
    /// switch is the whole of that passive.
    pub last_attack_target: Option<EntityId>,
    pub modifiers: Vec<Attached>,
    /// Index into the lane's waypoint list, for anything under [`Order::PushLane`].
    pub lane_leg: usize,
    /// Which lane this creep is walking. `None` for anything that is not in a lane.
    pub lane: Option<crate::map::LaneId>,
    /// Who summoned this, if anyone. Relay's drones — kill credit and the tether follow it.
    pub owner: Option<EntityId>,
    /// The tick a summon disappears on. `None` for anything permanent.
    pub expires_tick: Option<u32>,
    /// Where this stood over the last few seconds, newest last.
    ///
    /// **MOBA.md's second finding, made concrete.** Ghostuser's Backspace blinks to where he was
    /// three seconds ago, which means the sim has to *remember* — it is state, not a client-side
    /// nicety, and it has to survive serialization and a replay. Kept only for heroes: ninety
    /// positions each is nothing, and ninety for every creep in a five-lane wave is not.
    pub position_history: Vec<Vec2>,
    /// Who has damaged this recently, and when.
    ///
    /// The assist window. A kill in this genre is credited to whoever landed the last hit, but
    /// the four people who spent their cooldowns getting it there are the reason it happened —
    /// so everyone who contributed inside the window shares the credit. Kept per hero only, and
    /// pruned on read: an unbounded list of everyone who ever hit you is a leak on a long game.
    pub recent_attackers: Vec<(EntityId, u32)>,
    /// The tick this hero comes back on.
    ///
    /// **Also the "this death has been handled" flag**, which is the load-bearing half. Heroes
    /// are deliberately not despawned — a dead hero is one on a timer, and its id is held by
    /// scoreboards, marks and tethers — so the reap phase walks them every tick for as long as
    /// they lie there. Without a marker it re-killed the same hero thirty times a second: a
    /// death event, a bounty and a credited kill per tick.
    pub respawn_at: Option<u32>,
    /// The tick of the last action taken. Ghostuser's Idle reads it, which is why actions have
    /// to be observable as events rather than only as mutations.
    pub last_action_tick: u32,
    /// Structures that must fall before this one can be attacked.
    ///
    /// **The rule that makes three lanes a game rather than a race.** Without it the correct
    /// opening is to walk past every tower straight to the enemy base, and the lanes are
    /// scenery. Held as ids rather than as a tier number so the check is a lookup rather than a
    /// scan for "is anything with a lower tier still alive".
    pub guarded_by: Vec<EntityId>,

    /// Unspent gold. Heroes only.
    pub gold: Fx,
    /// Total experience earned. The level is derived from it rather than stored beside it —
    /// two fields that must agree is two fields that will eventually disagree.
    pub xp: u32,
    /// What is in the six inventory slots. Parallel to `abilities.slots[4..10]`, holding the
    /// item rather than the active, because an item without an active still occupies a slot.
    pub items: Vec<crate::item::ItemId>,
    /// Who last removed health from this. Read once, on death, to decide the kill credit —
    /// which is why it is a single id and not a damage log: last-hitting is the mechanic, and
    /// nothing in the game asks who dealt the *most*.
    pub last_damage_from: Option<EntityId>,

    pub mana: Fx,
    pub max_mana: Fx,
    /// The four ability slots, and their cooldown and toggle state.
    pub abilities: AbilitySlots,
    /// A cast or channel in progress. Cleared by a stun — see [`Sim::advance_casts`].
    pub casting: Option<Cast>,
    /// Displacement in progress. Separate from `order` because a dash is something happening
    /// *to* your position, not something you are choosing to do, and it must survive the order
    /// changing underneath it.
    pub dash: Option<Dash>,
    /// Set on [`EntityKind::Zone`] entities — Emberwitch's burning ground.
    pub zone: Option<Zone>,
    /// Set on [`EntityKind::Projectile`] entities.
    pub projectile: Option<Projectile>,
}

impl Entity {
    pub fn new(kind: EntityKind, team: Team, pos: Vec2, stats: Stats) -> Entity {
        Entity {
            kind,
            team,
            pos,
            hp: stats.max_hp,
            stats,
            order: Order::Idle,
            attack_cooldown: 0,
            last_attack_target: None,
            modifiers: Vec::new(),
            lane_leg: 0,
            lane: None,
            owner: None,
            expires_tick: None,
            position_history: Vec::new(),
            recent_attackers: Vec::new(),
            respawn_at: None,
            last_action_tick: 0,
            guarded_by: Vec::new(),
            gold: Fx::ZERO,
            xp: 0,
            items: Vec::new(),
            last_damage_from: None,
            mana: Fx::ZERO,
            max_mana: Fx::ZERO,
            abilities: AbilitySlots::default(),
            casting: None,
            dash: None,
            zone: None,
            projectile: None,
        }
    }

    /// A hero: full mana and the four ability slots filled.
    pub fn hero(
        team: Team,
        pos: Vec2,
        stats: Stats,
        mana: Fx,
        abilities: [AbilityId; 4],
    ) -> Entity {
        let mut e = Entity::new(EntityKind::Hero, team, pos, stats);
        e.mana = mana;
        e.max_mana = mana;
        e.abilities = AbilitySlots::new(abilities);
        e
    }

    /// The stats this entity actually fights with *right now*.
    ///
    /// Recomputed from the base every time rather than stored, which is the one discipline that
    /// keeps a buff expiring from leaving a permanent bonus behind — the bug every stat system
    /// grows the moment base and total share a field. It is a handful of adds over a short
    /// `Vec`, run a few hundred times a tick, and has never been the thing worth optimising.
    pub fn effective_stats(&self, tick: u32) -> Stats {
        let mut stats = self.stats;

        // Levels first, so item and buff percentages apply on top of the levelled base rather
        // than on the level-one one — otherwise a percentage buff would be worth less the
        // higher your level, which is backwards.
        if self.kind == EntityKind::Hero {
            let bonus = crate::level::bonus_for(self.level());
            stats.max_hp += bonus.max_hp;
            stats.attack_damage += bonus.attack_damage;
            stats.armour += bonus.armour;
        }

        let mut move_pct = Fx::ZERO;
        let mut attack_pct = Fx::ZERO;
        let slow_immune = self.has_status(tick, |m| matches!(m, Modifier::SlowImmune { .. }));

        for m in self
            .modifiers
            .iter()
            .filter(|a| !a.is_expired(tick))
            .map(|a| a.modifier)
        {
            match m {
                Modifier::Armour(bonus) => stats.armour += bonus,
                Modifier::MaxHealthFlat(bonus) => stats.max_hp += bonus,
                Modifier::MoveSpeedFlat(bonus) => stats.move_speed += bonus,
                Modifier::AttackDamageFlat(bonus) => stats.attack_damage += bonus,
                Modifier::MagicDamageFlat(bonus) => stats.magic_damage += bonus,
                // Slow immunity discards the reduction but keeps the haste. Jukebox's Encore is
                // "you cannot be slowed", not "your speed is frozen".
                Modifier::MoveSpeedPct { pct, .. } => {
                    if pct >= Fx::ZERO || !slow_immune {
                        move_pct += pct;
                    }
                }
                Modifier::AttackSpeedPct { pct, .. } => attack_pct += pct,
                _ => {}
            }
        }

        // Floored at 10% rather than at zero: a stack of slows that reached zero would be an
        // unbreakable root, which is a much stronger effect than any slow is priced as.
        let move_mult = (Fx::ONE + move_pct).max(Fx::ratio(1, 10));
        stats.move_speed = stats.move_speed * move_mult;

        let attack_mult = (Fx::ONE + attack_pct).max(Fx::ratio(1, 10));
        if stats.attack_interval != u32::MAX {
            let interval = Fx::from_int(stats.attack_interval as i32) / attack_mult;
            // At least one tick, or an entity with enough attack speed attacks infinitely often.
            stats.attack_interval = interval.floor_int().max(1) as u32;
        }
        stats
    }

    pub fn has_status(&self, tick: u32, pred: impl Fn(&Modifier) -> bool) -> bool {
        self.modifiers
            .iter()
            .any(|a| !a.is_expired(tick) && pred(&a.modifier))
    }

    /// Attach a modifier, replacing any live one of the same kind from the same source.
    ///
    /// The replace is what makes a toggle safe to re-apply every tick and what makes a refresh
    /// a refresh rather than a stack. Two modifiers of the same kind from *different* sources
    /// still coexist, which is why the source is part of the match and not just the kind.
    pub fn attach(&mut self, attached: Attached, tick: u32) {
        let same = |a: &Attached| {
            a.source == attached.source
                && core::mem::discriminant(&a.modifier)
                    == core::mem::discriminant(&attached.modifier)
                && !a.is_expired(tick)
        };
        if let Some(existing) = self.modifiers.iter_mut().find(|a| same(a)) {
            *existing = attached;
        } else {
            self.modifiers.push(attached);
        }
    }

    /// Drop every live modifier contributed by one ability. Turning a toggle off.
    pub fn detach_from(&mut self, source: AbilityId) {
        self.modifiers.retain(|a| a.source != Some(source));
    }

    /// Stunned entities do not act at all — no orders, no movement, no attacks, no casts.
    pub fn is_stunned(&self, tick: u32) -> bool {
        self.has_status(tick, |m| matches!(m, Modifier::Stun { .. }))
    }

    /// Silenced entities cannot cast, but attack and move normally.
    pub fn is_silenced(&self, tick: u32) -> bool {
        self.has_status(tick, |m| matches!(m, Modifier::Silence { .. }))
    }

    /// Dead and waiting to come back. Not on the map, in every sense that matters.
    pub fn is_dead(&self) -> bool {
        self.respawn_at.is_some()
    }

    /// Off the map entirely: no orders, no movement, no attacks, and untargetable.
    pub fn is_banished(&self, tick: u32) -> bool {
        self.has_status(tick, |m| matches!(m, Modifier::Banished { .. }))
    }

    /// Invisible to the other team. Enforced by the snapshot builder, not by the renderer.
    pub fn is_stealthed(&self, tick: u32) -> bool {
        self.has_status(tick, |m| matches!(m, Modifier::Stealth { .. }))
    }

    /// Anything that stops this from acting at all.
    pub fn is_incapacitated(&self, tick: u32) -> bool {
        self.is_stunned(tick) || self.is_banished(tick) || self.is_dead()
    }

    /// This hero's level, derived from its experience.
    pub fn level(&self) -> u32 {
        crate::level::level_for_xp(self.xp)
    }

    /// Reclassify as a creep. Exists for tests that need a non-hero body in a specific place —
    /// Shield Charge's "rides through creeps, stops on heroes" rule cannot be tested without
    /// one, and a full creep spawn would put it at the lane's start instead.
    pub fn kind_to_creep(&mut self) {
        self.kind = EntityKind::Creep;
    }

    #[inline]
    pub fn is_alive(&self) -> bool {
        self.hp > Fx::ZERO
    }
}

struct Slot {
    generation: u32,
    entity: Option<Entity>,
}

/// The world's entities.
///
/// A generational arena over a `Vec`. Iteration order is slot order, which is identical on every
/// machine that has applied the same commands — the property the whole sim rests on.
#[derive(Default)]
pub struct Entities {
    slots: Vec<Slot>,
    /// Slots freed by a death, newest first. Reusing them keeps the arena from growing without
    /// bound over a match's worth of creep waves.
    free: Vec<u32>,
}

impl Entities {
    pub fn new() -> Entities {
        Entities::default()
    }

    pub fn spawn(&mut self, entity: Entity) -> EntityId {
        if let Some(index) = self.free.pop() {
            let slot = &mut self.slots[index as usize];
            slot.entity = Some(entity);
            return EntityId {
                index,
                generation: slot.generation,
            };
        }
        let index = self.slots.len() as u32;
        self.slots.push(Slot {
            generation: 0,
            entity: Some(entity),
        });
        EntityId {
            index,
            generation: 0,
        }
    }

    /// Remove an entity, invalidating every id that pointed at it.
    pub fn despawn(&mut self, id: EntityId) {
        let Some(slot) = self.slots.get_mut(id.index as usize) else {
            return;
        };
        if slot.generation != id.generation {
            return;
        }
        slot.entity = None;
        // Bumping on free rather than on reuse means an id handed out before the despawn is
        // invalid immediately, not merely once something else moves in.
        slot.generation = slot.generation.wrapping_add(1);
        self.free.push(id.index);
    }

    pub fn get(&self, id: EntityId) -> Option<&Entity> {
        let slot = self.slots.get(id.index as usize)?;
        if slot.generation != id.generation {
            return None;
        }
        slot.entity.as_ref()
    }

    pub fn get_mut(&mut self, id: EntityId) -> Option<&mut Entity> {
        let slot = self.slots.get_mut(id.index as usize)?;
        if slot.generation != id.generation {
            return None;
        }
        slot.entity.as_mut()
    }

    pub fn iter(&self) -> impl Iterator<Item = (EntityId, &Entity)> {
        self.slots.iter().enumerate().filter_map(|(i, slot)| {
            slot.entity.as_ref().map(|e| {
                (
                    EntityId {
                        index: i as u32,
                        generation: slot.generation,
                    },
                    e,
                )
            })
        })
    }

    /// Every live id, in slot order.
    ///
    /// The tick phases collect this first and then walk it, because they need `&mut` access to
    /// entities while looking at others — which an iterator borrowing the arena cannot give.
    pub fn ids(&self) -> Vec<EntityId> {
        self.iter().map(|(id, _)| id).collect()
    }

    pub fn len(&self) -> usize {
        self.slots.iter().filter(|s| s.entity.is_some()).count()
    }

    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn creep(team: Team) -> Entity {
        Entity::new(EntityKind::Creep, team, Vec2::ZERO, Stats::melee_creep())
    }

    #[test]
    fn neutrals_fight_nobody() {
        assert!(Team::Blue.hostile_to(Team::Red));
        assert!(!Team::Blue.hostile_to(Team::Blue));
        assert!(!Team::Neutral.hostile_to(Team::Blue));
        assert!(!Team::Blue.hostile_to(Team::Neutral));
    }

    #[test]
    fn a_stale_id_does_not_resolve_to_its_replacement() {
        // The ABA case, and the reason ids carry a generation. Ghostuser's mark outlives its
        // target by design; it must not silently transfer to the next creep in that slot.
        let mut entities = Entities::new();
        let first = entities.spawn(creep(Team::Blue));
        entities.despawn(first);
        let second = entities.spawn(creep(Team::Red));

        assert_eq!(
            first.index, second.index,
            "the slot should have been reused"
        );
        assert!(entities.get(first).is_none());
        assert_eq!(entities.get(second).map(|e| e.team), Some(Team::Red));
    }

    #[test]
    fn despawning_twice_is_harmless() {
        let mut entities = Entities::new();
        let id = entities.spawn(creep(Team::Blue));
        entities.despawn(id);
        entities.despawn(id);
        assert_eq!(entities.len(), 0);
    }

    #[test]
    fn iteration_is_in_slot_order() {
        let mut entities = Entities::new();
        let ids: Vec<_> = (0..8).map(|_| entities.spawn(creep(Team::Blue))).collect();
        assert_eq!(entities.ids(), ids);
    }
}
