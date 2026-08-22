//! Fog of war, now that terrain blocks it.
//!
//! Fog is the only real anti-cheat this game has: an enemy the server never sends cannot be
//! drawn by any client, hacked or not. That makes these tests security tests as much as gameplay
//! ones — a leak here is not a visual glitch, it is a map hack that works.

use moba_sim::ability::heroes;
use moba_sim::entity::{EntityKind, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::map::Map;
use moba_sim::sim::{MatchConfig, Sim};

fn at(x: i32, y: i32) -> Vec2 {
    Vec2::new(Fx::from_int(x), Fx::from_int(y))
}

fn three_lane() -> Sim {
    Sim::new(
        Map::three_lane(),
        MatchConfig {
            team_size: 1,
            wave_interval: 0,
            creeps_per_wave: 0,
        },
    )
}

/// Put a hero from each team somewhere, and report whether Blue can see Red.
fn can_see(sim: &mut Sim, watcher_at: Vec2, target_at: Vec2) -> bool {
    let watcher = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let target = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    sim.entities.get_mut(watcher).unwrap().pos = watcher_at;
    sim.entities.get_mut(target).unwrap().pos = target_at;

    let snapshot = sim.snapshot(Team::Blue, Some(watcher), &[]);
    let seen = snapshot.entities.iter().any(|e| e.id == target.to_net());

    sim.entities.despawn(watcher);
    sim.entities.despawn(target);
    seen
}

// ── Line of sight ───────────────────────────────────────────────────────────────────────────

#[test]
fn a_wall_between_two_heroes_blocks_vision_even_at_point_blank_range() {
    // The whole point of terrain fog: standing on the other side of a jungle wall is *hiding*,
    // not merely being far away. Without this, ganking from the jungle is impossible because
    // everyone can see you coming through the rock.
    let mut sim = three_lane();

    // Two points either side of a jungle wall, 500 units apart — well inside a hero's
    // 1800-unit vision, so plain radius fog would see straight through it.
    //
    // These coordinates were found by probing the generated terrain rather than guessed at. An
    // earlier version of this test picked a point that looked like it should be jungle and was
    // not, and passed for the wrong reason until the assertion below caught it.
    let a = at(4650, 1700);
    let b = at(5150, 1700);
    assert!(
        sim.map.terrain.is_blocked(at(4900, 1700)),
        "the fixture assumes a wall between these two points and there is not one"
    );
    assert!(!sim.map.terrain.is_blocked(a) && !sim.map.terrain.is_blocked(b));
    assert!(
        !can_see(&mut sim, a, b),
        "vision passed straight through a jungle wall"
    );
}

#[test]
fn the_same_two_points_in_the_open_do_see_each_other() {
    // The control. Without it the test above would pass just as happily if vision were broken
    // everywhere.
    let mut sim = three_lane();
    let a = at(3000, 3000);
    let b = at(3400, 2600);
    assert!(!sim.map.terrain.is_blocked(a) && !sim.map.terrain.is_blocked(b));
    assert!(
        can_see(&mut sim, a, b),
        "two heroes standing together in mid could not see each other"
    );
}

#[test]
fn stepping_out_of_the_jungle_reveals_you() {
    let mut sim = three_lane();
    let watcher = at(3000, 3000);
    assert!(
        !can_see(&mut sim, watcher, at(2200, 2200)),
        "someone in the jungle was visible"
    );
    assert!(
        can_see(&mut sim, watcher, at(3300, 2700)),
        "someone in the open was not visible"
    );
}

#[test]
fn vision_is_blocked_the_same_way_along_every_angle() {
    // The failure this catches is the nasty one: a naive "sample the line every N units" walk
    // skips a cell whenever the line clips a corner, so vision leaks through walls at particular
    // angles and no others. Rare, angle-dependent, and indistinguishable from cheating when a
    // player reports it.
    let sim = three_lane();
    let centre = at(2100, 2100); // deep jungle
    let mut leaks = 0;

    for step in 0..64 {
        // A ring of watchers around a point well inside the rock. None of them should see it.
        let angle = step as f32 * std::f32::consts::TAU / 64.0;
        let watcher = at(
            2100 + (angle.cos() * 900.0) as i32,
            2100 + (angle.sin() * 900.0) as i32,
        );
        if sim.map.terrain.is_blocked(watcher) {
            continue; // the watcher is itself in rock; not an interesting case
        }
        if sim.map.terrain.line_is_clear(watcher, centre) {
            leaks += 1;
        }
    }
    assert_eq!(
        leaks, 0,
        "vision leaked into solid rock from {leaks} of 64 angles"
    );
}

#[test]
fn a_unit_pressed_against_a_wall_is_still_visible_from_the_open_side() {
    // Endpoints must not block, or anyone shoved into a wall by a knockback would vanish.
    let map = Map::three_lane();
    let open = at(3000, 3000);
    // Walk outward from mid until we find the first blocked cell — that is the wall face.
    let mut probe = open;
    for _ in 0..40 {
        probe = Vec2::new(probe.x, probe.y + Fx::from_int(100));
        if map.terrain.is_blocked(probe) {
            break;
        }
    }
    assert!(
        map.terrain.is_blocked(probe),
        "never found a wall to press against"
    );
    assert!(
        map.terrain.line_is_clear(open, probe),
        "a unit standing in the wall face was invisible from open ground beside it"
    );
}

// ── What fog does and does not hide ──────────────────────────────────────────────────────────

#[test]
fn enemy_structures_are_always_visible() {
    // The genre's rule: an enemy tower is a landmark and appears on the minimap from the first
    // second. It also avoids a building fading in and out as a creep walks past it.
    let mut sim = three_lane();
    let watcher = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(watcher).unwrap().pos = at(900, 5100);

    let snapshot = sim.snapshot(Team::Blue, Some(watcher), &[]);
    let enemy_structures = snapshot
        .entities
        .iter()
        .filter(|e| {
            matches!(
                e.kind,
                moba_proto::NetKind::Tower | moba_proto::NetKind::Base
            )
        })
        .filter(|e| e.team == moba_proto::NetTeam::Red)
        .count();

    let actual = sim
        .entities
        .iter()
        .filter(|(_, e)| e.kind.is_structure() && e.team == Team::Red)
        .count();
    assert_eq!(
        enemy_structures, actual,
        "some enemy structures were fogged out"
    );
}

#[test]
fn your_own_team_is_visible_through_walls() {
    // You always know where your teammates are. Applying line of sight to your own side would
    // make the map unreadable for no gain.
    let mut sim = three_lane();
    let a = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let b = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    sim.entities.get_mut(a).unwrap().pos = at(2400, 3000);
    sim.entities.get_mut(b).unwrap().pos = at(2400, 3600);

    let snapshot = sim.snapshot(Team::Blue, Some(a), &[]);
    assert!(
        snapshot.entities.iter().any(|e| e.id == b.to_net()),
        "a teammate behind a wall was fogged out"
    );
}

#[test]
fn a_creep_gives_vision_too_not_just_heroes() {
    // Wave vision is how lanes stay lit without anyone standing in them, and it is why pushing
    // a lane is also scouting it.
    let mut sim = three_lane();
    let watcher = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    sim.entities.get_mut(watcher).unwrap().pos = at(900, 5100);

    sim.spawn_wave(Team::Blue);
    for _ in 0..moba_proto::TICK_HZ * 20 {
        sim.step(&[]);
    }

    let target = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    // Stand next to a Blue creep, far from the Blue hero.
    let creep_pos = sim
        .entities
        .iter()
        .find(|(_, e)| e.kind == EntityKind::Creep && e.team == Team::Blue)
        .map(|(_, e)| e.pos)
        .expect("no creeps on the map");
    sim.entities.get_mut(target).unwrap().pos = creep_pos;

    let snapshot = sim.snapshot(Team::Blue, Some(watcher), &[]);
    assert!(
        snapshot.entities.iter().any(|e| e.id == target.to_net()),
        "an enemy standing on top of our own creep wave was invisible"
    );
}

#[test]
fn fog_costs_little_enough_to_run_every_snapshot() {
    // Line of sight turned a radius check into a grid walk, run per candidate per source. This
    // is a floor under that: a full lane of creeps plus heroes, snapshotted for both teams, at
    // the rate the server actually does it.
    let mut sim = three_lane();
    for _ in 0..3 {
        sim.spawn_wave(Team::Blue);
        sim.spawn_wave(Team::Red);
    }
    let blue = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    for _ in 0..moba_proto::TICK_HZ * 10 {
        sim.step(&[]);
    }

    let population = sim.entities.iter().count();
    assert!(
        population > 30,
        "the fixture only produced {population} entities"
    );

    let start = std::time::Instant::now();
    for _ in 0..100 {
        let _ = sim.snapshot(Team::Blue, Some(blue), &[]);
        let _ = sim.snapshot(Team::Red, None, &[]);
    }
    let elapsed = start.elapsed();

    // 100 iterations is five seconds of real snapshots at 20Hz. A debug build is perhaps 20×
    // slower than release, so the bar is deliberately loose — it is here to catch an accidental
    // quadratic, not to measure anything.
    assert!(
        elapsed.as_millis() < 2000,
        "200 snapshots over {population} entities took {elapsed:?}"
    );
}
