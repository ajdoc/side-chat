//! The tick.
//!
//! One `step` advances the world by exactly `1/TICK_HZ` of a second and returns everything that
//! happened, in order. It is the only entry point into the simulation, and it is pure: the same
//! `Sim` fed the same commands produces the same state and the same events on any machine.
//!
//! ## Phase order
//!
//! The order below is the design, not an accident of what was written first. Each phase runs to
//! completion for every entity before the next begins, which is what stops the outcome from
//! depending on arena order in the places it would otherwise:
//!
//! 1. **Expire** — modifiers whose tick has come are dropped first, so nothing resolves against
//!    a buff that ended on this tick.
//! 2. **Command** — player orders land. Before movement, so an order issued this tick takes
//!    effect this tick rather than feeling a frame late.
//! 3. **Spawn** — creep waves.
//! 4. **Acquire** — everything without an order picks a target.
//! 5. **Move** — everything with somewhere to be goes there.
//! 6. **Attack** — resolved *after* movement, so stepping out of range in the same tick you
//!    would have been hit actually saves you. The reverse order would make range feel a tick
//!    stale in the attacker's favour.
//! 7. **Reap** — the dead are removed, once, at the end. Killing during the attack phase would
//!    let a creep that died early in the phase not swing back, purely because of arena order.

use crate::ability::{Abilities, AbilityId, Target};
use crate::damage::{resolve, Attached, Damage, DamageKind, Modifier};
use crate::entity::{Entities, Entity, EntityId, EntityKind, Order, Stats, Team};
use crate::fixed::{Fx, Vec2};
use crate::map::Map;
use moba_proto::TICK_HZ;

/// How close a creep must get to a waypoint before it aims at the next one.
///
/// Must exceed one tick of movement or a creep overshoots and orbits the waypoint forever.
const WAYPOINT_REACHED: Fx = Fx::from_int(40);

/// How far from its path a creep will look for something to fight.
const AGGRO_RANGE: Fx = Fx::from_int(500);

/// What a player asked their hero to do.
#[derive(Clone, Copy, Debug)]
pub enum Command {
    MoveTo {
        hero: EntityId,
        pos: Vec2,
    },
    /// Walk this way until told otherwise. A zero vector stops.
    MoveDirection {
        hero: EntityId,
        dir: Vec2,
    },
    Attack {
        hero: EntityId,
        target: EntityId,
    },
    Stop {
        hero: EntityId,
    },
    /// Cast the ability in `slot` (0..4). A refusal is reported as an [`Event::CastRefused`]
    /// rather than dropped, so the client can say *why* nothing happened.
    CastAbility {
        hero: EntityId,
        slot: usize,
        target: Target,
    },
    BuyItem {
        hero: EntityId,
        item: crate::item::ItemId,
    },
    /// Spend a skill point on one ability.
    LearnAbility {
        hero: EntityId,
        slot: usize,
    },
}

/// Something that happened, in tick order.
///
/// The server forwards these to clients for effects and sound, and the headless replay test
/// asserts against them. They are facts, never rendered sentences — the same discipline
/// `app_activity` uses on the PHP side.
#[derive(Clone, Copy, Debug, PartialEq)]
pub enum Event {
    Damaged {
        source: Option<EntityId>,
        target: EntityId,
        amount: Fx,
    },
    Died {
        entity: EntityId,
        kind: EntityKind,
        team: Team,
    },
    StructureDestroyed {
        entity: EntityId,
        team: Team,
    },
    MatchEnded {
        winner: Team,
    },
    AbilityCast {
        entity: EntityId,
        ability: AbilityId,
        /// Where it was aimed. The caster's own position for a self-cast.
        at: Vec2,
    },
    /// A cast or channel was cut short — in practice, always by a stun.
    CastInterrupted {
        entity: EntityId,
        ability: AbilityId,
    },
    /// A hero was paid. `from` is what died to pay them.
    GoldGained {
        hero: EntityId,
        amount: Fx,
        from: EntityId,
    },
    /// A last hit landed on one's own creep, denying the enemy the gold. Emitted rather than
    /// silently doing nothing, because the *absence* of gold is the whole point and a client
    /// needs to show it happened.
    Denied {
        by: EntityId,
        victim: EntityId,
    },
    /// The order was understood and declined. Sent rather than dropped so a client can say
    /// *why* nothing happened instead of eating the click.
    CastRefused {
        entity: EntityId,
        slot: usize,
        reason: crate::ability::CastRefusal,
    },
    BuyRefused {
        hero: EntityId,
        item: crate::item::ItemId,
        reason: crate::item::BuyRefusal,
    },
}

/// Knobs the map does not own.
#[derive(Clone, Copy, Debug)]
pub struct MatchConfig {
    /// Players per side. **1 through 5.**
    ///
    /// A MOBA is a 5v5 game, but a 5v5 game is untestable by one person, and a mode that only
    /// works at full size cannot be played until it is finished. So the size is a parameter
    /// rather than a constant, and everything that scales with it — wave size, tower health,
    /// the gold curve — reads it from here.
    ///
    /// This is not only a testing affordance. 1v1 mid and 2v2 are real formats people play, and
    /// making the number configurable now is much cheaper than discovering later that five is
    /// baked into a dozen places.
    pub team_size: u8,
    /// Ticks between automatic creep waves. **Zero means manual** — nothing spawns unless
    /// [`Sim::spawn_wave`] is called, which is how tests build a deliberately lopsided lane
    /// without the sim carrying a test-only flag.
    pub wave_interval: u32,
    pub creeps_per_wave: u32,
}

impl Default for MatchConfig {
    fn default() -> MatchConfig {
        MatchConfig {
            team_size: 5,
            wave_interval: TICK_HZ * 30,
            creeps_per_wave: 6,
        }
    }
}

pub struct Sim {
    pub tick: u32,
    pub entities: Entities,
    pub map: Map,
    pub config: MatchConfig,
    /// The ability catalogue. Owned by the sim so that a test can, later, run a match against a
    /// modified table without a global.
    pub abilities: Abilities,
    pub items: crate::item::Items,
    /// Live barriers: where, how big, and the tick they lift on. Relay's Barrier mutates the
    /// terrain grid directly, and this is what remembers to put it back.
    pub(crate) barriers: Vec<(Vec2, Fx, u32)>,
    /// Kills, deaths, assists, gold and damage — everything the post-game screen shows.
    ///
    /// Derived from events the sim already produces rather than simulated separately, so it
    /// cannot become a second opinion about what happened. See `score.rs`.
    pub scores: crate::score::Scoreboard,
    winner: Option<Team>,
}

impl Sim {
    /// A fresh match: structures up, no creeps, tick zero.
    pub fn new(map: Map, config: MatchConfig) -> Sim {
        let mut entities = Entities::new();

        // Structures scale with the team size for the same reason waves do: a base sized for
        // five players is a twenty-minute chore for one.
        let scale = Fx::ratio(config.team_size.clamp(1, 5) as i32, 5);

        let mut placed: Vec<(EntityId, Option<crate::map::LaneId>, u8, bool)> = Vec::new();
        for (team, sites) in [
            (Team::Blue, &map.blue_structures),
            (Team::Red, &map.red_structures),
        ] {
            for site in sites {
                let (kind, mut stats) = if site.is_base {
                    (EntityKind::Base, Stats::base())
                } else {
                    (EntityKind::Tower, Stats::tower())
                };
                stats.max_hp = stats.max_hp * scale;
                let id = entities.spawn(Entity::new(kind, team, site.pos, stats));
                if let Some(e) = entities.get_mut(id) {
                    e.lane = site.lane;
                }
                placed.push((id, site.lane, site.tier, site.is_base));
            }
        }

        // Wire the guard chain. A tower is guarded by everything further out in its own lane;
        // a base is guarded by the *innermost* tower of every lane, so it opens up as soon as
        // one lane is fully broken rather than requiring all three.
        let count = placed.len();
        for index in 0..count {
            let (id, lane, tier, is_base) = placed[index];
            let team = entities.get(id).map(|e| e.team);
            let guards: Vec<EntityId> = placed
                .iter()
                .filter(|(other, other_lane, other_tier, other_base)| {
                    if *other == id || *other_base {
                        return false;
                    }
                    if entities.get(*other).map(|e| e.team) != team {
                        return false;
                    }
                    if is_base {
                        // Only the innermost tower of each lane stands in front of the base.
                        *other_tier == 1
                    } else {
                        *other_lane == lane && *other_tier < tier
                    }
                })
                .map(|(other, _, _, _)| *other)
                .collect();

            if let Some(e) = entities.get_mut(id) {
                e.guarded_by = guards;
            }
        }

        Sim {
            tick: 0,
            entities,
            map,
            config,
            abilities: Abilities::new(),
            items: crate::item::Items::new(),
            barriers: Vec::new(),
            scores: crate::score::Scoreboard::new(),
            winner: None,
        }
    }

    /// How long a hero stays dead, given how far into the match it is.
    ///
    /// ## Why it grows
    ///
    /// Eight seconds at minute one and eight seconds at minute forty would make a late teamfight
    /// worth nothing. A fight is won by the enemy being *away* — long enough to take an
    /// objective — and if they are back before you have walked anywhere, winning it bought you
    /// nothing at all. Every game in the genre scales the timer for this reason.
    ///
    /// Capped, because the other failure is worse: an uncapped timer means one mistake at minute
    /// sixty ends a player's match, and sitting out two minutes is not a game.
    pub fn respawn_delay_ticks(match_tick: u32) -> u32 {
        let minutes = match_tick / (TICK_HZ * 60);
        let seconds = (8 + minutes * 2).min(60);
        seconds * TICK_HZ
    }

    /// Bring back anyone whose timer has run out.
    ///
    /// A full reset rather than a health top-up: coming back still stunned, still burning, or
    /// still carrying a tether would make a death cost more than the timer says it does, and the
    /// timer is the whole price.
    fn respawn_heroes(&mut self) {
        let tick = self.tick;
        for id in self.entities.ids() {
            let due = self
                .entities
                .get(id)
                .and_then(|e| e.respawn_at)
                .is_some_and(|at| tick >= at);
            if !due {
                continue;
            }

            let spawn = self
                .entities
                .get(id)
                .map(|e| self.map.spawn_for(e.team))
                .unwrap_or(Vec2::ZERO);

            if let Some(e) = self.entities.get_mut(id) {
                e.respawn_at = None;
                e.hp = e.stats.max_hp;
                e.mana = e.max_mana;
                e.pos = spawn;
                e.order = Order::Idle;
                e.casting = None;
                e.dash = None;
                e.modifiers.clear();
                e.recent_attackers.clear();
                e.last_damage_from = None;
                e.attack_cooldown = 0;
                // The history would otherwise let a Backspace rewind through your own death,
                // back to where you were standing before it.
                e.position_history.clear();
            }
        }
    }

    /// Spend a skill point on an ability.
    ///
    /// Refused rather than clamped when there is nothing to spend or the rank is capped: a
    /// player who presses the button twice should be told the second press did nothing, not left
    /// wondering whether the point went somewhere.
    pub fn learn(
        &mut self,
        hero: EntityId,
        slot: usize,
    ) -> Result<(), crate::ability::CastRefusal> {
        use crate::ability::CastRefusal;
        use crate::level::ranks;

        let Some(entity) = self.entities.get(hero) else {
            return Err(CastRefusal::NoSuchAbility);
        };
        if entity.kind != EntityKind::Hero || slot >= crate::ability::HERO_SLOTS {
            return Err(CastRefusal::NoSuchAbility);
        }
        if entity.abilities.id(slot).is_none() {
            return Err(CastRefusal::NoSuchAbility);
        }

        let level = entity.level();
        if entity.abilities.unspent_points(level) == 0 {
            return Err(CastRefusal::NotLearned);
        }
        let current = entity.abilities.state[slot].rank;
        if current >= ranks::cap(slot, level) {
            return Err(CastRefusal::NotLearned);
        }

        if let Some(e) = self.entities.get_mut(hero) {
            e.abilities.state[slot].rank = current + 1;
        }
        Ok(())
    }

    /// Whether this entity may be attacked at all.
    ///
    /// Only structures ever answer `false`: a tower with a live tower in front of it, or a base
    /// with a live inner tower in every lane. **This is the rule that makes three lanes a game
    /// rather than a race** — without it the correct opening is to ignore the lanes entirely and
    /// walk to the enemy base.
    ///
    /// A base guarded by *all* lanes' inner towers would need every lane broken; guarded by any
    /// one of them, as here, means breaking a single lane opens it. That is the genre's rule and
    /// it is what stops a stalemate from lasting forever.
    pub fn is_attackable(&self, id: EntityId) -> bool {
        let Some(entity) = self.entities.get(id) else {
            return false;
        };
        if !entity.kind.is_structure() {
            return true;
        }
        // Nothing standing in front of it means it is open. Stated explicitly because the
        // base's rule below is an `any`, and `any` over an empty list is `false` — which would
        // make an unguarded base permanently invulnerable and the match unwinnable. That is
        // exactly what happened on the one-lane map, which has no inner towers to guard it.
        if entity.guarded_by.is_empty() {
            return true;
        }

        if entity.kind == EntityKind::Base {
            // Open as soon as *one* lane's inner tower is gone. Requiring all three would let a
            // team that is losing badly stall indefinitely by defending one lane.
            return entity
                .guarded_by
                .iter()
                .any(|guard| self.entities.get(*guard).is_none_or(|g| !g.is_alive()));
        }
        entity
            .guarded_by
            .iter()
            .all(|guard| self.entities.get(*guard).is_none_or(|g| !g.is_alive()))
    }

    /// The winning team, once there is one. `Some` means the match is over and further steps do
    /// nothing.
    pub fn winner(&self) -> Option<Team> {
        self.winner
    }

    /// Put a hero on the map at its team's spawn.
    pub fn spawn_hero(&mut self, team: Team, stats: Stats) -> EntityId {
        let pos = self.map.spawn_for(team);
        self.entities
            .spawn(Entity::new(EntityKind::Hero, team, pos, stats))
    }

    /// Put a real hero on the map: four ability slots and a mana pool.
    pub fn spawn_hero_with(
        &mut self,
        team: Team,
        stats: Stats,
        mana: Fx,
        abilities: [AbilityId; 4],
    ) -> EntityId {
        let pos = self.map.spawn_for(team);
        let id = self
            .entities
            .spawn(Entity::hero(team, pos, stats, mana, abilities));
        if let Some(e) = self.entities.get_mut(id) {
            e.gold = Fx::from_int(crate::economy::STARTING_GOLD);
        }
        id
    }

    /// Put one of the catalogue's heroes on the map.
    pub fn spawn_named_hero(&mut self, team: Team, hero: crate::ability::heroes::Hero) -> EntityId {
        self.spawn_hero_with(team, hero.stats, hero.mana, hero.abilities)
    }

    /// Creeps per wave at this match's size.
    ///
    /// Scaled rather than fixed: six creeps against one hero is a wave the hero cannot clear,
    /// and a 1v1 that the creeps win is not a game. Rounded up so a 1v1 still has a lane to
    /// contest rather than an empty corridor.
    pub fn wave_size(&self) -> u32 {
        let scaled =
            (self.config.creeps_per_wave * self.config.team_size.max(1) as u32).div_ceil(5);
        scaled.max(1)
    }

    /// Send one wave of creeps down the lane.
    ///
    /// Public because waves are a thing the match does *to itself* on a timer and a thing a test
    /// or a future game mode may want to do explicitly. Same code path either way.
    /// Send one wave down every lane the map has.
    pub fn spawn_wave(&mut self, team: Team) {
        let lanes: Vec<crate::map::LaneId> = self.map.lanes.iter().map(|l| l.id).collect();
        for lane in lanes {
            self.spawn_wave_in(team, lane);
        }
    }

    /// Send one wave down a single lane.
    pub fn spawn_wave_in(&mut self, team: Team, lane: crate::map::LaneId) {
        let origin = self.map.spawn_for(team);
        for i in 0..self.wave_size() {
            // Fan them out along the lane so they arrive in a column rather than stacked on one
            // point, which would make them a single target for splash later.
            let offset = Fx::from_int(i as i32 * 45);
            let pos = Vec2::new(origin.x + offset, origin.y - offset);
            let mut creep = Entity::new(EntityKind::Creep, team, pos, Stats::melee_creep());
            creep.order = Order::PushLane;
            creep.lane = Some(lane);
            self.entities.spawn(creep);
        }
    }

    /// Advance the world one tick.
    pub fn step(&mut self, commands: &[Command]) -> Vec<Event> {
        let mut events = Vec::new();
        if self.winner.is_some() {
            return events;
        }

        self.expire_modifiers();
        self.respawn_heroes();
        self.retire_barriers();
        self.expire_summons();
        self.record_positions();
        self.tick_abilities();
        self.tick_income();
        self.apply_commands(commands, &mut events);
        self.spawn_scheduled_waves();
        self.advance_casts(&mut events);
        self.acquire_targets();
        self.advance_dashes();
        self.move_entities();
        self.run_attacks(&mut events);
        self.advance_projectiles(&mut events);
        self.tick_zones(&mut events);
        self.tick_auras();
        self.reap(&mut events);

        self.tick += 1;
        events
    }

    /// Put an autoattack in the air.
    fn fire_projectile(&mut self, source: EntityId, target: EntityId, damage: Fx) {
        let Some(shooter) = self.entities.get(source) else {
            return;
        };
        let (pos, team) = (shooter.pos, shooter.team);

        let mut stats = Stats::base();
        stats.max_hp = Fx::ONE;
        stats.attack_damage = Fx::ZERO;
        stats.move_speed = Fx::ZERO;
        // Neutral, so nothing ever tries to shoot the bullet.
        let mut bolt = Entity::new(EntityKind::Projectile, Team::Neutral, pos, stats);
        bolt.projectile = Some(crate::entity::Projectile {
            target,
            source: Some(source),
            damage,
            speed: Fx::from_int(1400),
            // Two seconds is far longer than any autoattack needs at 1400 units per second, and
            // is here so a bolt aimed at something that blinks away dies rather than chasing.
            expires_tick: self.tick + TICK_HZ * 2,
        });
        let _ = team;
        self.entities.spawn(bolt);
    }

    /// Move everything in flight, and land what arrives.
    fn advance_projectiles(&mut self, events: &mut Vec<Event>) {
        let tick = self.tick;
        for id in self.entities.ids() {
            let Some((bolt, pos)) = self
                .entities
                .get(id)
                .and_then(|e| e.projectile.map(|p| (p, e.pos)))
            else {
                continue;
            };

            // Its target is gone, dead, or it has been in the air too long. A wasted shot, which
            // is the point.
            let target_pos = self
                .entities
                .get(bolt.target)
                .filter(|t| t.is_alive() && !t.is_dead())
                .map(|t| t.pos);
            let Some(target_pos) = target_pos else {
                self.entities.despawn(id);
                continue;
            };
            if tick >= bolt.expires_tick {
                self.entities.despawn(id);
                continue;
            }

            let to_target = target_pos - pos;
            let step = bolt.speed / Fx::from_int(TICK_HZ as i32);

            if to_target.len() <= step {
                // Arrived. Homing rather than ballistic: an autoattack in this genre does not
                // miss, it merely takes time, and a dodgeable autoattack would be a different
                // game entirely.
                self.deal_damage(
                    bolt.source,
                    bolt.target,
                    bolt.damage,
                    DamageKind::Physical,
                    events,
                );
                self.entities.despawn(id);
                continue;
            }

            if let Some(e) = self.entities.get_mut(id) {
                e.pos = pos + to_target.normalized().scale(step);
            }
        }
    }

    /// Lift barriers whose time is up.
    fn retire_barriers(&mut self) {
        let tick = self.tick;
        let expired: Vec<(Vec2, Fx)> = self
            .barriers
            .iter()
            .filter(|(_, _, until)| tick >= *until)
            .map(|(pos, radius, _)| (*pos, *radius))
            .collect();
        self.barriers.retain(|(_, _, until)| tick < *until);
        for (pos, radius) in expired {
            self.map.terrain.clear_disc_public(pos, radius);
        }
    }

    /// Remove summons whose lease has run out.
    ///
    /// They vanish rather than dying: a drone timing out is not a kill, and routing it through
    /// the death path would pay the enemy a bounty for waiting.
    fn expire_summons(&mut self) {
        let tick = self.tick;
        for id in self.entities.ids() {
            let expired = self
                .entities
                .get(id)
                .and_then(|e| e.expires_tick)
                .is_some_and(|until| tick >= until);
            if expired {
                self.entities.despawn(id);
            }
        }
    }

    /// Remember where every hero is standing.
    ///
    /// MOBA.md's second finding: Ghostuser's Backspace needs three seconds of history, so it is
    /// sim state and it is recorded every tick. Heroes only — ninety positions each is nothing,
    /// and ninety for every creep in three lanes of waves is not.
    fn record_positions(&mut self) {
        let window = (TICK_HZ * 3) as usize + 1;
        for id in self.entities.ids() {
            let Some(e) = self.entities.get_mut(id) else {
                continue;
            };
            if e.kind != EntityKind::Hero {
                continue;
            }
            e.position_history.push(e.pos);
            if e.position_history.len() > window {
                e.position_history.remove(0);
            }
        }
    }

    fn expire_modifiers(&mut self) {
        let tick = self.tick;
        for id in self.entities.ids() {
            if let Some(e) = self.entities.get_mut(id) {
                e.modifiers.retain(|m| !m.is_expired(tick));
            }
        }
    }

    fn apply_commands(&mut self, commands: &[Command], events: &mut Vec<Event>) {
        for command in commands {
            match *command {
                Command::MoveDirection { hero, dir } => {
                    if let Some(e) = self.entities.get_mut(hero) {
                        // A Taunt still overrides it, exactly as it overrides a click.
                        if !matches!(e.order, Order::Forced { .. }) {
                            e.order = if dir == Vec2::ZERO {
                                Order::Idle
                            } else {
                                Order::MoveDirection(dir.normalized())
                            };
                        }
                    }
                }
                Command::MoveTo { hero, pos } => {
                    if let Some(e) = self.entities.get_mut(hero) {
                        // A player order cannot override a Taunt. Ironclad's E is meaningless if
                        // the victim can simply click away from it.
                        if !matches!(e.order, Order::Forced { .. }) {
                            e.order = Order::MoveTo(pos);
                        }
                    }
                }
                Command::Attack { hero, target } => {
                    if let Some(e) = self.entities.get_mut(hero) {
                        if !matches!(e.order, Order::Forced { .. }) {
                            e.order = Order::Attack(target);
                        }
                    }
                }
                Command::Stop { hero } => {
                    if let Some(e) = self.entities.get_mut(hero) {
                        if !matches!(e.order, Order::Forced { .. }) {
                            e.order = Order::Idle;
                        }
                    }
                }
                Command::LearnAbility { hero, slot } => {
                    if let Err(reason) = self.learn(hero, slot) {
                        events.push(Event::CastRefused {
                            entity: hero,
                            slot,
                            reason,
                        });
                    }
                }
                Command::BuyItem { hero, item } => {
                    if let Err(reason) = self.buy(hero, item) {
                        events.push(Event::BuyRefused { hero, item, reason });
                    }
                }
                Command::CastAbility { hero, slot, target } => {
                    if let Err(reason) = self.try_cast(hero, slot, target, events) {
                        events.push(Event::CastRefused {
                            entity: hero,
                            slot,
                            reason,
                        });
                    }
                }
            }
        }
    }

    fn spawn_scheduled_waves(&mut self) {
        let interval = self.config.wave_interval;
        if interval == 0 || !self.tick.is_multiple_of(interval) {
            return;
        }
        self.spawn_wave(Team::Blue);
        self.spawn_wave(Team::Red);
    }

    fn acquire_targets(&mut self) {
        let tick = self.tick;
        for id in self.entities.ids() {
            let Some(e) = self.entities.get(id) else {
                continue;
            };

            // A Forced order that has run out returns the entity to its own devices rather than
            // leaving it locked on. See `Order::Forced`.
            if let Order::Forced { until_tick, .. } = e.order {
                if tick >= until_tick {
                    if let Some(e) = self.entities.get_mut(id) {
                        e.order = if e.kind == EntityKind::Creep {
                            Order::PushLane
                        } else {
                            Order::Idle
                        };
                    }
                }
                continue;
            }

            // A live, explicitly-chosen attack order is left alone; a stale one is dropped so
            // the entity can pick something else rather than standing still forever.
            if let Order::Attack(target) = e.order {
                if self.entities.get(target).is_some_and(|t| t.is_alive()) {
                    continue;
                }
                if let Some(e) = self.entities.get_mut(id) {
                    e.order = if e.kind == EntityKind::Creep {
                        Order::PushLane
                    } else {
                        Order::Idle
                    };
                }
                continue;
            }

            let is_structure = e.kind.is_structure();
            let pushing = matches!(e.order, Order::PushLane);
            if !is_structure && !pushing {
                continue;
            }

            // Structures reach as far as they shoot. Creeps look a little further than they can
            // hit, so they stop and engage rather than walking past something and being shot in
            // the back.
            let radius = if is_structure {
                e.stats.attack_range
            } else {
                AGGRO_RANGE
            };
            if let Some(target) = self.nearest_hostile(id, radius) {
                if let Some(e) = self.entities.get_mut(id) {
                    e.order = Order::Attack(target);
                }
            }
        }
    }

    /// The closest live enemy within `radius`.
    ///
    /// Ties break on the lower `EntityId`, which is arbitrary but *fixed* — two machines must
    /// pick the same creep when two are equidistant, and "whichever the iterator saw first" is
    /// only stable because the arena is ordered. Making the tiebreak explicit means it survives
    /// a future change to iteration.
    fn nearest_hostile(&self, id: EntityId, radius: Fx) -> Option<EntityId> {
        let source = self.entities.get(id)?;
        if !source.is_alive() {
            return None;
        }
        let limit = radius.sq();
        let mut best: Option<(EntityId, crate::fixed::Sq)> = None;

        for (other_id, other) in self.entities.iter() {
            if !other.is_alive() || !source.team.hostile_to(other.team) {
                continue;
            }
            // A tower still guarded by one further out is not a legal target, so nothing should
            // walk up to it and start swinging either.
            if other.kind.is_structure() && !self.is_attackable(other_id) {
                continue;
            }
            // Off the map, or invisible to this team. Neither can be walked up to and hit.
            if other.is_banished(self.tick) || other.is_stealthed(self.tick) || other.is_dead() {
                continue;
            }
            let dist_sq = (other.pos - source.pos).len_sq();
            if dist_sq > limit {
                continue;
            }
            match best {
                Some((best_id, best_dist))
                    if best_dist < dist_sq || (best_dist == dist_sq && best_id < other_id) => {}
                _ => best = Some((other_id, dist_sq)),
            }
        }
        best.map(|(id, _)| id)
    }

    fn move_entities(&mut self) {
        let tick = self.tick;
        // Captured up front so the borrow of `self.map` does not outlive into the mutable
        // entity access below.
        let terrain_blocks = |pos: Vec2| self.map.terrain.is_blocked(pos);
        for id in self.entities.ids() {
            let Some(e) = self.entities.get(id) else {
                continue;
            };
            if e.kind.is_structure() || e.kind.is_ethereal() {
                continue;
            }
            // A dash overrides ordinary movement rather than adding to it, and a stun stops
            // both. Casting roots you — that is what makes a cast time a real commitment.
            if e.dash.is_some() || e.is_incapacitated(tick) || e.casting.is_some() {
                continue;
            }
            let speed = e.effective_stats(tick).move_speed;
            if speed == Fx::ZERO {
                continue;
            }

            let (destination, advance_leg) = match e.order {
                Order::MoveTo(pos) => (Some(pos), false),
                // One step's worth ahead, recomputed every tick. The hero never "arrives", which
                // is the point — the order ends when the player lets go, not when a destination
                // is reached.
                Order::MoveDirection(dir) => (Some(e.pos + dir.scale(Fx::from_int(200))), false),
                Order::Attack(target) | Order::Forced { target, .. } => {
                    // Walk toward the target only until in range; then stand and swing.
                    match self.entities.get(target) {
                        Some(t) if (t.pos - e.pos).len_sq() > e.stats.attack_range.sq() => {
                            (Some(t.pos), false)
                        }
                        _ => (None, false),
                    }
                }
                Order::PushLane => match e.lane {
                    Some(lane) => {
                        let waypoints = self.map.lane_for(e.team, lane);
                        (waypoints.get(e.lane_leg).copied(), true)
                    }
                    // A creep with no lane has nowhere to be. Standing still is the honest
                    // result; picking a lane for it here would hide the bug that put it here.
                    None => (None, false),
                },
                Order::Idle => (None, false),
            };

            let Some(destination) = destination else {
                continue;
            };
            let blocked = &terrain_blocks;
            let Some(e) = self.entities.get_mut(id) else {
                continue;
            };

            let to_go = destination - e.pos;
            let step = speed / Fx::from_int(TICK_HZ as i32);

            if to_go.len_sq() <= WAYPOINT_REACHED.sq() {
                e.pos = destination;
                if advance_leg {
                    e.lane_leg += 1;
                }
                // Reaching a click-to-move destination clears the order; otherwise the entity
                // keeps "moving" to where it already stands and never goes idle.
                if matches!(e.order, Order::MoveTo(_)) {
                    e.order = Order::Idle;
                }
                continue;
            }

            // Terrain. A hero ordered into the jungle stops at its edge rather than walking
            // through it; sliding along one axis when the other is blocked is what keeps a unit
            // brushing a wall from sticking to it completely, which reads as the controls having
            // failed rather than as a wall being there.
            let direction = to_go.normalized();
            let wanted = e.pos + direction.scale(step);
            e.pos = if !blocked(wanted) {
                wanted
            } else {
                let along_x = Vec2::new(wanted.x, e.pos.y);
                let along_y = Vec2::new(e.pos.x, wanted.y);
                if !blocked(along_x) {
                    along_x
                } else if !blocked(along_y) {
                    along_y
                } else {
                    e.pos
                }
            };
        }
    }

    fn run_attacks(&mut self, events: &mut Vec<Event>) {
        for id in self.entities.ids() {
            let Some(e) = self.entities.get(id) else {
                continue;
            };
            if !e.is_alive() {
                continue;
            }

            if e.attack_cooldown > 0 {
                if let Some(e) = self.entities.get_mut(id) {
                    e.attack_cooldown -= 1;
                }
                continue;
            }

            let target = match e.order {
                Order::Attack(t) | Order::Forced { target: t, .. } => t,
                _ => continue,
            };
            let tick = self.tick;
            let effective = e.effective_stats(self.tick);
            let damage = effective.attack_damage;
            let range_sq = effective.attack_range.sq();
            let interval = effective.attack_interval;
            let has_kindle = e.abilities.has(crate::ability::ids::KINDLE);
            let has_spool_up = e.abilities.has(crate::ability::ids::SPOOL_UP);
            let previous_target = e.last_attack_target;
            if damage <= Fx::ZERO {
                continue;
            }

            let in_range = self
                .entities
                .get(target)
                .is_some_and(|t| t.is_alive() && (t.pos - e.pos).len_sq() <= range_sq);
            if !in_range {
                continue;
            }

            // Ranged attacks travel; melee land at once. That distinction is what makes a
            // ranged hero read as ranged, and it introduces a real mechanic with it — a
            // projectile whose target dies in flight is wasted, so last-hitting at range means
            // leading the creep's health rather than reacting to it.
            if effective.attack_range >= Fx::from_int(crate::entity::RANGED_THRESHOLD) {
                self.fire_projectile(id, target, damage);
            } else {
                self.deal_damage(Some(id), target, damage, DamageKind::Physical, events);
            }

            // Kindle: Emberwitch's autoattacks apply Heat. A passive with no cast path, which is
            // why it is read here from the slot list rather than run through the ability engine.
            if has_kindle {
                let tick = self.tick;
                if let Some(victim) = self.entities.get_mut(target) {
                    let existing = victim
                        .modifiers
                        .iter()
                        .filter(|a| !a.is_expired(tick))
                        .find_map(|a| match a.modifier {
                            Modifier::HeatStacks { stacks, .. } => Some(stacks),
                            _ => None,
                        })
                        .unwrap_or(0);
                    victim.attach(
                        Attached::from(
                            Modifier::HeatStacks {
                                stacks: (existing + 1).min(3),
                                until_tick: tick + TICK_HZ * 6,
                            },
                            crate::ability::ids::KINDLE,
                        ),
                        tick,
                    );
                }
            }

            if let Some(e) = self.entities.get_mut(id) {
                e.attack_cooldown = interval;
                e.last_attack_target = Some(target);
                e.last_action_tick = tick;
                // Attacking breaks stealth. Ghostuser's Idle says "any action breaks it", and
                // an autoattack is the action players will most expect to.
                e.modifiers
                    .retain(|a| !matches!(a.modifier, Modifier::Stealth { .. }));
            }

            // Overclock's Spool Up: consecutive attacks on the *same* target ramp attack speed,
            // and switching resets. Read from `last_attack_target`, which exists for this.
            if has_spool_up {
                let stacks = if previous_target == Some(target) {
                    self.entities
                        .get(id)
                        .and_then(|e| {
                            e.modifiers
                                .iter()
                                .filter(|a| !a.is_expired(tick))
                                .find_map(|a| match a.modifier {
                                    Modifier::AttackChain { stacks, .. } => Some(stacks),
                                    _ => None,
                                })
                        })
                        .unwrap_or(0)
                        .saturating_add(1)
                        .min(5)
                } else {
                    1
                };
                if let Some(e) = self.entities.get_mut(id) {
                    e.attach(
                        Attached::from(
                            Modifier::AttackChain {
                                stacks,
                                until_tick: tick + TICK_HZ * 3,
                            },
                            crate::ability::ids::SPOOL_UP,
                        ),
                        tick,
                    );
                    e.attach(
                        Attached::from(
                            Modifier::AttackSpeedPct {
                                pct: Fx::ratio(20, 100) * Fx::from_int(stacks as i32),
                                until_tick: tick + TICK_HZ * 3,
                            },
                            crate::ability::ids::SPOOL_UP,
                        ),
                        tick,
                    );
                }
            }
        }
    }

    /// Push one damage event through the pipeline and apply whatever it redirects.
    ///
    /// The depth cap is not paranoia: two Relays Linked to each other is a legal board state,
    /// and without a bound a single autoattack bounces between them until the stack overflows
    /// and the match process dies. Capping it means the last hop simply keeps its share.
    pub fn deal_damage(
        &mut self,
        source: Option<EntityId>,
        target: EntityId,
        amount: Fx,
        kind: DamageKind,
        events: &mut Vec<Event>,
    ) {
        self.deal_damage_inner(source, target, amount, kind, events, 0);
    }

    fn deal_damage_inner(
        &mut self,
        source: Option<EntityId>,
        target: EntityId,
        amount: Fx,
        kind: DamageKind,
        events: &mut Vec<Event>,
        depth: u32,
    ) {
        const MAX_REDIRECT_DEPTH: u32 = 4;

        let tick = self.tick;
        // Checked here as well as in targeting, because an ability's area selection does not go
        // through target acquisition — a Cinder dropped on a guarded tower would otherwise burn
        // it down from behind its own front line.
        if !self.is_attackable(target) {
            return;
        }
        // **The deny rule.** You may only finish your own creep once it is below half health.
        // Without it a laner could deny an entire wave from the instant it spawned, which is not
        // a skill expression — it is a way to delete the lane.
        if let (Some(attacker), Some(victim)) = (
            source.and_then(|s| self.entities.get(s)),
            self.entities.get(target),
        ) {
            let friendly_creep =
                victim.kind == EntityKind::Creep && !attacker.team.hostile_to(victim.team);
            if friendly_creep && victim.hp > victim.stats.max_hp * Fx::ratio(1, 2) {
                return;
            }
        }

        // A banished entity is off the map: an area effect that lands where they were standing
        // must not reach them.
        if self
            .entities
            .get(target)
            .is_some_and(|e| e.is_banished(tick) || e.is_dead())
        {
            return;
        }
        let Some(entity) = self.entities.get_mut(target) else {
            return;
        };
        if !entity.is_alive() {
            return;
        }

        let is_hero = entity.kind == EntityKind::Hero;
        let resolved = resolve(entity, Damage::new(source, amount, kind), tick);
        if resolved.dealt > Fx::ZERO {
            // Overwritten by every landing hit, read once on death. See `economy.rs`.
            entity.last_damage_from = source;

            // The assist window. Recorded on the victim rather than the attacker because it is
            // read once, on the victim's death, and storing it the other way would mean
            // scanning every hero to answer one question.
            if is_hero {
                if let Some(attacker) = source {
                    entity.recent_attackers.retain(|(id, _)| *id != attacker);
                    entity.recent_attackers.push((attacker, tick));
                    // Prune here rather than on a timer: the list only grows when someone is
                    // being hit, which is exactly when it is cheap to tidy.
                    let window = crate::score::ASSIST_WINDOW_TICKS;
                    entity
                        .recent_attackers
                        .retain(|(_, at)| tick.saturating_sub(*at) <= window);
                }
            }
            events.push(Event::Damaged {
                source,
                target,
                amount: resolved.dealt,
            });
        }

        if depth >= MAX_REDIRECT_DEPTH {
            return;
        }
        for (to, moved) in resolved.redirected {
            self.deal_damage_inner(source, to, moved, kind, events, depth + 1);
        }
    }

    /// Share a dying unit's experience among the enemy heroes standing near it.
    ///
    /// **Shared by presence, not by the last hit.** That single rule is what makes the laning
    /// phase work: gold rewards precision and experience rewards staying, so a support who never
    /// last-hits still levels, and a hero who wanders off falls behind whether or not they were
    /// farming somewhere else.
    ///
    /// Split rather than duplicated — two heroes sharing a wave level slower than one soloing
    /// it, which is the whole reason anyone ever goes to a lane alone.
    fn award_experience(&mut self, victim: EntityId) {
        let Some(dead) = self.entities.get(victim) else {
            return;
        };
        let (kind, team, pos) = (dead.kind, dead.team, dead.pos);
        let amount = crate::level::xp_bounty(kind, dead.level());
        if amount == 0 {
            return;
        }

        let radius = Fx::from_int(crate::level::XP_RADIUS).sq();
        let nearby: Vec<EntityId> = self
            .entities
            .iter()
            .filter(|(_, e)| {
                e.kind == EntityKind::Hero
                    && e.is_alive()
                    && !e.is_dead()
                    && team.hostile_to(e.team)
                    && (e.pos - pos).len_sq() <= radius
            })
            .map(|(id, _)| id)
            .collect();

        if nearby.is_empty() {
            return;
        }
        let each = amount / nearby.len() as u32;
        for hero in nearby {
            if let Some(e) = self.entities.get_mut(hero) {
                let before = e.level();
                e.xp += each;
                let after = e.level();
                if after > before {
                    // Levelling tops you up rather than leaving you at the same health on a
                    // longer bar — the level should feel like a reward, not a dilution.
                    let bonus = crate::level::bonus_for(after).max_hp
                        - crate::level::bonus_for(before).max_hp;
                    e.hp += bonus;
                    let mana = crate::level::bonus_for(after).max_mana
                        - crate::level::bonus_for(before).max_mana;
                    e.max_mana += mana;
                    e.mana += mana;
                }
            }
        }
    }

    /// Hand a dying target's unfinished Requiem back to whoever cast it.
    fn redirect_requiem(&mut self, victim: EntityId) {
        let tick = self.tick;
        let Some(dying) = self.entities.get(victim) else {
            return;
        };
        let remaining: Vec<(Fx, u32)> = dying
            .modifiers
            .iter()
            .filter(|a| a.source == Some(crate::ability::ids::REQUIEM) && !a.is_expired(tick))
            .filter_map(|a| match a.modifier {
                Modifier::Regen {
                    per_tick,
                    until_tick,
                } => Some((per_tick, until_tick)),
                _ => None,
            })
            .collect();
        if remaining.is_empty() {
            return;
        }

        // The nearest living Jukebox on the victim's own team. Held positionally rather than as
        // a stored caster id because the modifier does not carry one — and adding a caster to
        // every Regen to serve one ability would be the same over-generalisation the death
        // trigger itself avoided.
        let team = dying.team;
        let pos = dying.pos;
        let jukebox = self
            .entities
            .iter()
            .filter(|(_, e)| {
                e.team == team && e.is_alive() && e.abilities.has(crate::ability::ids::REQUIEM)
            })
            .min_by_key(|(_, e)| (e.pos - pos).len_sq())
            .map(|(id, _)| id);

        let Some(jukebox) = jukebox else { return };
        for (per_tick, until_tick) in remaining {
            if let Some(e) = self.entities.get_mut(jukebox) {
                e.attach(
                    Attached::from(
                        Modifier::Regen {
                            per_tick,
                            until_tick,
                        },
                        crate::ability::ids::REQUIEM,
                    ),
                    tick,
                );
            }
        }
    }

    /// Remove the dead and decide whether the match is over.
    fn reap(&mut self, events: &mut Vec<Event>) {
        let tick = self.tick;
        for id in self.entities.ids() {
            let Some(e) = self.entities.get(id) else {
                continue;
            };
            if e.is_alive() {
                continue;
            }
            // Already counted, and lying there waiting for the timer. Without this the reap
            // phase re-kills the same hero on every tick for as long as it stays dead.
            if e.is_dead() {
                continue;
            }
            let (kind, team) = (e.kind, e.team);

            // The scoreboard. Heroes only: a creep dying is gold, not a kill.
            if kind == EntityKind::Hero {
                let (killer, assists) = self
                    .entities
                    .get(id)
                    .map(|e| {
                        let window = crate::score::ASSIST_WINDOW_TICKS;
                        let helpers: Vec<EntityId> = e
                            .recent_attackers
                            .iter()
                            .filter(|(_, at)| tick.saturating_sub(*at) <= window)
                            .map(|(who, _)| *who)
                            // Only heroes take credit. A tower landing the last hit is a death
                            // with no killer, which is exactly what it should be.
                            .filter(|who| {
                                self.entities
                                    .get(*who)
                                    .is_some_and(|h| h.kind == EntityKind::Hero)
                            })
                            .collect();
                        let killer = e.last_damage_from.filter(|k| {
                            self.entities
                                .get(*k)
                                .is_some_and(|h| h.kind == EntityKind::Hero)
                        });
                        (killer, helpers)
                    })
                    .unwrap_or((None, Vec::new()));
                self.scores.record_kill(id, killer, &assists);
            }

            // Jukebox's Requiem: if the target dies while the heal-over-time is running, its
            // remainder goes to Jukebox instead. Checked here rather than through a callback
            // mechanism, because the reap phase already walks the dead and a subscription system
            // for exactly one ability is a system nobody else would use.
            self.redirect_requiem(id);

            self.award_experience(id);

            // Paid before the corpse leaves the arena — the only moment at which both the
            // bounty and the id of whoever landed the last hit are still readable.
            self.award_bounty(id, events);

            events.push(Event::Died {
                entity: id,
                kind,
                team,
            });
            if kind.is_structure() {
                events.push(Event::StructureDestroyed { entity: id, team });
            }
            if kind == EntityKind::Base && self.winner.is_none() {
                self.winner = Some(team.opponent());
                events.push(Event::MatchEnded {
                    winner: team.opponent(),
                });
            }

            // Heroes are not despawned — a dead hero is one on a respawn timer, and its id is
            // held by scoreboards, marks and tethers. Everything else leaves the arena.
            if kind == EntityKind::Hero {
                let comes_back = tick + Sim::respawn_delay_ticks(tick);
                if let Some(e) = self.entities.get_mut(id) {
                    e.respawn_at = Some(comes_back);
                    // Whatever they were doing is over.
                    e.order = Order::Idle;
                    e.casting = None;
                    e.dash = None;
                }
            } else {
                self.entities.despawn(id);
            }
        }
    }
}

/// A fixture that plays a whole match with no client attached.
///
/// This is the shape the determinism test will take once there is a second machine to compare
/// against: feed a command log, run to a conclusion, compare the events.
pub fn run_to_conclusion(sim: &mut Sim, max_ticks: u32) -> Vec<Event> {
    let mut all = Vec::new();
    for _ in 0..max_ticks {
        all.extend(sim.step(&[]));
        if sim.winner().is_some() {
            break;
        }
    }
    all
}
