//! The three-lane map: that the lanes are walkable, the towers are where they should be, and the
//! guard chain makes a push mean something.

use moba_sim::entity::{EntityKind, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::map::{LaneId, Map};
use moba_sim::sim::{MatchConfig, Sim};

fn config() -> MatchConfig {
    MatchConfig {
        team_size: 5,
        wave_interval: 0,
        creeps_per_wave: 6,
    }
}

fn at(x: i32, y: i32) -> Vec2 {
    Vec2::new(Fx::from_int(x), Fx::from_int(y))
}

// ── Terrain ─────────────────────────────────────────────────────────────────────────────────

#[test]
fn every_lane_is_walkable_from_end_to_end() {
    // The failure this prevents is a wave stuck against a rock forty seconds into a match, which
    // is why the map carves lanes *out of* filled terrain rather than placing jungle and hoping.
    let map = Map::three_lane();

    for lane in LaneId::ALL {
        let points = map.lane_for(Team::Blue, lane);
        for pair in points.windows(2) {
            let (from, to) = (pair[0], pair[1]);
            let length = (to - from).len();
            let direction = (to - from).normalized();
            // Sample every 40 units — well under the 94-unit cell size, so no cell is skipped.
            let mut travelled = Fx::ZERO;
            while travelled <= length {
                let point = from + direction.scale(travelled);
                assert!(
                    !map.terrain.is_blocked(point),
                    "{lane:?} is blocked at {point:?}"
                );
                travelled += Fx::from_int(40);
            }
        }
    }
}

#[test]
fn the_jungle_between_the_lanes_is_solid() {
    // If it were not, "three lanes" would be one open field with three lines drawn on it, and
    // every one of the genre's decisions about where to walk would evaporate.
    let map = Map::three_lane();
    assert!(
        map.terrain.is_blocked(at(3000, 4400)),
        "the space above mid is walkable"
    );
    assert!(
        map.terrain.is_blocked(at(1800, 1800)),
        "the top-left jungle is walkable"
    );
}

#[test]
fn the_map_is_walled_in() {
    let map = Map::three_lane();
    assert!(
        map.terrain.is_blocked(at(30, 3000)),
        "the west edge is open"
    );
    assert!(
        map.terrain.is_blocked(at(5970, 3000)),
        "the east edge is open"
    );
    // And genuinely off-map counts as blocked, so an order past the edge stops at it.
    assert!(map.terrain.is_blocked(at(-500, 3000)));
    assert!(map.terrain.is_blocked(at(9999, 9999)));
}

#[test]
fn a_hero_ordered_into_the_jungle_stops_at_its_edge() {
    let mut sim = Sim::new(Map::three_lane(), config());
    let hero = sim.spawn_named_hero(Team::Blue, moba_sim::ability::heroes::ironclad());
    // Stand in mid and walk straight at the jungle wall.
    sim.entities.get_mut(hero).unwrap().pos = at(3000, 3000);

    for _ in 0..moba_proto::TICK_HZ * 6 {
        sim.step(&[moba_sim::sim::Command::MoveTo {
            hero,
            pos: at(3000, 5500),
        }]);
    }

    let landed = sim.entities.get(hero).unwrap().pos;
    assert!(
        !sim.map.terrain.is_blocked(landed),
        "the hero ended up inside a wall at {landed:?}"
    );
}

// ── Lanes ───────────────────────────────────────────────────────────────────────────────────

#[test]
fn mid_is_the_short_lane_and_the_side_lanes_are_long() {
    // Not a cosmetic property. Most of the genre's strategy is downstream of mid being the fast
    // route and the side lanes being the slow ones.
    let map = Map::three_lane();
    let length = |lane| {
        map.lane_for(Team::Blue, lane)
            .windows(2)
            .map(|p| (p[1] - p[0]).len().floor_int())
            .sum::<i32>()
    };
    let mid = length(LaneId::Mid);
    assert!(mid < length(LaneId::Top), "mid is not shorter than top");
    assert!(mid < length(LaneId::Bot), "mid is not shorter than bot");
}

#[test]
fn a_wave_goes_down_every_lane() {
    let mut sim = Sim::new(Map::three_lane(), config());
    sim.spawn_wave(Team::Blue);

    for lane in LaneId::ALL {
        let count = sim
            .entities
            .iter()
            .filter(|(_, e)| e.kind == EntityKind::Creep && e.lane == Some(lane))
            .count();
        assert!(count > 0, "no creeps were sent down {lane:?}");
    }
}

#[test]
fn each_team_walks_its_lane_from_its_own_end() {
    let map = Map::three_lane();
    let blue = map.lane_for(Team::Blue, LaneId::Mid);
    let red = map.lane_for(Team::Red, LaneId::Mid);
    assert_eq!(
        blue.first(),
        red.last(),
        "the two teams do not share a lane"
    );
    assert_eq!(blue.last(), red.first());
}

// ── The guard chain ─────────────────────────────────────────────────────────────────────────

#[test]
fn an_outer_tower_must_fall_before_the_one_behind_it() {
    // Without this the correct opening is to walk past every tower to the enemy base, and the
    // lanes are scenery.
    let sim = Sim::new(Map::three_lane(), config());

    let towers: Vec<_> = sim
        .entities
        .iter()
        .filter(|(_, e)| e.kind == EntityKind::Tower && e.team == Team::Red)
        .filter(|(_, e)| e.lane == Some(LaneId::Mid))
        .map(|(id, e)| (id, e.guarded_by.len()))
        .collect();
    assert_eq!(towers.len(), 2, "mid should have two towers a side");

    let outer = towers
        .iter()
        .find(|(_, guards)| *guards == 0)
        .expect("no unguarded outer tower");
    let inner = towers
        .iter()
        .find(|(_, guards)| *guards > 0)
        .expect("no guarded inner tower");

    assert!(
        sim.is_attackable(outer.0),
        "the outermost tower is not attackable"
    );
    assert!(
        !sim.is_attackable(inner.0),
        "the inner tower is attackable with the outer one alive"
    );
}

#[test]
fn breaking_one_lane_opens_the_base() {
    // The genre's rule. Requiring every lane would let a losing team stall forever by holding
    // one; requiring none would make the towers pointless.
    let mut sim = Sim::new(Map::three_lane(), config());

    let base = sim
        .entities
        .iter()
        .find(|(_, e)| e.kind == EntityKind::Base && e.team == Team::Red)
        .map(|(id, _)| id)
        .expect("Red has no base");
    assert!(
        !sim.is_attackable(base),
        "the base was open from the first tick"
    );

    // Flatten mid, outer first.
    let mid_towers: Vec<_> = sim
        .entities
        .iter()
        .filter(|(_, e)| {
            e.kind == EntityKind::Tower && e.team == Team::Red && e.lane == Some(LaneId::Mid)
        })
        .map(|(id, e)| (id, e.guarded_by.len()))
        .collect();

    for (tower, _) in mid_towers.iter().filter(|(_, guards)| *guards == 0) {
        sim.entities.get_mut(*tower).unwrap().hp = Fx::ZERO;
    }
    assert!(
        !sim.is_attackable(base),
        "one tower down should not open the base"
    );

    for (tower, _) in mid_towers.iter().filter(|(_, guards)| *guards > 0) {
        sim.entities.get_mut(*tower).unwrap().hp = Fx::ZERO;
    }
    assert!(
        sim.is_attackable(base),
        "a fully broken lane did not open the base"
    );
}

#[test]
fn a_guarded_tower_cannot_be_damaged_even_by_an_ability() {
    // Area effects do not go through target acquisition, so the rule has to be enforced at the
    // damage pipeline too — otherwise a spell dropped over the front line burns down what is
    // behind it.
    let mut sim = Sim::new(Map::three_lane(), config());
    let inner = sim
        .entities
        .iter()
        .find(|(_, e)| e.kind == EntityKind::Tower && !e.guarded_by.is_empty())
        .map(|(id, _)| id)
        .expect("no guarded tower");

    let before = sim.entities.get(inner).unwrap().hp;
    let mut events = Vec::new();
    sim.deal_damage(
        None,
        inner,
        Fx::from_int(500),
        moba_sim::damage::DamageKind::Magical,
        &mut events,
    );
    assert_eq!(
        sim.entities.get(inner).unwrap().hp,
        before,
        "a guarded tower took damage"
    );
}

#[test]
fn structures_still_scale_with_the_team_size() {
    let base_hp = |team_size: u8| {
        let sim = Sim::new(
            Map::three_lane(),
            MatchConfig {
                team_size,
                ..config()
            },
        );
        let hp = sim
            .entities
            .iter()
            .find(|(_, e)| e.kind == EntityKind::Base)
            .map(|(_, e)| e.hp.floor_int())
            .expect("no base");
        hp
    };
    assert!(
        base_hp(1) < base_hp(5),
        "a 1v1 base is as tough as a 5v5 base"
    );
}
