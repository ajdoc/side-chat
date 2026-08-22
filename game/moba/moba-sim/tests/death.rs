//! Dying, and coming back.
//!
//! A MOBA's death is not a removal — it is a timer, and the length of that timer is most of what
//! makes a teamfight worth winning. Getting it wrong in either direction breaks the game: no
//! timer at all and there is no cost to dying, an eternal one and a single mistake ends your
//! match.

use moba_proto::TICK_HZ;
use moba_sim::ability::heroes;
use moba_sim::damage::DamageKind;
use moba_sim::entity::{EntityId, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::map::Map;
use moba_sim::sim::{Command, Event, MatchConfig, Sim};

fn arena() -> Sim {
    Sim::new(
        Map::empty(),
        MatchConfig {
            team_size: 1,
            wave_interval: 0,
            creeps_per_wave: 0,
        },
    )
}

fn at(x: i32, y: i32) -> Vec2 {
    Vec2::new(Fx::from_int(x), Fx::from_int(y))
}

fn kill(sim: &mut Sim, victim: EntityId, killer: Option<EntityId>) -> Vec<Event> {
    let mut events = Vec::new();
    sim.deal_damage(
        killer,
        victim,
        Fx::from_int(99_999),
        DamageKind::Pure,
        &mut events,
    );
    events.extend(sim.step(&[]));
    events
}

#[test]
fn a_death_happens_once_however_long_the_hero_stays_dead() {
    // The bug this closes: heroes are deliberately not despawned — a dead hero is one on a
    // respawn timer, and its id is held by scoreboards, marks and tethers. But the reap phase
    // walks everything that is not alive, so without a "already handled" state it re-killed the
    // same hero thirty times a second: a death event per tick, a bounty per tick, and a kill
    // credited to the killer per tick. A single kill was worth thousands of gold.
    let mut sim = arena();
    let killer = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let victim = sim.spawn_named_hero(Team::Red, heroes::emberwitch());

    let mut deaths = kill(&mut sim, victim, Some(killer))
        .iter()
        .filter(|e| matches!(e, Event::Died { .. }))
        .count();

    for _ in 0..TICK_HZ * 3 {
        deaths += sim
            .step(&[])
            .iter()
            .filter(|e| matches!(e, Event::Died { .. }))
            .count();
    }

    assert_eq!(deaths, 1, "one death produced {deaths} death events");
    assert_eq!(
        sim.scores.get(killer).kills,
        1,
        "one kill was credited more than once"
    );
    assert_eq!(sim.scores.get(victim).deaths, 1);
}

#[test]
fn a_kill_pays_its_bounty_once_and_not_per_tick() {
    let mut sim = arena();
    let killer = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let victim = sim.spawn_named_hero(Team::Red, heroes::emberwitch());
    let before = sim.entities.get(killer).unwrap().gold;

    kill(&mut sim, victim, Some(killer));
    for _ in 0..TICK_HZ * 3 {
        sim.step(&[]);
    }

    let earned = sim.entities.get(killer).unwrap().gold - before;
    // A hero bounty plus three seconds of passive income, and nowhere near ninety of them.
    assert!(
        earned < Fx::from_int(500),
        "a single kill paid {earned:?}, which is a bounty per tick"
    );
}

#[test]
fn a_dead_hero_cannot_walk_around() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    kill(&mut sim, hero, None);
    let where_it_fell = sim.entities.get(hero).unwrap().pos;

    for _ in 0..TICK_HZ {
        sim.step(&[Command::MoveTo {
            hero,
            pos: at(3000, 3000),
        }]);
    }

    assert_eq!(
        sim.entities.get(hero).unwrap().pos,
        where_it_fell,
        "a corpse walked off under its own orders"
    );
}

#[test]
fn a_dead_hero_is_not_sent_to_anyone() {
    // They are not on the map. A body drawn where someone died is a target players will click.
    let mut sim = arena();
    let watcher = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let victim = sim.spawn_named_hero(Team::Red, heroes::emberwitch());
    sim.entities.get_mut(watcher).unwrap().pos = at(1000, 1000);
    sim.entities.get_mut(victim).unwrap().pos = at(1100, 1000);

    assert!(sim
        .snapshot(Team::Blue, Some(watcher), &[])
        .entities
        .iter()
        .any(|e| e.id == victim.to_net()));

    kill(&mut sim, victim, Some(watcher));

    assert!(
        !sim.snapshot(Team::Blue, Some(watcher), &[])
            .entities
            .iter()
            .any(|e| e.id == victim.to_net()),
        "a dead hero was still being drawn"
    );
}

#[test]
fn a_dead_hero_cannot_be_hit_again() {
    let mut sim = arena();
    let killer = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let victim = sim.spawn_named_hero(Team::Red, heroes::emberwitch());
    kill(&mut sim, victim, Some(killer));

    let mut events = Vec::new();
    sim.deal_damage(
        Some(killer),
        victim,
        Fx::from_int(100),
        DamageKind::Magical,
        &mut events,
    );

    assert!(events.is_empty(), "a corpse took damage");
    assert_eq!(
        sim.scores.get(killer).kills,
        1,
        "hitting a corpse credited another kill"
    );
}

#[test]
fn a_hero_comes_back_at_their_own_spawn_with_full_health() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(3000, 3000);
    let full = sim.entities.get(hero).unwrap().stats.max_hp;

    kill(&mut sim, hero, None);
    assert!(!sim.entities.get(hero).unwrap().is_alive());

    // Long enough for any respawn timer this early in a match.
    for _ in 0..TICK_HZ * 60 {
        sim.step(&[]);
    }

    let e = sim.entities.get(hero).expect("the hero never came back");
    assert!(e.is_alive(), "the hero is still dead a minute later");
    assert_eq!(e.hp.floor_int(), full.floor_int(), "came back hurt");
    assert_eq!(
        e.pos,
        sim.map.spawn_for(Team::Blue),
        "came back where they died"
    );
}

#[test]
fn a_respawning_hero_loses_whatever_was_on_them() {
    // Coming back still stunned, or still burning, would make a death cost more than the timer
    // says it does.
    use moba_sim::damage::{Attached, Modifier};
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let tick = sim.tick;
    sim.entities.get_mut(hero).unwrap().attach(
        Attached::new(Modifier::Stun {
            until_tick: tick + TICK_HZ * 600,
        }),
        tick,
    );

    kill(&mut sim, hero, None);
    for _ in 0..TICK_HZ * 60 {
        sim.step(&[]);
    }

    assert!(
        !sim.entities.get(hero).unwrap().is_stunned(sim.tick),
        "the hero respawned still stunned"
    );
}

#[test]
fn the_respawn_wait_grows_as_the_match_goes_on() {
    // Eight seconds at minute one and eight seconds at minute forty would make late deaths
    // meaningless — a teamfight is won by the enemy being *away*, and if they are back before
    // you have walked anywhere, winning it bought nothing.
    let early = Sim::respawn_delay_ticks(0);
    let later = Sim::respawn_delay_ticks(TICK_HZ * 60 * 20);
    assert!(later > early, "the respawn timer never grows");
    // And it is capped, or a death at minute sixty is the end of someone's game.
    assert!(
        Sim::respawn_delay_ticks(TICK_HZ * 60 * 60) <= TICK_HZ * 70,
        "the respawn timer grows without bound"
    );
}
