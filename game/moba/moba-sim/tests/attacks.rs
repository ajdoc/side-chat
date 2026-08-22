//! Autoattacks: how they travel, and what you may shoot.

use moba_proto::TICK_HZ;
use moba_sim::ability::heroes;
use moba_sim::damage::DamageKind;
use moba_sim::entity::{EntityId, EntityKind, Stats, Team};
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

fn creep(sim: &mut Sim, team: Team, pos: Vec2) -> EntityId {
    let mut stats = Stats::melee_creep();
    stats.move_speed = Fx::ZERO;
    stats.attack_damage = Fx::ZERO;
    let id = sim.spawn_hero(team, stats);
    sim.entities.get_mut(id).unwrap().kind_to_creep();
    sim.entities.get_mut(id).unwrap().pos = pos;
    id
}

fn bolts(sim: &Sim) -> usize {
    sim.entities
        .iter()
        .filter(|(_, e)| e.kind == EntityKind::Projectile)
        .count()
}

fn hp(sim: &Sim, id: EntityId) -> Fx {
    sim.entities.get(id).map(|e| e.hp).unwrap_or(Fx::ZERO)
}

// ── Projectiles ─────────────────────────────────────────────────────────────────────────────

#[test]
fn a_ranged_attack_travels_and_a_melee_one_lands_at_once() {
    // The distinction that makes a ranged hero read as ranged. Before this both resolved
    // instantly and differed only in how long the hit line was drawn.
    let mut ranged = arena();
    let witch = ranged.spawn_named_hero(Team::Blue, heroes::emberwitch());
    ranged.entities.get_mut(witch).unwrap().pos = at(1000, 1000);
    let victim = creep(&mut ranged, Team::Red, at(1500, 1000));
    let before = hp(&ranged, victim);

    ranged.step(&[Command::Attack {
        hero: witch,
        target: victim,
    }]);
    assert_eq!(
        bolts(&ranged),
        1,
        "a ranged attack did not put anything in the air"
    );
    assert_eq!(
        hp(&ranged, victim),
        before,
        "a ranged attack landed instantly"
    );

    for _ in 0..TICK_HZ {
        ranged.step(&[]);
        if hp(&ranged, victim) < before {
            break;
        }
    }
    assert!(hp(&ranged, victim) < before, "the projectile never arrived");

    let mut melee = arena();
    let ironclad = melee.spawn_named_hero(Team::Blue, heroes::ironclad());
    melee.entities.get_mut(ironclad).unwrap().pos = at(1000, 1000);
    let victim = creep(&mut melee, Team::Red, at(1080, 1000));
    let before = hp(&melee, victim);

    melee.step(&[Command::Attack {
        hero: ironclad,
        target: victim,
    }]);
    assert_eq!(bolts(&melee), 0, "a melee attack fired a projectile");
    assert!(
        hp(&melee, victim) < before,
        "a melee attack did not land at once"
    );
}

#[test]
fn a_projectile_whose_target_dies_in_flight_is_wasted() {
    // The mechanic this creates: last-hitting at range means leading the creep's health rather
    // than reacting to it. A shot fired at something already about to die is a shot thrown away.
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    sim.entities.get_mut(witch).unwrap().pos = at(1000, 1000);
    let victim = creep(&mut sim, Team::Red, at(1500, 1000));

    sim.step(&[Command::Attack {
        hero: witch,
        target: victim,
    }]);
    assert_eq!(bolts(&sim), 1);

    // Something else finishes it while the bolt is still travelling.
    let mut events = Vec::new();
    sim.deal_damage(
        None,
        victim,
        Fx::from_int(9999),
        DamageKind::Pure,
        &mut events,
    );
    sim.step(&[]);

    for _ in 0..TICK_HZ {
        sim.step(&[]);
    }
    assert_eq!(bolts(&sim), 0, "the projectile is still chasing a corpse");
}

#[test]
fn a_projectile_gives_up_rather_than_chasing_forever() {
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    sim.entities.get_mut(witch).unwrap().pos = at(1000, 1000);
    let victim = creep(&mut sim, Team::Red, at(1500, 1000));

    sim.step(&[Command::Attack {
        hero: witch,
        target: victim,
    }]);
    // Teleport the target far enough that the bolt would never catch it in its lifetime.
    sim.entities.get_mut(victim).unwrap().pos = at(5900, 5900);

    for _ in 0..TICK_HZ * 4 {
        sim.step(&[]);
    }
    assert_eq!(bolts(&sim), 0, "a projectile outlived its target's escape");
}

#[test]
fn nothing_can_attack_or_be_blocked_by_a_projectile() {
    // A bullet is scenery. If it were a valid target, heroes would autoattack the arrows flying
    // past them.
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    sim.entities.get_mut(witch).unwrap().pos = at(1000, 1000);
    let victim = creep(&mut sim, Team::Red, at(1500, 1000));
    sim.step(&[Command::Attack {
        hero: witch,
        target: victim,
    }]);

    let bolt = sim
        .entities
        .iter()
        .find(|(_, e)| e.kind == EntityKind::Projectile)
        .map(|(id, _)| id)
        .expect("no projectile");

    // Nobody's team is hostile to it, so nothing will ever acquire it.
    let enemy = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    let bolt_team = sim.entities.get(bolt).unwrap().team;
    assert!(
        !sim.entities.get(enemy).unwrap().team.hostile_to(bolt_team),
        "a projectile is a legal target"
    );
}

#[test]
fn a_projectile_is_sent_to_clients_so_it_can_be_drawn() {
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    sim.entities.get_mut(witch).unwrap().pos = at(1000, 1000);
    let victim = creep(&mut sim, Team::Red, at(1500, 1000));
    sim.step(&[Command::Attack {
        hero: witch,
        target: victim,
    }]);

    let snapshot = sim.snapshot(Team::Blue, Some(witch), &[]);
    assert!(
        snapshot
            .entities
            .iter()
            .any(|e| e.kind == moba_proto::NetKind::Projectile),
        "the projectile never reached the client"
    );
}

// ── Denying ─────────────────────────────────────────────────────────────────────────────────

#[test]
fn you_cannot_deny_a_healthy_creep() {
    // Without the health rule a laner could deny an entire wave from the instant it spawned,
    // which is not skill expression — it is deleting the lane.
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    let mine = creep(&mut sim, Team::Blue, at(1080, 1000));
    let full = hp(&sim, mine);

    let mut events = Vec::new();
    sim.deal_damage(
        Some(hero),
        mine,
        Fx::from_int(50),
        DamageKind::Physical,
        &mut events,
    );
    assert_eq!(
        hp(&sim, mine),
        full,
        "a full-health friendly creep took damage"
    );
}

#[test]
fn you_can_deny_a_wounded_one() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    let mine = creep(&mut sim, Team::Blue, at(1080, 1000));

    // Hurt it past the threshold with something that is not the denier.
    let max = sim.entities.get(mine).unwrap().stats.max_hp;
    sim.entities.get_mut(mine).unwrap().hp = max * Fx::ratio(4, 10);
    let wounded = hp(&sim, mine);

    let mut events = Vec::new();
    sim.deal_damage(
        Some(hero),
        mine,
        Fx::from_int(50),
        DamageKind::Physical,
        &mut events,
    );
    assert!(
        hp(&sim, mine) < wounded,
        "a wounded friendly creep could not be denied"
    );
}

#[test]
fn the_deny_rule_does_not_protect_enemy_creeps() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(hero).unwrap().pos = at(1000, 1000);
    let theirs = creep(&mut sim, Team::Red, at(1080, 1000));
    let full = hp(&sim, theirs);

    let mut events = Vec::new();
    sim.deal_damage(
        Some(hero),
        theirs,
        Fx::from_int(50),
        DamageKind::Physical,
        &mut events,
    );
    assert!(
        hp(&sim, theirs) < full,
        "a healthy enemy creep was protected by the deny rule"
    );
}

#[test]
fn the_deny_rule_does_not_protect_friendly_heroes_from_area_effects() {
    // The rule is about creeps. Extending it to heroes would make friendly fire impossible,
    // which is not what it is for.
    let mut sim = arena();
    let a = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let b = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    let full = hp(&sim, b);

    let mut events = Vec::new();
    sim.deal_damage(
        Some(a),
        b,
        Fx::from_int(40),
        DamageKind::Magical,
        &mut events,
    );
    assert!(
        hp(&sim, b) < full,
        "a friendly hero was protected by the creep deny rule"
    );
}
