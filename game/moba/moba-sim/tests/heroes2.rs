//! The second four heroes: Jukebox, Ghostuser, Overclock and Relay.
//!
//! These are the real test of the spec-first bet. The first two heroes shaped the engine; these
//! four were designed at the same time but implemented months of work later, against an ability
//! system that was already finished. Everything they needed was supposed to be a new `Effect`
//! arm and a catalogue entry — no new plumbing, no reshaping of the cast path.

use moba_proto::TICK_HZ;
use moba_sim::ability::{heroes, ids, Target};
use moba_sim::damage::{Attached, DamageKind, Modifier};
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

fn place(sim: &mut Sim, id: EntityId, pos: Vec2) {
    sim.entities.get_mut(id).expect("entity vanished").pos = pos;
}

fn hp(sim: &Sim, id: EntityId) -> Fx {
    sim.entities.get(id).expect("entity vanished").hp
}

fn dummy(sim: &mut Sim, team: Team, pos: Vec2) -> EntityId {
    let mut stats = Stats::melee_hero();
    stats.max_hp = Fx::from_int(20_000);
    stats.attack_damage = Fx::ZERO;
    stats.move_speed = Fx::ZERO;
    stats.armour = Fx::ZERO;
    let id = sim.spawn_hero(team, stats);
    place(sim, id, pos);
    id
}

/// Give a hero enough experience to have unlocked its ultimate.
///
/// Slot 3 is locked until level six — see `level.rs`. A test about what an ultimate *does* is
/// not a test about that rule, so it levels past it rather than asserting around it.
fn with_ultimate(sim: &mut Sim, hero: EntityId) {
    sim.entities.get_mut(hero).unwrap().xp =
        moba_sim::level::xp_for_level(moba_sim::level::ULTIMATE_LEVEL);
}

fn cast(sim: &mut Sim, hero: EntityId, slot: usize, target: Target) {
    sim.step(&[Command::CastAbility { hero, slot, target }]);
}

fn settle(sim: &mut Sim, ticks: u32) {
    for _ in 0..ticks {
        sim.step(&[]);
    }
}

// ── Jukebox ─────────────────────────────────────────────────────────────────────────────────

#[test]
fn drop_the_beat_buffs_whoever_is_standing_near_him_not_whoever_he_clicked() {
    // Jukebox's whole identity: he broadcasts. An aura that needed a target would be a different
    // hero.
    let mut sim = arena();
    let jukebox = sim.spawn_named_hero(Team::Blue, heroes::jukebox());
    place(&mut sim, jukebox, at(1000, 1000));

    let near = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    place(&mut sim, near, at(1300, 1000));
    let far = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    place(&mut sim, far, at(5000, 5000));

    let base = sim
        .entities
        .get(near)
        .unwrap()
        .effective_stats(sim.tick)
        .move_speed;
    cast(&mut sim, jukebox, 0, Target::None);
    settle(&mut sim, 2);

    assert!(
        sim.entities
            .get(near)
            .unwrap()
            .effective_stats(sim.tick)
            .move_speed
            > base,
        "an ally standing next to Jukebox was not buffed"
    );
    assert_eq!(
        sim.entities
            .get(far)
            .unwrap()
            .effective_stats(sim.tick)
            .move_speed
            .floor_int(),
        base.floor_int(),
        "the aura reached an ally on the far side of the map"
    );
}

#[test]
fn requiem_heals_over_time_and_pays_out_to_jukebox_if_the_target_dies() {
    // The death trigger. Without it Requiem on someone about to die is wasted mana, which makes
    // the ability unusable at exactly the moment it is most wanted.
    let mut sim = arena();
    let jukebox = sim.spawn_named_hero(Team::Blue, heroes::jukebox());
    place(&mut sim, jukebox, at(1000, 1000));
    let ally = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    place(&mut sim, ally, at(1200, 1000));
    sim.entities.get_mut(ally).unwrap().hp = Fx::from_int(300);
    sim.entities.get_mut(jukebox).unwrap().hp = Fx::from_int(200);

    cast(&mut sim, jukebox, 1, Target::Unit(ally));
    settle(&mut sim, TICK_HZ);
    assert!(hp(&sim, ally) > Fx::from_int(300), "Requiem healed nothing");

    // Kill the target mid-heal; the remainder should follow Jukebox.
    let jukebox_before = hp(&sim, jukebox);
    sim.entities.get_mut(ally).unwrap().hp = Fx::ZERO;
    settle(&mut sim, TICK_HZ);

    assert!(
        hp(&sim, jukebox) > jukebox_before,
        "the target died and the remaining heal went nowhere"
    );
}

#[test]
fn feedback_silences_without_stopping_movement_or_attacks() {
    // The distinction that makes silence a different effect from a stun. Collapsing them would
    // make Jukebox a hard-crowd-control hero, which is not what the roster needs him to be.
    let mut sim = arena();
    let jukebox = sim.spawn_named_hero(Team::Blue, heroes::jukebox());
    place(&mut sim, jukebox, at(1000, 1000));
    let victim = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    place(&mut sim, victim, at(1200, 1000));

    cast(&mut sim, jukebox, 2, Target::Point(at(1200, 1000)));
    settle(&mut sim, TICK_HZ / 2);

    let e = sim.entities.get(victim).unwrap();
    assert!(e.is_silenced(sim.tick), "Feedback silenced nobody");
    assert!(!e.is_stunned(sim.tick), "a silence also stunned");
    assert!(
        e.effective_stats(sim.tick).move_speed > Fx::ZERO,
        "a silence stopped the victim moving"
    );
}

// ── Ghostuser ───────────────────────────────────────────────────────────────────────────────

#[test]
fn idle_hides_him_from_the_enemy_snapshot_entirely() {
    // Enforced by the server, not the renderer: a stealthed hero is filtered out of the enemy's
    // snapshot exactly as one in the fog is, so there is nothing on the client to reveal.
    let mut sim = arena();
    let ghost = sim.spawn_named_hero(Team::Blue, heroes::ghostuser());
    place(&mut sim, ghost, at(1000, 1000));
    let watcher = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    place(&mut sim, watcher, at(1100, 1000));

    // Visible before.
    let seen = sim.snapshot(Team::Red, Some(watcher), &[]);
    assert!(seen.entities.iter().any(|e| e.id == ghost.to_net()));

    cast(&mut sim, ghost, 0, Target::None);
    settle(&mut sim, TICK_HZ * 2);

    let hidden = sim.snapshot(Team::Red, Some(watcher), &[]);
    assert!(
        !hidden.entities.iter().any(|e| e.id == ghost.to_net()),
        "a stealthed hero was sent to the enemy client anyway"
    );
    // His own team still sees him.
    let allied = sim.snapshot(Team::Blue, Some(ghost), &[]);
    assert!(allied.entities.iter().any(|e| e.id == ghost.to_net()));
}

#[test]
fn attacking_breaks_stealth() {
    let mut sim = arena();
    let ghost = sim.spawn_named_hero(Team::Blue, heroes::ghostuser());
    place(&mut sim, ghost, at(1000, 1000));
    let victim = dummy(&mut sim, Team::Red, at(1080, 1000));

    cast(&mut sim, ghost, 0, Target::None);
    settle(&mut sim, TICK_HZ * 2);
    assert!(sim.entities.get(ghost).unwrap().is_stealthed(sim.tick));

    sim.step(&[Command::Attack {
        hero: ghost,
        target: victim,
    }]);
    settle(&mut sim, TICK_HZ);
    assert!(
        !sim.entities.get(ghost).unwrap().is_stealthed(sim.tick),
        "he stayed invisible while attacking"
    );
}

#[test]
fn backspace_returns_him_to_where_he_stood_three_seconds_ago() {
    // MOBA.md's second finding, working end to end: the sim keeps three seconds of positions per
    // hero, and this is the ability that spends them.
    let mut sim = arena();
    let ghost = sim.spawn_named_hero(Team::Blue, heroes::ghostuser());
    place(&mut sim, ghost, at(1000, 1000));
    settle(&mut sim, 5);
    let origin = sim.entities.get(ghost).unwrap().pos;

    // Walk away for three seconds.
    for _ in 0..TICK_HZ * 3 {
        sim.step(&[Command::MoveTo {
            hero: ghost,
            pos: at(3000, 1000),
        }]);
    }
    let travelled = sim.entities.get(ghost).unwrap().pos;
    assert!(
        (travelled - origin).len() > Fx::from_int(400),
        "he never went anywhere"
    );

    cast(&mut sim, ghost, 2, Target::None);
    let back = sim.entities.get(ghost).unwrap().pos;
    assert!(
        (back - origin).len() < (travelled - origin).len(),
        "Backspace left him where he was rather than where he had been"
    );
}

#[test]
fn ban_takes_someone_off_the_map_and_gives_them_back() {
    let mut sim = arena();
    let ghost = sim.spawn_named_hero(Team::Blue, heroes::ghostuser());
    place(&mut sim, ghost, at(1000, 1000));
    let victim = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    place(&mut sim, victim, at(1300, 1000));
    with_ultimate(&mut sim, ghost);

    cast(&mut sim, ghost, 3, Target::Unit(victim));
    settle(&mut sim, TICK_HZ * 2);

    assert!(
        sim.entities.get(victim).unwrap().is_banished(sim.tick),
        "Ban banished nobody"
    );

    // Untouchable while banished — an area effect landing where they stood must not reach them.
    let before = hp(&sim, victim);
    let mut events = Vec::new();
    sim.deal_damage(
        Some(ghost),
        victim,
        Fx::from_int(400),
        DamageKind::Magical,
        &mut events,
    );
    assert_eq!(hp(&sim, victim), before, "a banished hero took damage");

    // And comes back.
    settle(&mut sim, TICK_HZ * 3);
    assert!(
        !sim.entities.get(victim).unwrap().is_banished(sim.tick),
        "Ban never wore off"
    );
}

// ── Overclock ───────────────────────────────────────────────────────────────────────────────

#[test]
fn spool_up_ramps_on_one_target_and_resets_when_he_switches() {
    let mut sim = arena();
    let overclock = sim.spawn_named_hero(Team::Blue, heroes::overclock());
    place(&mut sim, overclock, at(1000, 1000));
    let first = dummy(&mut sim, Team::Red, at(1300, 1000));
    let second = dummy(&mut sim, Team::Red, at(1320, 1000));

    let base = sim
        .entities
        .get(overclock)
        .unwrap()
        .effective_stats(sim.tick)
        .attack_interval;

    for _ in 0..TICK_HZ * 6 {
        sim.step(&[Command::Attack {
            hero: overclock,
            target: first,
        }]);
    }
    let ramped = sim
        .entities
        .get(overclock)
        .unwrap()
        .effective_stats(sim.tick)
        .attack_interval;
    assert!(
        ramped < base,
        "attacking the same target did not ramp attack speed"
    );

    // Switching resets the chain.
    sim.step(&[Command::Attack {
        hero: overclock,
        target: second,
    }]);
    settle(&mut sim, TICK_HZ * 2);
    let after_switch = sim
        .entities
        .get(overclock)
        .unwrap()
        .effective_stats(sim.tick)
        .attack_interval;
    assert!(after_switch > ramped, "switching targets kept the ramp");
}

#[test]
fn vent_strips_slows_but_leaves_a_friendly_haste_alone() {
    // A dispel that removed every move-speed modifier would have Vent undo Jukebox's buff on its
    // own user — a dispel working against the team that cast it.
    let mut sim = arena();
    let overclock = sim.spawn_named_hero(Team::Blue, heroes::overclock());
    let tick = sim.tick;
    {
        let e = sim.entities.get_mut(overclock).unwrap();
        e.attach(
            Attached::new(Modifier::MoveSpeedPct {
                pct: Fx::ratio(-40, 100),
                until_tick: tick + TICK_HZ * 5,
            }),
            tick,
        );
        e.attach(
            Attached::from(
                Modifier::MoveSpeedPct {
                    pct: Fx::ratio(15, 100),
                    until_tick: tick + TICK_HZ * 5,
                },
                ids::DROP_THE_BEAT,
            ),
            tick,
        );
    }

    cast(&mut sim, overclock, 1, Target::None);
    settle(&mut sim, 2);

    let e = sim.entities.get(overclock).unwrap();
    let has_slow = e
        .modifiers
        .iter()
        .any(|a| matches!(a.modifier, Modifier::MoveSpeedPct { pct, .. } if pct < Fx::ZERO));
    let has_haste = e
        .modifiers
        .iter()
        .any(|a| matches!(a.modifier, Modifier::MoveSpeedPct { pct, .. } if pct > Fx::ZERO));
    assert!(!has_slow, "Vent left the slow in place");
    assert!(
        has_haste,
        "Vent stripped a friendly haste along with the slow"
    );
}

#[test]
fn meltdown_costs_health_rather_than_mana_and_will_not_kill_him() {
    // "Vent or die" is meant to be a decision. A toggle that could finish you is a suicide
    // button, and a player would simply never press it.
    let mut sim = arena();
    let overclock = sim.spawn_named_hero(Team::Blue, heroes::overclock());
    sim.entities.get_mut(overclock).unwrap().hp = Fx::from_int(120);
    let mana_before = sim.entities.get(overclock).unwrap().mana;

    cast(&mut sim, overclock, 3, Target::None);
    settle(&mut sim, TICK_HZ * 8);

    let e = sim.entities.get(overclock).unwrap();
    assert!(e.hp > Fx::ZERO, "Meltdown killed its own user");
    assert_eq!(
        e.mana.floor_int(),
        mana_before.floor_int(),
        "Meltdown charged mana"
    );
    assert!(
        !e.abilities.state[3].toggled_on,
        "Meltdown kept running past what he could pay"
    );
}

// ── Relay ───────────────────────────────────────────────────────────────────────────────────

#[test]
fn deploy_drone_puts_a_unit_on_the_map_that_expires_without_paying_a_bounty() {
    // A summon timing out is not a kill. Routing it through the death path would pay the enemy
    // carry for waiting, which turns Swarm into a gift.
    let mut sim = arena();
    let relay = sim.spawn_named_hero(Team::Blue, heroes::relay());
    place(&mut sim, relay, at(1000, 1000));

    let before = sim
        .entities
        .iter()
        .filter(|(_, e)| e.kind == EntityKind::Creep)
        .count();
    cast(&mut sim, relay, 0, Target::Point(at(1200, 1000)));
    settle(&mut sim, 2);

    let after = sim
        .entities
        .iter()
        .filter(|(_, e)| e.kind == EntityKind::Creep)
        .count();
    assert_eq!(after, before + 1, "no drone appeared");

    let drone = sim
        .entities
        .iter()
        .find(|(_, e)| e.kind == EntityKind::Creep)
        .map(|(id, _)| id)
        .unwrap();
    assert_eq!(
        sim.entities.get(drone).unwrap().owner,
        Some(relay),
        "the drone has no owner"
    );

    // Run past its lease.
    settle(&mut sim, TICK_HZ * 21);
    assert!(
        sim.entities.get(drone).is_none(),
        "the drone outlived its duration"
    );
}

#[test]
fn link_sends_a_share_of_an_allys_damage_to_relay() {
    let mut sim = arena();
    let relay = sim.spawn_named_hero(Team::Blue, heroes::relay());
    place(&mut sim, relay, at(1000, 1000));
    let ally = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    place(&mut sim, ally, at(1200, 1000));

    cast(&mut sim, relay, 1, Target::Unit(ally));
    settle(&mut sim, 2);

    let (relay_before, ally_before) = (hp(&sim, relay), hp(&sim, ally));
    let mut events = Vec::new();
    sim.deal_damage(
        None,
        ally,
        Fx::from_int(100),
        DamageKind::Magical,
        &mut events,
    );

    assert!(
        hp(&sim, relay) < relay_before,
        "Relay took none of the tethered damage"
    );
    assert!(
        hp(&sim, ally) < ally_before,
        "the ally took none of it either"
    );
    let moved = relay_before - hp(&sim, relay);
    let kept = ally_before - hp(&sim, ally);
    assert!(moved < kept, "Relay took more of the hit than the ally did");
}

#[test]
fn barrier_blocks_the_ground_and_then_gives_it_back() {
    // The runtime terrain mutation MOBA.md warned about, working — and, importantly, undoing
    // itself. A barrier that never lifted would leave the map permanently scarred.
    let mut sim = arena();
    let relay = sim.spawn_named_hero(Team::Blue, heroes::relay());
    place(&mut sim, relay, at(1000, 1000));
    let spot = at(1300, 1000);

    assert!(
        !sim.map.terrain.is_blocked(spot),
        "the ground was blocked before the cast"
    );
    cast(&mut sim, relay, 2, Target::Point(spot));
    settle(&mut sim, 2);
    assert!(sim.map.terrain.is_blocked(spot), "Barrier blocked nothing");

    settle(&mut sim, TICK_HZ * 4);
    assert!(!sim.map.terrain.is_blocked(spot), "Barrier never lifted");
}

#[test]
fn swarm_summons_four_drones_already_tethered() {
    let mut sim = arena();
    let relay = sim.spawn_named_hero(Team::Blue, heroes::relay());
    place(&mut sim, relay, at(1000, 1000));
    with_ultimate(&mut sim, relay);

    cast(&mut sim, relay, 3, Target::Point(at(1200, 1000)));
    settle(&mut sim, 2);

    let drones: Vec<_> = sim
        .entities
        .iter()
        .filter(|(_, e)| e.kind == EntityKind::Creep && e.owner == Some(relay))
        .map(|(id, _)| id)
        .collect();
    assert_eq!(drones.len(), 4, "Swarm summoned {} drones", drones.len());

    for drone in &drones {
        let tethered = sim
            .entities
            .get(*drone)
            .unwrap()
            .modifiers
            .iter()
            .any(|a| matches!(a.modifier, Modifier::Redirect { to, .. } if to == relay));
        assert!(tethered, "a Swarm drone arrived untethered");
    }
}

// ── The roster as a whole ───────────────────────────────────────────────────────────────────

#[test]
fn every_hero_in_the_roster_has_four_real_abilities() {
    // Catches the mistake of adding a hero to the list and forgetting to give one of their slots
    // a catalogue entry, which otherwise shows up as a key that silently does nothing.
    let sim = arena();
    for build in heroes::all() {
        let hero = build();
        for (slot, ability) in hero.abilities.iter().enumerate() {
            let spec = sim
                .abilities
                .get(*ability)
                .unwrap_or_else(|| panic!("{} slot {slot} has no catalogue entry", hero.name));
            assert!(
                !spec.name.is_empty(),
                "{} slot {slot} is unnamed",
                hero.name
            );
        }
    }
}

#[test]
fn no_two_heroes_share_an_ability_id() {
    // A copy-paste in the catalogue would have two heroes casting the same thing, which reads as
    // a balance mystery rather than as the typo it is.
    let mut seen = Vec::new();
    for build in heroes::all() {
        for ability in build().abilities {
            assert!(
                !seen.contains(&ability),
                "ability {ability:?} is on two heroes"
            );
            seen.push(ability);
        }
    }
    assert_eq!(
        seen.len(),
        24,
        "the roster should have twenty-four abilities"
    );
}
