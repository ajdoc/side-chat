//! Phase 1's acceptance test: a match that reaches a conclusion, with no renderer, no server
//! and no client.
//!
//! The point is not that a base falls — it is that every system built so far has to cooperate
//! for one to. A creep must spawn, walk a polyline, notice a tower, stop, resolve damage through
//! the pipeline against the tower's armour, die to return fire, be reaped without corrupting the
//! arena, and be replaced by the next wave. If any one of those is wrong the match hangs, and a
//! hang is what this test actually catches.

use moba_proto::TICK_HZ;
use moba_sim::entity::{EntityKind, Stats, Team};
use moba_sim::fixed::Fx;
use moba_sim::map::Map;
use moba_sim::sim::{run_to_conclusion, Command, Event, MatchConfig, Sim};

/// Manual waves — `wave_interval: 0` — so a test decides who pushes. That is what lets this be
/// a *lopsided* lane without the sim carrying a test-only flag for it.
fn manual_waves() -> MatchConfig {
    MatchConfig {
        team_size: 5,
        wave_interval: 0,
        creeps_per_wave: 6,
    }
}

#[test]
fn a_one_sided_push_takes_the_tower_and_then_the_base() {
    let mut sim = Sim::new(Map::one_lane(), manual_waves());

    // Blue pushes uncontested, a wave every ten seconds. Nothing spawns for Red, so the only
    // thing standing between the wave and the base is Red's tower.
    let mut events = Vec::new();
    let mut ticks = 0u32;
    while sim.winner().is_none() && ticks < TICK_HZ * 60 * 10 {
        if ticks.is_multiple_of(TICK_HZ * 10) {
            sim.spawn_wave(Team::Blue);
        }
        events.extend(sim.step(&[]));
        ticks += 1;
    }

    assert_eq!(
        sim.winner(),
        Some(Team::Blue),
        "the push never concluded in ten minutes"
    );

    let structures: Vec<_> = events
        .iter()
        .filter_map(|e| match e {
            Event::StructureDestroyed { team, .. } => Some(*team),
            _ => None,
        })
        .collect();
    assert_eq!(
        structures,
        vec![Team::Red, Team::Red],
        "expected Red's tower then Red's base, and nothing of Blue's"
    );

    // The match-ended event must be last and must appear exactly once.
    let ended: Vec<_> = events
        .iter()
        .filter(|e| matches!(e, Event::MatchEnded { .. }))
        .collect();
    assert_eq!(ended.len(), 1);
    assert_eq!(
        events.last(),
        Some(&Event::MatchEnded { winner: Team::Blue })
    );
}

#[test]
fn an_even_lane_does_not_resolve() {
    // The control case for the test above. If both sides push equally the lane should grind
    // rather than someone winning — a base falling here would mean the sim has a bias in it,
    // most likely arena order leaking into targeting.
    let mut sim = Sim::new(Map::one_lane(), MatchConfig::default());
    run_to_conclusion(&mut sim, TICK_HZ * 60 * 3);
    assert_eq!(
        sim.winner(),
        None,
        "an even lane resolved, so something is biased"
    );
}

#[test]
fn the_same_inputs_produce_the_same_match() {
    // The property the entire crate exists for. Two sims, same seed of events, compared event
    // for event — this is the harness the server/client desync check will grow out of.
    let run = || {
        let mut sim = Sim::new(Map::one_lane(), manual_waves());
        let mut events = Vec::new();
        for tick in 0..TICK_HZ * 120 {
            if tick.is_multiple_of(TICK_HZ * 10) {
                sim.spawn_wave(Team::Blue);
                sim.spawn_wave(Team::Red);
            }
            events.extend(sim.step(&[]));
        }
        events
    };

    let first = run();
    let second = run();
    assert_eq!(first.len(), second.len(), "the two runs diverged in length");
    for (i, (a, b)) in first.iter().zip(second.iter()).enumerate() {
        assert_eq!(a, b, "runs diverged at event {i}");
    }
    assert!(
        !first.is_empty(),
        "the run produced no events, so it proves nothing"
    );
}

#[test]
fn a_hero_walks_where_it_is_told_and_then_stops() {
    let mut sim = Sim::new(Map::one_lane(), manual_waves());
    let hero = sim.spawn_hero(Team::Blue, Stats::melee_creep());
    let destination = sim.map.lanes[0].waypoints[1];

    for _ in 0..TICK_HZ * 20 {
        sim.step(&[Command::MoveTo {
            hero,
            pos: destination,
        }]);
    }

    let e = sim.entities.get(hero).expect("hero vanished");
    let remaining = (e.pos - destination).len();
    assert!(
        remaining < Fx::from_int(41),
        "hero stopped {remaining:?} short"
    );
}

#[test]
fn a_dead_hero_stays_in_the_arena() {
    // Heroes respawn; creeps do not. A hero despawned on death would invalidate every id held
    // by a scoreboard, a mark or a tether — see `Sim::reap`.
    let mut sim = Sim::new(Map::one_lane(), manual_waves());
    let hero = sim.spawn_hero(Team::Blue, Stats::melee_creep());

    let mut events = Vec::new();
    sim.deal_damage(
        None,
        hero,
        Fx::from_int(99_999),
        moba_sim::damage::DamageKind::Pure,
        &mut events,
    );
    sim.step(&[]);

    let e = sim
        .entities
        .get(hero)
        .expect("a dead hero must still be addressable");
    assert_eq!(e.hp, Fx::ZERO);
    assert_eq!(e.kind, EntityKind::Hero);
}
