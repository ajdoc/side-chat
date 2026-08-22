//! Every ability Ironclad and Emberwitch have, exercised through the public command path.
//!
//! These are the tests that decide whether the spec-first bet in MOBA.md paid off. If the
//! ability engine is really the union of what the roster needs, then eight abilities across two
//! very different heroes — a melee initiator built out of dashes, taunts and a channel, and a
//! ranged mage built out of zones, stacks and a pierce — should have needed no bespoke code
//! beyond their entries in the catalogue. Anything here that had to reach past the engine is a
//! sign the primitive extraction missed something.

use moba_proto::TICK_HZ;
use moba_sim::ability::{heroes, ids, CastRefusal, Target};
use moba_sim::damage::{Attached, DamageKind, Modifier};
use moba_sim::entity::{EntityId, EntityKind, Stats, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::map::Map;
use moba_sim::sim::{Command, Event, MatchConfig, Sim};

/// No creep waves and no structures in the way: an empty field to test one ability on.
///
/// `Map::empty()` matters more than it looks. On the real map a control subject standing 600
/// units off to one side is inside Blue's tower range, and a tower hit is indistinguishable
/// from the ability under test hitting someone it should not have.
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

fn pos_of(sim: &Sim, id: EntityId) -> Vec2 {
    sim.entities.get(id).expect("entity vanished").pos
}

fn hp_of(sim: &Sim, id: EntityId) -> Fx {
    sim.entities.get(id).expect("entity vanished").hp
}

/// A punching bag with enough health to survive anything in the catalogue.
fn dummy(sim: &mut Sim, team: Team, pos: Vec2) -> EntityId {
    let mut stats = Stats::melee_hero();
    // Well under Q16.16's ~32768 ceiling. A "just make it huge" value of 100_000 saturates
    // silently, and every health reading in the test then compares two identical maxima.
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
/// Slot 3 is locked until level six — see `level.rs` for why. A test about what an ultimate
/// *does* is not a test about that rule, so it levels past it rather than asserting around it.
fn with_ultimate(sim: &mut Sim, hero: EntityId) {
    sim.entities.get_mut(hero).unwrap().xp =
        moba_sim::level::xp_for_level(moba_sim::level::ULTIMATE_LEVEL);
}

/// A hero with its abilities actually learned.
///
/// Skill points mean an ability starts at rank zero and cannot be cast at all. A test about what
/// an ability *does* is not a test about that rule — `ranks.rs` covers it — so this levels to six
/// and spends a point on each of the four, which is what any player would have done by the time
/// the ability under test matters.
fn ready(sim: &mut Sim, hero: EntityId) {
    sim.entities.get_mut(hero).unwrap().xp =
        moba_sim::level::xp_for_level(moba_sim::level::ULTIMATE_LEVEL);
    for slot in 0..4 {
        let _ = sim.learn(hero, slot);
    }
}

fn cast(sim: &mut Sim, hero: EntityId, slot: usize, target: Target) -> Vec<Event> {
    sim.step(&[Command::CastAbility { hero, slot, target }])
}

fn refusal(events: &[Event]) -> Option<CastRefusal> {
    events.iter().find_map(|e| match e {
        Event::CastRefused { reason, .. } => Some(*reason),
        _ => None,
    })
}

// ── Ironclad ────────────────────────────────────────────────────────────────────────────────

#[test]
fn shield_charge_rides_through_creeps_and_stops_on_the_first_hero() {
    let mut sim = arena();
    let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    ready(&mut sim, ironclad);
    place(&mut sim, ironclad, at(0, 0));

    // A creep directly in the path, and a hero beyond it. The charge must ignore the first and
    // stop on the second — otherwise a lane wave body-blocks every initiation in the game.
    let mut creep_stats = Stats::melee_creep();
    creep_stats.move_speed = Fx::ZERO;
    let creep = sim.spawn_hero(Team::Red, creep_stats);
    sim.entities.get_mut(creep).unwrap().kind_to_creep();
    place(&mut sim, creep, at(300, 0));

    let victim = dummy(&mut sim, Team::Red, at(700, 0));

    cast(&mut sim, ironclad, 0, Target::Point(at(900, 0)));
    for _ in 0..TICK_HZ {
        sim.step(&[]);
    }

    let landed = pos_of(&sim, ironclad);
    assert!(
        landed.x > Fx::from_int(400),
        "charge stopped on the creep at {landed:?}"
    );
    assert!(
        landed.x < Fx::from_int(750),
        "charge rode past its target to {landed:?}"
    );

    let stunned = sim.entities.get(victim).unwrap().is_stunned(sim.tick);
    assert!(stunned, "the charge connected but did not stun");
    assert!(
        !sim.entities.get(creep).unwrap().is_stunned(sim.tick),
        "the creep was stunned too"
    );
}

#[test]
fn bulwark_toggles_on_and_off_without_stripping_item_armour() {
    let mut sim = arena();
    let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    ready(&mut sim, ironclad);

    // An item's armour, attached from a different source. Turning Bulwark off must not take it.
    sim.entities.get_mut(ironclad).unwrap().attach(
        Attached::from(Modifier::Armour(Fx::from_int(12)), ids::FIREWALL),
        0,
    );

    let base = sim
        .entities
        .get(ironclad)
        .unwrap()
        .effective_stats(sim.tick);

    cast(&mut sim, ironclad, 1, Target::None);
    sim.step(&[]);
    let on = sim
        .entities
        .get(ironclad)
        .unwrap()
        .effective_stats(sim.tick);
    assert!(on.armour > base.armour, "Bulwark granted no armour");
    assert!(
        on.move_speed < base.move_speed,
        "Bulwark did not slow its user"
    );

    cast(&mut sim, ironclad, 1, Target::None);
    sim.step(&[]);
    let off = sim
        .entities
        .get(ironclad)
        .unwrap()
        .effective_stats(sim.tick);
    assert_eq!(
        off.armour.floor_int(),
        base.armour.floor_int(),
        "toggling Bulwark off took the item's armour with it"
    );
    assert_eq!(off.move_speed.floor_int(), base.move_speed.floor_int());
}

#[test]
fn bulwark_drains_mana_and_shuts_itself_off_when_empty() {
    let mut sim = arena();
    let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    ready(&mut sim, ironclad);
    sim.entities.get_mut(ironclad).unwrap().mana = Fx::from_int(20);

    cast(&mut sim, ironclad, 1, Target::None);
    for _ in 0..TICK_HZ * 5 {
        sim.step(&[]);
    }

    let e = sim.entities.get(ironclad).unwrap();
    assert!(
        !e.abilities.state[1].toggled_on,
        "Bulwark ran on an empty mana pool"
    );
    // Compared against the hero's own unbuffed armour rather than the level-one constant: a
    // hero levels now, and a levelled hero has more armour than the statline it started with.
    let unbuffed = Stats::melee_hero().armour + moba_sim::level::bonus_for(e.level()).armour;
    assert_eq!(
        e.effective_stats(sim.tick).armour.floor_int(),
        unbuffed.floor_int(),
        "the armour outlived the toggle"
    );
}

#[test]
fn taunt_overrides_orders_and_hands_control_back_when_it_ends() {
    let mut sim = arena();
    let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    ready(&mut sim, ironclad);
    place(&mut sim, ironclad, at(0, 0));
    let victim = dummy(&mut sim, Team::Red, at(300, 0));
    sim.entities.get_mut(victim).unwrap().stats.move_speed = Fx::from_int(300);

    cast(&mut sim, ironclad, 2, Target::Unit(victim));

    // While taunted, the victim's own orders are refused outright.
    sim.step(&[Command::MoveTo {
        hero: victim,
        pos: at(-2000, 0),
    }]);
    let order = sim.entities.get(victim).unwrap().order;
    assert!(
        matches!(order, moba_sim::entity::Order::Forced { .. }),
        "a move order broke the taunt: {order:?}"
    );

    for _ in 0..TICK_HZ * 2 {
        sim.step(&[]);
    }
    let order = sim.entities.get(victim).unwrap().order;
    assert!(
        !matches!(order, moba_sim::entity::Order::Forced { .. }),
        "the taunt never expired: {order:?}"
    );
}

#[test]
fn last_stand_pays_out_on_missing_health_and_a_stun_cancels_it() {
    let hit_for = |ironclad_hp: i32| {
        let mut sim = arena();
        let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
        ready(&mut sim, ironclad);
        place(&mut sim, ironclad, at(0, 0));
        let victim = dummy(&mut sim, Team::Red, at(200, 0));

        with_ultimate(&mut sim, ironclad);
        sim.entities.get_mut(ironclad).unwrap().hp = Fx::from_int(ironclad_hp);
        let before = hp_of(&sim, victim);

        cast(&mut sim, ironclad, 3, Target::None);
        for _ in 0..TICK_HZ * 3 {
            sim.step(&[]);
        }
        before - hp_of(&sim, victim)
    };

    let healthy = hit_for(700);
    let nearly_dead = hit_for(60);
    assert!(
        nearly_dead > healthy * Fx::from_int(2),
        "Last Stand at 60hp ({nearly_dead:?}) should dwarf it at 700hp ({healthy:?})"
    );

    // And the counterplay: interrupting the channel means it never pays out at all.
    let mut sim = arena();
    let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    ready(&mut sim, ironclad);
    place(&mut sim, ironclad, at(0, 0));
    let victim = dummy(&mut sim, Team::Red, at(200, 0));
    with_ultimate(&mut sim, ironclad);
    sim.entities.get_mut(ironclad).unwrap().hp = Fx::from_int(60);

    let mut events = cast(&mut sim, ironclad, 3, Target::None);
    let before = hp_of(&sim, victim);
    sim.entities.get_mut(ironclad).unwrap().attach(
        Attached::new(Modifier::Stun {
            until_tick: sim.tick + TICK_HZ * 3,
        }),
        sim.tick,
    );
    for _ in 0..TICK_HZ * 3 {
        events.extend(sim.step(&[]));
    }

    assert!(
        events
            .iter()
            .any(|e| matches!(e, Event::CastInterrupted { .. })),
        "stunning a channel did not interrupt it"
    );
    let leaked = before - hp_of(&sim, victim);
    assert!(
        leaked < Fx::from_int(30),
        "an interrupted channel still dealt {leaked:?}"
    );
}

// ── Emberwitch ──────────────────────────────────────────────────────────────────────────────

#[test]
fn cinder_hits_applies_heat_and_leaves_ground_that_keeps_burning() {
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    ready(&mut sim, witch);
    place(&mut sim, witch, at(0, 0));
    let victim = dummy(&mut sim, Team::Red, at(120, 0));

    let before = hp_of(&sim, victim);
    cast(&mut sim, witch, 0, Target::Point(at(120, 0)));
    for _ in 0..TICK_HZ / 2 {
        sim.step(&[]);
    }

    let after_impact = hp_of(&sim, victim);
    assert!(
        before - after_impact >= Fx::from_int(80),
        "the initial hit did not land"
    );
    assert!(
        sim.entities
            .get(victim)
            .unwrap()
            .has_status(sim.tick, |m| matches!(m, Modifier::HeatStacks { .. })),
        "Cinder applied no Heat"
    );
    assert!(
        sim.entities.iter().any(|(_, e)| e.kind == EntityKind::Zone),
        "no burning ground was left behind"
    );

    for _ in 0..TICK_HZ {
        sim.step(&[]);
    }
    assert!(
        hp_of(&sim, victim) < after_impact,
        "the zone did not burn anyone standing in it"
    );

    // And it must retire on schedule rather than burning forever.
    for _ in 0..TICK_HZ * 5 {
        sim.step(&[]);
    }
    assert!(
        !sim.entities.iter().any(|(_, e)| e.kind == EntityKind::Zone),
        "the burning ground outlived its duration"
    );
}

#[test]
fn kindle_stacks_heat_on_autoattacks_and_caps_at_three() {
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    ready(&mut sim, witch);
    place(&mut sim, witch, at(0, 0));
    let victim = dummy(&mut sim, Team::Red, at(200, 0));

    sim.entities.get_mut(witch).unwrap().order = moba_sim::entity::Order::Attack(victim);
    for _ in 0..TICK_HZ * 8 {
        sim.step(&[]);
    }

    let stacks = sim
        .entities
        .get(victim)
        .unwrap()
        .modifiers
        .iter()
        .find_map(|a| match a.modifier {
            Modifier::HeatStacks { stacks, .. } => Some(stacks),
            _ => None,
        })
        .expect("autoattacks applied no Heat");
    assert_eq!(stacks, 3, "Heat should cap at three, not reach {stacks}");
}

#[test]
fn flashstep_blinks_the_full_range_and_makes_the_next_cast_free() {
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    ready(&mut sim, witch);
    place(&mut sim, witch, at(0, 0));

    let before = sim.entities.get(witch).unwrap().mana;
    cast(&mut sim, witch, 2, Target::Point(at(400, 0)));

    let landed = pos_of(&sim, witch);
    assert!(
        landed.x > Fx::from_int(390),
        "Flashstep went nowhere: {landed:?}"
    );

    // Cinder immediately after should cost nothing beyond what Flashstep itself charged.
    let after_blink = sim.entities.get(witch).unwrap().mana;
    cast(&mut sim, witch, 0, Target::Point(at(500, 0)));
    let after_cinder = sim.entities.get(witch).unwrap().mana;

    assert!(before > after_blink, "Flashstep was free");
    assert_eq!(
        after_cinder.floor_int(),
        after_blink.floor_int(),
        "the free cast was charged anyway"
    );
}

#[test]
fn pyre_pierces_the_line_and_consumes_heat_for_bonus_damage() {
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    ready(&mut sim, witch);
    place(&mut sim, witch, at(0, 0));
    with_ultimate(&mut sim, witch);

    let near = dummy(&mut sim, Team::Red, at(300, 0));
    let far = dummy(&mut sim, Team::Red, at(800, 0));
    let aside = dummy(&mut sim, Team::Red, at(400, 600));

    // Only the near one is carrying Heat, so the two in the line must take different amounts.
    sim.entities.get_mut(near).unwrap().attach(
        Attached::new(Modifier::HeatStacks {
            stacks: 3,
            until_tick: sim.tick + TICK_HZ * 10,
        }),
        sim.tick,
    );

    let (n0, f0, a0) = (hp_of(&sim, near), hp_of(&sim, far), hp_of(&sim, aside));
    cast(&mut sim, witch, 3, Target::Point(at(1000, 0)));
    for _ in 0..TICK_HZ {
        sim.step(&[]);
    }

    let near_taken = n0 - hp_of(&sim, near);
    let far_taken = f0 - hp_of(&sim, far);

    assert!(
        far_taken > Fx::from_int(150),
        "Pyre did not pierce to the second target"
    );
    assert!(
        near_taken > far_taken + Fx::from_int(200),
        "Heat was not consumed: {near_taken:?} vs {far_taken:?}"
    );
    assert_eq!(
        a0,
        hp_of(&sim, aside),
        "Pyre hit someone standing off the line"
    );
    assert!(
        !sim.entities
            .get(near)
            .unwrap()
            .has_status(sim.tick, |m| matches!(m, Modifier::HeatStacks { .. })),
        "Heat survived being consumed"
    );
}

// ── The engine's own rules ──────────────────────────────────────────────────────────────────

#[test]
fn a_refused_cast_says_why() {
    let mut sim = arena();
    let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    ready(&mut sim, ironclad);
    place(&mut sim, ironclad, at(0, 0));
    let victim = dummy(&mut sim, Team::Red, at(300, 0));

    // Out of range: Taunt reaches 500.
    place(&mut sim, victim, at(3000, 0));
    let events = cast(&mut sim, ironclad, 2, Target::Unit(victim));
    assert_eq!(refusal(&events), Some(CastRefusal::OutOfRange));

    // On cooldown, after a successful cast.
    place(&mut sim, victim, at(300, 0));
    cast(&mut sim, ironclad, 2, Target::Unit(victim));
    let events = cast(&mut sim, ironclad, 2, Target::Unit(victim));
    assert_eq!(refusal(&events), Some(CastRefusal::OnCooldown));

    // Silenced: can still move and attack, cannot cast. That distinction is Jukebox's Feedback.
    sim.entities.get_mut(ironclad).unwrap().attach(
        Attached::new(Modifier::Silence {
            until_tick: sim.tick + TICK_HZ,
        }),
        sim.tick,
    );
    let events = cast(&mut sim, ironclad, 0, Target::Point(at(500, 0)));
    assert_eq!(refusal(&events), Some(CastRefusal::Silenced));

    // Out of mana.
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    ready(&mut sim, witch);
    sim.entities.get_mut(witch).unwrap().mana = Fx::ZERO;
    let events = cast(&mut sim, witch, 0, Target::Point(at(100, 0)));
    assert_eq!(refusal(&events), Some(CastRefusal::NotEnoughMana));
}

#[test]
fn an_item_active_runs_through_the_same_path_as_a_spell() {
    // Firewall is an AbilitySpec in the same table as Bulwark and Pyre. If items had their own
    // system this would be a second shield implementation to keep in step with the first.
    let mut sim = arena();
    let ironclad = sim.spawn_hero_with(
        Team::Blue,
        Stats::melee_hero(),
        Fx::from_int(400),
        [ids::FIREWALL, ids::BULWARK, ids::TAUNT, ids::LAST_STAND],
    );
    // Deliberately in a *hero* slot, to prove an item's active runs the ordinary ability path —
    // so it needs a rank like any other hero ability. Its natural home in an item slot needs no
    // rank at all, which `economy.rs` covers.
    ready(&mut sim, ironclad);
    place(&mut sim, ironclad, at(0, 0));

    cast(&mut sim, ironclad, 0, Target::None);
    let shielded = sim
        .entities
        .get(ironclad)
        .unwrap()
        .has_status(sim.tick, |m| matches!(m, Modifier::Shield { .. }));
    assert!(shielded, "the item active granted no shield");

    let before = hp_of(&sim, ironclad);
    let mut events = Vec::new();
    sim.deal_damage(
        None,
        ironclad,
        Fx::from_int(150),
        DamageKind::Magical,
        &mut events,
    );
    assert_eq!(
        hp_of(&sim, ironclad),
        before,
        "the shield did not absorb the hit"
    );
}

#[test]
fn casting_a_channel_roots_the_caster() {
    // A cast time has to be a real commitment or every ability with one is free to use.
    let mut sim = arena();
    let ironclad = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    ready(&mut sim, ironclad);
    place(&mut sim, ironclad, at(0, 0));
    with_ultimate(&mut sim, ironclad);

    cast(&mut sim, ironclad, 3, Target::None);
    let start = pos_of(&sim, ironclad);
    for _ in 0..TICK_HZ {
        sim.step(&[Command::MoveTo {
            hero: ironclad,
            pos: at(2000, 0),
        }]);
    }
    assert_eq!(
        pos_of(&sim, ironclad),
        start,
        "the caster walked out of its own channel"
    );
}
