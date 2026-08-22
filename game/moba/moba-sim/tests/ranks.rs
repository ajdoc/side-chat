//! Skill points: spending them, and what a rank is worth.
//!
//! The decision the genre is built on. Whether you max your wave clear or your escape first is
//! most of what separates two players on the same hero, and none of it exists without a rule
//! about what a point may be spent on.

use moba_sim::ability::heroes;
use moba_sim::entity::{EntityId, Team};
use moba_sim::fixed::Fx;
use moba_sim::level::{self, ranks};
use moba_sim::map::Map;
use moba_sim::sim::{MatchConfig, Sim};

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

fn hero_at_level(sim: &mut Sim, level: u32) -> EntityId {
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().xp = level::xp_for_level(level);
    hero
}

fn rank(sim: &Sim, hero: EntityId, slot: usize) -> u8 {
    sim.entities.get(hero).unwrap().abilities.state[slot].rank
}

#[test]
fn a_new_hero_has_one_point_and_no_ranks() {
    let mut sim = arena();
    let hero = hero_at_level(&mut sim, 1);
    let e = sim.entities.get(hero).unwrap();

    assert_eq!(
        e.abilities.unspent_points(1),
        1,
        "a level-one hero cannot learn anything"
    );
    assert_eq!(rank(&sim, hero, 0), 0);
}

#[test]
fn spending_a_point_raises_one_ability_and_consumes_the_point() {
    let mut sim = arena();
    let hero = hero_at_level(&mut sim, 1);

    sim.learn(hero, 0).expect("could not spend the first point");
    assert_eq!(rank(&sim, hero, 0), 1);
    assert_eq!(
        sim.entities.get(hero).unwrap().abilities.unspent_points(1),
        0
    );

    // Nothing left to spend.
    assert!(
        sim.learn(hero, 1).is_err(),
        "a second point appeared from nowhere"
    );
}

#[test]
fn an_unlearned_ability_cannot_be_cast() {
    // Rank zero replaced the old flat "ultimates unlock at six" special case, so every ability
    // now answers the same question the same way.
    use moba_sim::ability::{CastRefusal, Target};
    use moba_sim::sim::{Command, Event};

    let mut sim = arena();
    let hero = hero_at_level(&mut sim, 1);

    let events = sim.step(&[Command::CastAbility {
        hero,
        slot: 0,
        target: Target::Point(moba_sim::fixed::Vec2::ZERO),
    }]);
    assert!(
        events.iter().any(|e| matches!(
            e,
            Event::CastRefused {
                reason: CastRefusal::NotLearned,
                ..
            }
        )),
        "an unlearned ability was cast"
    );

    sim.learn(hero, 0).unwrap();
    let events = sim.step(&[Command::CastAbility {
        hero,
        slot: 0,
        target: Target::Point(moba_sim::fixed::Vec2::new(Fx::from_int(500), Fx::ZERO)),
    }]);
    assert!(
        !events.iter().any(|e| matches!(
            e,
            Event::CastRefused {
                reason: CastRefusal::NotLearned,
                ..
            }
        )),
        "a learned ability was still refused"
    );
}

#[test]
fn a_basic_ability_cannot_be_maxed_before_leaving_lane() {
    // Without a cap, pouring every point into one ability would be strictly better than any
    // other choice — and a choice with a right answer is not a choice.
    assert_eq!(ranks::cap(0, 1), 1);
    assert_eq!(ranks::cap(0, 2), 1);
    assert_eq!(ranks::cap(0, 3), 2);
    assert_eq!(ranks::cap(0, 7), ranks::MAX_BASIC);
    assert_eq!(
        ranks::cap(0, 18),
        ranks::MAX_BASIC,
        "a basic ability went past its cap"
    );
}

#[test]
fn the_ultimate_follows_six_eleven_sixteen() {
    let slot = level::ULTIMATE_SLOT;
    assert_eq!(
        ranks::cap(slot, 5),
        0,
        "the ultimate was available before level six"
    );
    assert_eq!(ranks::cap(slot, 6), 1);
    assert_eq!(ranks::cap(slot, 10), 1);
    assert_eq!(ranks::cap(slot, 11), 2);
    assert_eq!(ranks::cap(slot, 16), ranks::MAX_ULTIMATE);
    assert_eq!(ranks::cap(slot, 18), ranks::MAX_ULTIMATE);
}

#[test]
fn a_point_cannot_be_spent_past_the_cap() {
    let mut sim = arena();
    let hero = hero_at_level(&mut sim, 4);

    sim.learn(hero, 0).unwrap();
    sim.learn(hero, 0).unwrap();
    // Level four caps a basic at two.
    assert!(
        sim.learn(hero, 0).is_err(),
        "an ability went past its level cap"
    );
    assert_eq!(rank(&sim, hero, 0), 2);

    // The remaining points still go elsewhere.
    sim.learn(hero, 1).unwrap();
    sim.learn(hero, 2).unwrap();
    assert_eq!(
        sim.entities.get(hero).unwrap().abilities.unspent_points(4),
        0
    );
}

#[test]
fn the_ultimate_cannot_be_learned_early_however_many_points_are_saved() {
    // Saving points is a real strategy; buying the ultimate at level three with them is not.
    let mut sim = arena();
    let hero = hero_at_level(&mut sim, 5);
    assert!(
        sim.learn(hero, level::ULTIMATE_SLOT).is_err(),
        "the ultimate was learned at level five"
    );

    sim.entities.get_mut(hero).unwrap().xp = level::xp_for_level(6);
    sim.learn(hero, level::ULTIMATE_SLOT)
        .expect("the ultimate could not be learned at six");
}

#[test]
fn a_higher_rank_hits_harder() {
    assert_eq!(
        ranks::scale(0),
        Fx::ZERO,
        "an unlearned ability still did something"
    );
    assert!(ranks::scale(2) > ranks::scale(1));
    assert!(ranks::scale(4) > ranks::scale(3));
    // Roughly double at max, not ten times.
    assert!(ranks::scale(4) < Fx::from_int(3));
}

#[test]
fn points_are_derived_from_levels_so_they_cannot_drift() {
    // Stored separately, unspent points and ranks would eventually disagree — the same reason
    // level is derived from experience rather than kept beside it.
    let mut sim = arena();
    let hero = hero_at_level(&mut sim, 6);
    assert_eq!(
        sim.entities.get(hero).unwrap().abilities.unspent_points(6),
        6
    );

    for slot in [0, 1, 2] {
        sim.learn(hero, slot).unwrap();
    }
    assert_eq!(
        sim.entities.get(hero).unwrap().abilities.unspent_points(6),
        3
    );
}

#[test]
fn the_snapshot_carries_ranks_caps_and_points() {
    let mut sim = arena();
    let hero = hero_at_level(&mut sim, 6);
    sim.learn(hero, 0).unwrap();
    sim.learn(hero, 0).unwrap();

    let own = sim
        .snapshot(Team::Blue, Some(hero), &[])
        .own
        .expect("no own block");
    assert_eq!(own.ranks[0], 2);
    assert_eq!(own.ranks[1], 0);
    assert_eq!(own.rank_caps[0], ranks::cap(0, 6));
    assert_eq!(own.rank_caps[level::ULTIMATE_SLOT], 1);
    assert_eq!(own.skill_points, 4);
}
