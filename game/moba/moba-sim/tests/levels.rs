//! Experience and levels in play.

use moba_proto::TICK_HZ;
use moba_sim::ability::{heroes, Target};
use moba_sim::damage::DamageKind;
use moba_sim::entity::{EntityId, Stats, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::level;
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

fn creep(sim: &mut Sim, team: Team, pos: Vec2) -> EntityId {
    let mut stats = Stats::melee_creep();
    stats.move_speed = Fx::ZERO;
    stats.attack_damage = Fx::ZERO;
    let id = sim.spawn_hero(team, stats);
    sim.entities.get_mut(id).unwrap().kind_to_creep();
    sim.entities.get_mut(id).unwrap().pos = pos;
    id
}

fn kill(sim: &mut Sim, victim: EntityId) {
    let mut events = Vec::new();
    sim.deal_damage(
        None,
        victim,
        Fx::from_int(99_999),
        DamageKind::Pure,
        &mut events,
    );
    sim.step(&[]);
}

fn xp(sim: &Sim, hero: EntityId) -> u32 {
    sim.entities.get(hero).unwrap().xp
}

#[test]
fn standing_near_a_dying_creep_earns_experience_without_last_hitting_it() {
    // The rule the whole laning phase rests on: gold rewards the last hit, experience rewards
    // being there. A support who never last-hits still levels.
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::jukebox());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    let victim = creep(&mut sim, Team::Red, at(1200, 1000));

    assert_eq!(xp(&sim, hero), 0);
    kill(&mut sim, victim);
    assert!(
        xp(&sim, hero) > 0,
        "a hero standing over a dying creep earned nothing"
    );
}

#[test]
fn experience_does_not_reach_across_the_map() {
    let mut sim = arena();
    let near = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let far = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    sim.entities.get_mut(near).unwrap().pos = at(1000, 1000);
    sim.entities.get_mut(far).unwrap().pos = at(5000, 5000);
    let victim = creep(&mut sim, Team::Red, at(1100, 1000));

    kill(&mut sim, victim);
    assert!(xp(&sim, near) > 0);
    assert_eq!(
        xp(&sim, far),
        0,
        "experience reached a hero on the far side of the map"
    );
}

#[test]
fn two_heroes_sharing_a_wave_level_slower_than_one_soloing_it() {
    // Split, not duplicated — which is the entire reason anyone goes to a lane alone.
    let solo = {
        let mut sim = arena();
        let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
        sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
        let victim = creep(&mut sim, Team::Red, at(1100, 1000));
        kill(&mut sim, victim);
        xp(&sim, hero)
    };

    let shared = {
        let mut sim = arena();
        let a = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
        let b = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
        sim.entities.get_mut(a).unwrap().pos = at(1000, 1000);
        sim.entities.get_mut(b).unwrap().pos = at(1050, 1000);
        let victim = creep(&mut sim, Team::Red, at(1100, 1000));
        kill(&mut sim, victim);
        xp(&sim, a)
    };

    assert!(
        shared < solo,
        "sharing a lane cost nothing: {shared} against {solo}"
    );
}

#[test]
fn your_own_creeps_dying_teach_you_nothing() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    let mine = creep(&mut sim, Team::Blue, at(1100, 1000));

    kill(&mut sim, mine);
    assert_eq!(
        xp(&sim, hero),
        0,
        "a hero levelled off its own dying creeps"
    );
}

#[test]
fn a_dead_hero_earns_nothing_while_waiting_to_respawn() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    kill(&mut sim, hero);

    let victim = creep(&mut sim, Team::Red, at(1050, 1000));
    kill(&mut sim, victim);

    assert_eq!(
        xp(&sim, hero),
        0,
        "a corpse earned experience for a creep it did not see"
    );
}

#[test]
fn levelling_makes_a_hero_tougher_and_tops_it_up_rather_than_diluting_it() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);

    let base = sim.entities.get(hero).unwrap().effective_stats(sim.tick);
    let fraction_before = {
        let e = sim.entities.get(hero).unwrap();
        e.hp.raw() as f64 / base.max_hp.raw() as f64
    };

    // Enough creeps to cross at least one level boundary.
    for i in 0..6 {
        let victim = creep(&mut sim, Team::Red, at(1050 + i * 10, 1000));
        kill(&mut sim, victim);
    }

    let e = sim.entities.get(hero).unwrap();
    let now = e.effective_stats(sim.tick);
    assert!(e.level() > 1, "six creeps did not produce a level");
    assert!(now.max_hp > base.max_hp, "levelling granted no health");
    assert!(
        now.attack_damage > base.attack_damage,
        "levelling granted no damage"
    );

    let fraction_after = e.hp.raw() as f64 / now.max_hp.raw() as f64;
    assert!(
        fraction_after >= fraction_before - 0.01,
        "levelling left the hero at a smaller fraction of a longer bar"
    );
}

#[test]
fn the_ultimate_is_locked_until_level_six() {
    // An ultimate available at minute zero is a different game — every hero's strongest ability
    // up for the least consequential fight of the match.
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);

    let events = sim.step(&[Command::CastAbility {
        hero,
        slot: level::ULTIMATE_SLOT,
        target: Target::None,
    }]);
    assert!(
        events.iter().any(|e| matches!(
            e,
            moba_sim::sim::Event::CastRefused {
                reason: moba_sim::ability::CastRefusal::NotLearned,
                ..
            }
        )),
        "a level-one hero cast its ultimate"
    );

    // The other three are available from the start.
    let events = sim.step(&[Command::CastAbility {
        hero,
        slot: 0,
        target: Target::Point(at(1500, 1000)),
    }]);
    assert!(
        !events
            .iter()
            .any(|e| matches!(e, moba_sim::sim::Event::CastRefused { .. })),
        "a basic ability was locked too"
    );

    // Level up and it unlocks.
    sim.entities.get_mut(hero).unwrap().xp = level::xp_for_level(level::ULTIMATE_LEVEL);
    for _ in 0..TICK_HZ * 80 {
        sim.step(&[]);
    }
    let events = sim.step(&[Command::CastAbility {
        hero,
        slot: level::ULTIMATE_SLOT,
        target: Target::None,
    }]);
    assert!(
        !events.iter().any(|e| matches!(
            e,
            moba_sim::sim::Event::CastRefused {
                reason: moba_sim::ability::CastRefusal::NotLearned,
                ..
            }
        )),
        "the ultimate stayed locked at level {}",
        level::ULTIMATE_LEVEL
    );
}

#[test]
fn a_hero_kill_is_worth_more_the_higher_level_the_victim_was() {
    let bounty = |level: u32| level::xp_bounty(moba_sim::entity::EntityKind::Hero, level);
    assert!(
        bounty(10) > bounty(2),
        "killing a fed hero was worth no more than killing a fresh one"
    );
}

#[test]
fn the_snapshot_carries_the_level_and_the_bar_behind_it() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    sim.entities.get_mut(hero).unwrap().xp = level::xp_for_level(4) + 10;

    let snapshot = sim.snapshot(Team::Blue, Some(hero), &[]);
    let own = snapshot.own.expect("no own block");
    assert_eq!(own.level, 4);
    assert_eq!(own.xp_into_level, 10);
    assert!(own.xp_for_next > 0);

    // And an enemy's level is visible on their entity, because knowing they are four levels up
    // is most of what decides whether to fight them.
    let drawn = snapshot
        .entities
        .iter()
        .find(|e| e.id == hero.to_net())
        .unwrap();
    assert_eq!(drawn.level, 4);
}
