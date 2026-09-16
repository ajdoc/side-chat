//! Which way a unit is pointing.
//!
//! Worth its own file because the renderer draws one of eight pictures from this, and the bug it
//! replaced was invisible in every way except on screen: facing used to be derived from the
//! current order, and `MoveTo` was the only order that carried a direction. A hero on WASD, a
//! creep pushing a lane, and anything attacking all reported exactly zero — so the sim was
//! "correct" and every unit in the game faced the same way.

use moba_proto::TICK_HZ;
use moba_sim::entity::{EntityId, Stats, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::map::Map;
use moba_sim::sim::{Command, MatchConfig, Sim};

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

fn place(sim: &mut Sim, id: EntityId, pos: Vec2) {
    sim.entities.get_mut(id).expect("entity vanished").pos = pos;
}

fn facing(sim: &Sim, id: EntityId) -> Vec2 {
    sim.entities.get(id).expect("entity vanished").facing
}

#[test]
fn walking_to_a_point_turns_you_toward_it() {
    let mut sim = arena();
    let hero = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hero, at(1000, 1000));

    sim.step(&[Command::MoveTo {
        hero,
        pos: at(2000, 1000),
    }]);

    let f = facing(&sim, hero);
    assert!(f.x > Fx::ZERO, "should face +x, got {f:?}");
    assert_eq!(f.y, Fx::ZERO);
}

#[test]
fn holding_a_direction_turns_you_too() {
    // The case that was broken. `MoveDirection` is what WASD sends, and it reported no facing at
    // all — so a hero could run all over the map without ever turning.
    let mut sim = arena();
    let hero = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hero, at(3000, 3000));

    sim.step(&[Command::MoveDirection {
        hero,
        dir: Vec2::new(Fx::ZERO, Fx::from_int(1)),
    }]);

    let f = facing(&sim, hero);
    assert!(f.y > Fx::ZERO, "should face +y, got {f:?}");
}

#[test]
fn facing_survives_stopping() {
    // Stop walking and you keep facing the way you were going. Snapping back to a default the
    // moment a key is released is the thing that reads as the character twitching.
    let mut sim = arena();
    let hero = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hero, at(3000, 3000));

    sim.step(&[Command::MoveDirection {
        hero,
        dir: Vec2::new(Fx::from_int(-1), Fx::ZERO),
    }]);
    let moving = facing(&sim, hero);
    assert!(moving.x < Fx::ZERO);

    sim.step(&[Command::Stop { hero }]);
    for _ in 0..TICK_HZ {
        sim.step(&[]);
    }
    assert_eq!(facing(&sim, hero), moving, "facing reset when the unit stopped");
}

#[test]
fn attacking_turns_you_toward_what_you_are_hitting() {
    let mut sim = arena();
    let hero = sim.spawn_hero(Team::Blue, Stats::ranged_hero());
    let victim = sim.spawn_hero(Team::Red, Stats::melee_hero());
    place(&mut sim, hero, at(3000, 3000));
    // Behind the hero, and within a ranged hero's 600-unit reach so no walking is needed.
    place(&mut sim, victim, at(3000, 2600));

    // Walk east first, so the facing has something wrong to be corrected from.
    sim.step(&[Command::MoveDirection {
        hero,
        dir: Vec2::new(Fx::from_int(1), Fx::ZERO),
    }]);
    assert!(facing(&sim, hero).x > Fx::ZERO);

    sim.step(&[Command::Attack {
        hero,
        target: victim,
    }]);
    for _ in 0..(TICK_HZ * 2) {
        sim.step(&[]);
        place(&mut sim, hero, at(3000, 3000));
        place(&mut sim, victim, at(3000, 2600));
    }

    let f = facing(&sim, hero);
    assert!(f.y < Fx::ZERO, "should have turned toward the target, got {f:?}");
}
