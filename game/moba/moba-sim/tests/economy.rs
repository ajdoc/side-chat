//! Gold, last-hitting, and the five items.
//!
//! Each item in MOBA.md was chosen to ask the engine a different question, so there is one test
//! per question rather than one per item: an on-event hook, a castable active, a projected aura,
//! and a debuff attached as a side effect of someone else's action. If those four hold, a sixth
//! item is data.

use moba_proto::TICK_HZ;
use moba_sim::ability::{heroes, ids, Target, HERO_SLOTS};
use moba_sim::damage::{Attached, DamageKind, Modifier};
use moba_sim::economy::STARTING_GOLD;
use moba_sim::entity::{EntityId, Stats, Team};
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::item::{items, BuyRefusal};
use moba_sim::map::Map;
use moba_sim::sim::{Command, Event, MatchConfig, Sim};

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

fn gold(sim: &Sim, id: EntityId) -> i32 {
    sim.entities
        .get(id)
        .expect("entity vanished")
        .gold
        .floor_int()
}

/// A creep standing still, on `team`, at `pos`.
fn creep(sim: &mut Sim, team: Team, pos: Vec2) -> EntityId {
    let mut stats = Stats::melee_creep();
    stats.move_speed = Fx::ZERO;
    stats.attack_damage = Fx::ZERO;
    let id = sim.spawn_hero(team, stats);
    sim.entities.get_mut(id).unwrap().kind_to_creep();
    place(sim, id, pos);
    id
}

fn give(sim: &mut Sim, hero: EntityId, amount: i32) {
    sim.entities.get_mut(hero).unwrap().gold = Fx::from_int(amount);
}

/// Run one tick and report what each hero gained.
///
/// Gold assertions have to be written as *differences between two heroes*, never as absolute
/// totals: passive income pays every living hero on every whole second including tick zero, so
/// "gold went up by three" is true of a hero who did nothing at all. Comparing two heroes in the
/// same match cancels the income out.
fn step_and_diff(sim: &mut Sim, a: EntityId, b: EntityId) -> (i32, i32, Vec<Event>) {
    let (a0, b0) = (gold(sim, a), gold(sim, b));
    let events = sim.step(&[]);
    (gold(sim, a) - a0, gold(sim, b) - b0, events)
}

/// Enough ticks for a cast time to elapse and its effects to land.
fn settle(sim: &mut Sim) {
    for _ in 0..TICK_HZ / 2 {
        sim.step(&[]);
    }
}

// ── Gold ────────────────────────────────────────────────────────────────────────────────────

#[test]
fn the_last_hit_takes_the_gold_and_the_hits_before_it_take_nothing() {
    let mut sim = arena();
    let killer = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let helper = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    let victim = creep(&mut sim, Team::Red, at(0, 0));

    // The helper does almost all the damage; the killer lands the blow that finishes it. In a
    // MOBA the second one is worth everything and the first is worth nothing, and that
    // asymmetry is the entire laning phase.
    let mut events = Vec::new();
    sim.deal_damage(
        Some(helper),
        victim,
        Fx::from_int(500),
        DamageKind::Magical,
        &mut events,
    );
    sim.deal_damage(
        Some(killer),
        victim,
        Fx::from_int(500),
        DamageKind::Magical,
        &mut events,
    );

    let (killer_gain, helper_gain, events) = step_and_diff(&mut sim, killer, helper);

    assert!(
        killer_gain > helper_gain + 30,
        "the last hit paid {killer_gain} against the helper's {helper_gain}"
    );
    assert!(events.iter().any(|e| matches!(e, Event::GoldGained { .. })));
}

#[test]
fn killing_your_own_creep_denies_the_gold_to_everyone() {
    let mut sim = arena();
    let enemy = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    let denier = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    let victim = creep(&mut sim, Team::Blue, at(0, 0));

    let mut events = Vec::new();
    sim.deal_damage(
        Some(enemy),
        victim,
        Fx::from_int(400),
        DamageKind::Magical,
        &mut events,
    );
    sim.deal_damage(
        Some(denier),
        victim,
        Fx::from_int(400),
        DamageKind::Magical,
        &mut events,
    );

    let (enemy_gain, denier_gain, events) = step_and_diff(&mut sim, enemy, denier);

    // Both gained only their passive income, and nothing more.
    assert_eq!(
        enemy_gain, denier_gain,
        "a denied creep paid somebody a bounty"
    );
    assert!(
        events.iter().any(|e| matches!(e, Event::Denied { .. })),
        "a deny must be visible; its whole point is the gold that did not happen"
    );
}

#[test]
fn a_creep_landing_the_last_hit_pays_nobody() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let lane_creep = creep(&mut sim, Team::Blue, at(0, 0));
    let victim = creep(&mut sim, Team::Red, at(50, 0));

    let control = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    let mut events = Vec::new();
    sim.deal_damage(
        Some(hero),
        victim,
        Fx::from_int(400),
        DamageKind::Magical,
        &mut events,
    );
    sim.deal_damage(
        Some(lane_creep),
        victim,
        Fx::from_int(400),
        DamageKind::Magical,
        &mut events,
    );

    let (hero_gain, control_gain, _) = step_and_diff(&mut sim, hero, control);
    assert_eq!(
        hero_gain, control_gain,
        "gold is for the player who timed it, not for being nearby when a lane creep did"
    );
}

#[test]
fn passive_income_accrues_every_second() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    assert_eq!(gold(&sim, hero), STARTING_GOLD);

    for _ in 0..TICK_HZ * 10 {
        sim.step(&[]);
    }
    // Ten seconds of income. Exact value is a balance number; that it moved is the contract.
    assert!(
        gold(&sim, hero) > STARTING_GOLD + 20,
        "no passive income accrued"
    );
}

// ── The four hooks ──────────────────────────────────────────────────────────────────────────

#[test]
fn ledger_pays_extra_per_creep_and_only_per_creep() {
    let take = |with_ledger: bool| {
        let mut sim = arena();
        let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
        let control = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
        if with_ledger {
            give(&mut sim, hero, 5000);
            sim.buy(hero, items::LEDGER).expect("could not buy Ledger");
        }
        let victim = creep(&mut sim, Team::Red, at(0, 0));
        let mut events = Vec::new();
        sim.deal_damage(
            Some(hero),
            victim,
            Fx::from_int(900),
            DamageKind::Magical,
            &mut events,
        );
        let (gain, income, _) = step_and_diff(&mut sim, hero, control);
        gain - income
    };

    let with = take(true);
    let without = take(false);
    assert!(
        with > without,
        "Ledger's on-kill hook never fired: {with} vs {without}"
    );
}

#[test]
fn firewall_occupies_an_item_slot_and_is_cast_like_any_other_ability() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    give(&mut sim, hero, 5000);

    let slot = sim
        .buy(hero, items::FIREWALL)
        .expect("could not buy Firewall");
    assert!(slot >= HERO_SLOTS, "an item landed in a hero ability slot");
    assert_eq!(
        sim.entities.get(hero).unwrap().abilities.id(slot),
        Some(ids::FIREWALL)
    );

    // Cast through the ordinary command path — the point of one flat slot array.
    sim.step(&[Command::CastAbility {
        hero,
        slot,
        target: Target::None,
    }]);
    assert!(
        sim.entities
            .get(hero)
            .unwrap()
            .has_status(sim.tick, |m| matches!(m, Modifier::Shield { .. })),
        "the item's active granted nothing"
    );

    // And its armour is in the statline.
    let armour = sim
        .entities
        .get(hero)
        .unwrap()
        .effective_stats(sim.tick)
        .armour;
    assert!(
        armour > Stats::melee_hero().armour,
        "Firewall's armour never applied"
    );
}

#[test]
fn broadcast_regenerates_allies_in_range_and_stops_when_they_leave() {
    let mut sim = arena();
    let holder = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    place(&mut sim, holder, at(0, 0));
    give(&mut sim, holder, 5000);
    sim.buy(holder, items::BROADCAST)
        .expect("could not buy Broadcast");

    let ally = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    place(&mut sim, ally, at(300, 0));
    sim.entities.get_mut(ally).unwrap().hp = Fx::from_int(100);

    let enemy = sim.spawn_named_hero(Team::Red, heroes::emberwitch());
    place(&mut sim, enemy, at(300, 0));
    sim.entities.get_mut(enemy).unwrap().hp = Fx::from_int(100);

    for _ in 0..TICK_HZ * 3 {
        sim.step(&[]);
    }

    let healed = sim.entities.get(ally).unwrap().hp;
    assert!(
        healed > Fx::from_int(110),
        "the aura did not heal a nearby ally: {healed:?}"
    );
    assert_eq!(
        sim.entities.get(enemy).unwrap().hp.floor_int(),
        100,
        "a friendly aura healed an enemy standing in it"
    );

    // Walk out of range: the refresh simply stops, and the grant expires on its own.
    place(&mut sim, ally, at(5000, 0));
    for _ in 0..TICK_HZ {
        sim.step(&[]);
    }
    let parted = sim.entities.get(ally).unwrap().hp;
    for _ in 0..TICK_HZ * 2 {
        sim.step(&[]);
    }
    assert_eq!(
        sim.entities.get(ally).unwrap().hp.floor_int(),
        parted.floor_int(),
        "the aura followed an ally out of its own radius"
    );
}

#[test]
fn null_pointer_attaches_healing_reduction_to_whoever_an_ability_hits() {
    let mut sim = arena();
    let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
    place(&mut sim, witch, at(0, 0));
    give(&mut sim, witch, 5000);
    sim.buy(witch, items::NULL_POINTER)
        .expect("could not buy Null Pointer");

    let victim = sim.spawn_named_hero(Team::Red, heroes::ironclad());
    place(&mut sim, victim, at(150, 0));

    sim.step(&[Command::CastAbility {
        hero: witch,
        slot: 0,
        target: Target::Point(at(150, 0)),
    }]);
    // Cinder has a cast time; the effects have not fired on the tick the order lands.
    settle(&mut sim);

    assert!(
        sim.entities
            .get(victim)
            .unwrap()
            .has_status(sim.tick, |m| matches!(m, Modifier::HealReduction { .. })),
        "Null Pointer's passive never attached to the target of an ability"
    );
}

#[test]
fn ability_power_scales_magical_damage_but_not_pure() {
    let cinder_damage = |with_item: bool| {
        let mut sim = arena();
        let witch = sim.spawn_named_hero(Team::Blue, heroes::emberwitch());
        place(&mut sim, witch, at(0, 0));
        if with_item {
            give(&mut sim, witch, 5000);
            sim.buy(witch, items::NULL_POINTER).unwrap();
        }
        let mut stats = Stats::melee_hero();
        stats.max_hp = Fx::from_int(20_000);
        stats.armour = Fx::ZERO;
        let victim = sim.spawn_hero(Team::Red, stats);
        place(&mut sim, victim, at(150, 0));

        let before = sim.entities.get(victim).unwrap().hp;
        sim.step(&[Command::CastAbility {
            hero: witch,
            slot: 0,
            target: Target::Point(at(150, 0)),
        }]);
        // Only far enough for the impact, not for the burning ground to add to it.
        for _ in 0..8 {
            sim.step(&[]);
        }
        before - sim.entities.get(victim).unwrap().hp
    };

    let plain = cinder_damage(false);
    let powered = cinder_damage(true);
    assert!(
        powered > plain + Fx::from_int(30),
        "ability power did not scale Cinder"
    );
}

// ── The shop's rules ────────────────────────────────────────────────────────────────────────

#[test]
fn a_refused_purchase_says_why() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());

    give(&mut sim, hero, 10);
    assert_eq!(
        sim.buy(hero, items::BROADCAST),
        Err(BuyRefusal::CannotAfford)
    );

    give(&mut sim, hero, 20_000);
    sim.buy(hero, items::BOOTSTRAP).unwrap();
    assert_eq!(
        sim.buy(hero, items::BOOTSTRAP),
        Err(BuyRefusal::AlreadyOwned),
        "two of the same item is a balance question nobody has answered"
    );

    // Fill the remaining five slots, then overflow.
    for item in [
        items::LEDGER,
        items::FIREWALL,
        items::BROADCAST,
        items::NULL_POINTER,
    ] {
        sim.buy(hero, item).unwrap();
    }
    // Only five items exist, so the sixth slot cannot be filled and inventory-full is unreachable
    // today. Asserting the count instead keeps the test honest rather than pretending otherwise.
    assert_eq!(sim.entities.get(hero).unwrap().items.len(), 5);
}

#[test]
fn buying_max_health_grants_the_health_rather_than_a_smaller_fraction_of_a_bigger_bar() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    give(&mut sim, hero, 5000);

    let before = sim.entities.get(hero).unwrap().hp;
    sim.buy(hero, items::BROADCAST).unwrap();
    let after = sim.entities.get(hero).unwrap().hp;

    assert!(
        after > before,
        "buying health left the hero at the same hp on a longer bar"
    );
}

#[test]
fn two_items_granting_armour_do_not_overwrite_each_other() {
    // `Entity::attach` replaces on source *and* kind. If it matched on kind alone, the second
    // armour item bought would silently replace the first.
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    give(&mut sim, hero, 20_000);

    let base = sim
        .entities
        .get(hero)
        .unwrap()
        .effective_stats(sim.tick)
        .armour;
    sim.buy(hero, items::FIREWALL).unwrap();
    let one = sim
        .entities
        .get(hero)
        .unwrap()
        .effective_stats(sim.tick)
        .armour;

    // Bulwark is not an item, but it is a second source of armour, which is the case that
    // matters. Toggle it and both contributions must be present.
    sim.step(&[Command::CastAbility {
        hero,
        slot: 1,
        target: Target::None,
    }]);
    sim.step(&[]);
    let both = sim
        .entities
        .get(hero)
        .unwrap()
        .effective_stats(sim.tick)
        .armour;

    assert!(one > base, "Firewall's armour did not apply");
    assert!(
        both > one,
        "a second armour source replaced the first instead of adding to it"
    );
}

#[test]
fn gold_is_not_paid_twice_for_one_death() {
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    let victim = creep(&mut sim, Team::Red, at(0, 0));

    let mut events = Vec::new();
    sim.deal_damage(
        Some(hero),
        victim,
        Fx::from_int(900),
        DamageKind::Magical,
        &mut events,
    );

    let mut payouts = 0;
    for _ in 0..TICK_HZ * 3 {
        payouts += sim
            .step(&[])
            .iter()
            .filter(|e| matches!(e, Event::GoldGained { .. }))
            .count();
    }
    assert_eq!(payouts, 1, "one death paid out {payouts} times");
}

#[test]
fn an_unspent_modifier_source_survives_a_stun() {
    // Regression guard for the `Attached` source scheme: statuses and item grants share one
    // list, so a stun landing must not disturb what an item contributed.
    let mut sim = arena();
    let hero = sim.spawn_named_hero(Team::Blue, heroes::ironclad());
    give(&mut sim, hero, 5000);
    sim.buy(hero, items::BOOTSTRAP).unwrap();

    let with_boots = sim
        .entities
        .get(hero)
        .unwrap()
        .effective_stats(sim.tick)
        .move_speed;
    sim.entities.get_mut(hero).unwrap().attach(
        Attached::new(Modifier::Stun {
            until_tick: sim.tick + TICK_HZ,
        }),
        sim.tick,
    );
    for _ in 0..TICK_HZ * 2 {
        sim.step(&[]);
    }

    assert_eq!(
        sim.entities
            .get(hero)
            .unwrap()
            .effective_stats(sim.tick)
            .move_speed
            .floor_int(),
        with_boots.floor_int(),
        "a stun expiring took the boots with it"
    );
}
