//! The brush: who can see whom, from where.
//!
//! These are rules a player feels immediately and cannot describe when they are wrong — "I got
//! jumped by someone I should have seen" is a bug report nobody can act on. So they are pinned
//! here rather than left to be noticed in a match.

use moba_proto::TICK_HZ;
use moba_sim::entity::{EntityId, Stats, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::map::Map;
use moba_sim::sim::{MatchConfig, Sim};

fn arena() -> Sim {
    Sim::new(
        Map::three_lane(),
        MatchConfig {
            team_size: 1,
            wave_interval: 0,
            creeps_per_wave: 0,
        },
    )
}

/// The centre of some cell in patch `id`, found rather than written down: the patches are
/// authored in `map.rs`, and a literal here would be a second copy of them that goes stale the
/// first time one moves.
fn point_in_patch(map: &Map, id: u16) -> Vec2 {
    let cell = Fx::from_int(map.size) / Fx::from_int(64);
    let half = cell / Fx::from_int(2);
    let cells: Vec<_> = map
        .brush
        .cells()
        .into_iter()
        .filter(|(_, _, patch)| *patch == id)
        .collect();
    assert!(!cells.is_empty(), "no brush cells in patch {id}");
    // The middle of the list, which is well inside the disc rather than on its rim.
    let (cx, cy, _) = cells[cells.len() / 2];
    let pos = Vec2::new(
        cell * Fx::from_int(cx as i32) + half,
        cell * Fx::from_int(cy as i32) + half,
    );
    assert_eq!(map.brush.patch_at(pos), id);
    pos
}

fn place(sim: &mut Sim, id: EntityId, pos: Vec2) {
    sim.entities.get_mut(id).expect("entity vanished").pos = pos;
}

fn sees(sim: &Sim, team: Team, target: EntityId) -> bool {
    sim.snapshot(team, None, &[])
        .entities
        .iter()
        .any(|e| e.id == target.to_net())
}

#[test]
fn the_map_has_brush_and_it_is_symmetric() {
    let map = Map::three_lane();
    let cells = map.brush.cells();
    assert!(!cells.is_empty(), "the three-lane map should have brush");

    // The map is symmetric under transposing x and y, which swaps the two bases. Every brush
    // cell must have its transpose, or one team has cover the other does not.
    let all: std::collections::BTreeSet<(u16, u16)> =
        cells.iter().map(|(cx, cy, _)| (*cx, *cy)).collect();
    for (cx, cy) in &all {
        assert!(all.contains(&(*cy, *cx)), "cell ({cx},{cy}) has no mirror");
    }
}

#[test]
fn brush_is_walkable() {
    // A brush you cannot walk into is scenery. The patches carve their own clearing precisely
    // because the interior of this map is otherwise solid.
    let map = Map::three_lane();
    for id in 1..=2 {
        let pos = point_in_patch(&map, id);
        assert!(!map.terrain.is_blocked(pos), "patch {id} is inside a wall");
    }
}

#[test]
fn a_hero_in_brush_is_hidden_from_outside_it() {
    let mut sim = arena();
    let map = Map::three_lane();
    let hide = point_in_patch(&map, 1);

    let hider = sim.spawn_hero(Team::Red, Stats::melee_hero());
    let seeker = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hider, hide);
    // Right next to the brush, well inside a hero's 1800-unit sight, but not in it.
    place(&mut sim, seeker, hide + Vec2::new(Fx::from_int(500), Fx::ZERO));
    assert_eq!(map.brush.patch_at(hide + Vec2::new(Fx::from_int(500), Fx::ZERO)), 0);

    assert!(!sees(&sim, Team::Blue, hider), "brush did not hide anyone");
}

#[test]
fn standing_in_the_same_brush_reveals_them() {
    let mut sim = arena();
    let map = Map::three_lane();
    let hide = point_in_patch(&map, 1);

    let hider = sim.spawn_hero(Team::Red, Stats::melee_hero());
    let seeker = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hider, hide);
    place(&mut sim, seeker, hide);

    assert!(sees(&sim, Team::Blue, hider));
}

#[test]
fn a_different_patch_does_not_count() {
    // The rule is "the same brush", not "any brush". Otherwise a hero in one patch scouts every
    // other patch on the map from wherever they happen to be standing.
    let mut sim = arena();
    let map = Map::three_lane();
    let one = point_in_patch(&map, 1);
    let two = point_in_patch(&map, 2);
    assert_ne!(map.brush.patch_at(one), map.brush.patch_at(two));

    let hider = sim.spawn_hero(Team::Red, Stats::melee_hero());
    let seeker = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hider, one);
    place(&mut sim, seeker, two);

    assert!(!sees(&sim, Team::Blue, hider));
}

#[test]
fn open_ground_is_unaffected() {
    let mut sim = arena();
    let hider = sim.spawn_hero(Team::Red, Stats::melee_hero());
    let seeker = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    let spot = Vec2::new(Fx::from_int(3000), Fx::from_int(3000));
    place(&mut sim, hider, spot);
    place(&mut sim, seeker, spot + Vec2::new(Fx::from_int(400), Fx::ZERO));

    assert!(sees(&sim, Team::Blue, hider), "brush changed open-ground vision");
}

#[test]
fn acting_from_cover_gives_you_away() {
    let mut sim = arena();
    let map = Map::three_lane();
    let hide = point_in_patch(&map, 1);

    let hider = sim.spawn_hero(Team::Red, Stats::melee_hero());
    let seeker = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hider, hide);
    place(&mut sim, seeker, hide + Vec2::new(Fx::from_int(500), Fx::ZERO));
    assert!(!sees(&sim, Team::Blue, hider));

    // Past tick zero first. A `last_action_tick` of zero means "has never acted", so acting *on*
    // tick zero is indistinguishable from not having acted — see the reveal check in `net.rs`.
    sim.step(&[]);
    place(&mut sim, hider, hide);
    place(&mut sim, seeker, hide + Vec2::new(Fx::from_int(500), Fx::ZERO));

    // Attacking or casting sets `last_action_tick`, which is what the reveal reads.
    sim.entities.get_mut(hider).unwrap().last_action_tick = sim.tick;
    assert!(sees(&sim, Team::Blue, hider), "shooting from cover stayed hidden");
}

#[test]
fn the_reveal_wears_off() {
    let mut sim = arena();
    let map = Map::three_lane();
    let hide = point_in_patch(&map, 1);

    let hider = sim.spawn_hero(Team::Red, Stats::melee_hero());
    let seeker = sim.spawn_hero(Team::Blue, Stats::melee_hero());
    place(&mut sim, hider, hide);
    place(&mut sim, seeker, hide + Vec2::new(Fx::from_int(500), Fx::ZERO));

    sim.entities.get_mut(hider).unwrap().last_action_tick = 1;
    for _ in 0..(TICK_HZ * 2) {
        sim.step(&[]);
        place(&mut sim, hider, hide);
        place(&mut sim, seeker, hide + Vec2::new(Fx::from_int(500), Fx::ZERO));
    }
    assert!(!sees(&sim, Team::Blue, hider), "the reveal never expired");
}
