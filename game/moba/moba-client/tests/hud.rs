//! The ability bar's geometry.
//!
//! Worth testing precisely because it is invisible when correct and infuriating when not: on a
//! phone these rectangles *are* the controls, and a bar that overflows the screen puts the item
//! slots off the edge, where they cannot be reached at all.

use moba_client::effects::{EffectKind, Effects};
use moba_client::hud::{Hud, SLOT_COUNT};
use moba_proto::{NetEvent, NetSelf};

fn own(cooldowns: Vec<u32>, items: usize) -> NetSelf {
    NetSelf {
        id: 1,
        mana: 0,
        max_mana: 0,
        gold: 0,
        cooldowns,
        abilities: vec![0; 10],
        targeting: vec![moba_proto::NetTargeting::Point; 10],
        items: (0..items).map(|i| i as u16).collect(),
        attack_range: 150 * 65536,
        respawn_in: 0,
        level: 1,
        xp_into_level: 0,
        xp_for_next: 100,
    }
}

#[test]
fn the_bar_has_a_slot_for_every_ability_and_every_item() {
    // Must match `AbilitySlots` in the sim: four hero, six item. A mismatch means tapping the
    // third button casts something other than the third ability.
    let hud = Hud::layout(1280.0, 720.0, None);
    assert_eq!(hud.slots.len(), SLOT_COUNT);
    assert_eq!(hud.slots[0].label, "Q");
    assert_eq!(hud.slots[3].label, "R");
    assert_eq!(hud.slots[4].label, "1");
    assert_eq!(hud.slots[9].label, "6");
}

#[test]
fn the_bar_fits_inside_a_narrow_phone_rather_than_running_off_the_edge() {
    // The failure this prevents is not cosmetic: an overflowing bar puts the item slots past the
    // right edge of a phone, where no tap can ever reach them.
    for width in [320.0f32, 375.0, 414.0, 768.0, 1920.0] {
        let hud = Hud::layout(width, 700.0, None);
        let first = hud.slots.first().unwrap().rect;
        let last = hud.slots.last().unwrap().rect;
        assert!(first.x >= 0.0, "bar starts off-screen at width {width}");
        assert!(
            last.x + last.w <= width,
            "bar ends at {} on a {width}-wide screen",
            last.x + last.w
        );
    }
}

#[test]
fn buttons_are_bigger_on_a_touch_sized_viewport() {
    // A finger is not a mouse pointer. Below the phone threshold the buttons grow, subject to
    // still fitting.
    let phone = Hud::layout(414.0, 800.0, None);
    let desktop = Hud::layout(1600.0, 900.0, None);
    assert!(phone.touch_sized);
    assert!(!desktop.touch_sized);
    assert!(
        phone.slots[0].rect.h >= 30.0,
        "phone buttons are too small to hit reliably"
    );
}

#[test]
fn the_bar_sits_along_the_bottom_and_stays_on_screen() {
    let hud = Hud::layout(900.0, 600.0, None);
    let rect = hud.slots[0].rect;
    assert!(rect.y > 400.0, "the bar is not at the bottom");
    assert!(
        rect.y + rect.h <= 600.0,
        "the bar hangs off the bottom edge"
    );
}

#[test]
fn hit_testing_finds_the_button_under_a_tap_and_nothing_elsewhere() {
    let hud = Hud::layout(1280.0, 720.0, None);
    let target = hud.slots[6].rect;

    assert_eq!(
        hud.hit(target.x + target.w / 2.0, target.y + target.h / 2.0),
        Some(6)
    );
    // Well above the bar is the world, not the HUD — otherwise a tap meant to move the hero
    // would silently arm an ability.
    assert_eq!(hud.hit(target.x, 100.0), None);
    assert!(!hud.contains(target.x, 100.0));
    assert!(hud.contains(target.x + 2.0, target.y + 2.0));
}

#[test]
fn a_cooldown_shows_as_progress_and_a_ready_ability_shows_as_none() {
    let hot = Hud::layout(
        1280.0,
        720.0,
        Some(&own(vec![300, 0, 0, 0, 0, 0, 0, 0, 0, 0], 0)),
    );
    assert!(
        hot.slots[0].cooldown > 0.0,
        "an ability on cooldown showed as ready"
    );
    assert_eq!(
        hot.slots[1].cooldown, 0.0,
        "a ready ability showed as on cooldown"
    );
}

#[test]
fn empty_item_slots_are_marked_empty() {
    let hud = Hud::layout(1280.0, 720.0, Some(&own(vec![0; 10], 2)));
    assert!(hud.slots[0].filled, "a hero ability slot was marked empty");
    assert!(hud.slots[4].filled, "the first owned item was marked empty");
    assert!(
        !hud.slots[8].filled,
        "an unowned item slot was marked filled"
    );
}

// ── Effects ─────────────────────────────────────────────────────────────────────────────────

#[test]
fn a_hit_produces_a_number_and_a_line_back_to_whatever_hit_you() {
    // The gap that made the first playable build unreadable: you could see your health drop and
    // had no way to tell whether a creep, a tower or a hero had done it.
    let mut effects = Effects::new();
    effects.ingest(
        &[NetEvent::Damaged {
            source: Some(9),
            target: 1,
            amount: 40 * 65536,
        }],
        Some(1),
    );

    let kinds: Vec<_> = effects.iter().map(|e| e.kind).collect();
    assert!(
        kinds.iter().any(|k| matches!(
            k,
            EffectKind::DamageNumber {
                amount: 40,
                on_self: true
            }
        )),
        "no damage number, or it was not marked as landing on the viewer: {kinds:?}"
    );
    assert!(
        kinds
            .iter()
            .any(|k| matches!(k, EffectKind::HitLine { from: 9, to: 1 })),
        "no line back to the attacker: {kinds:?}"
    );
}

#[test]
fn a_hit_from_inside_the_fog_produces_a_number_but_no_line() {
    // The server withholds the source when the viewer cannot see it, so being shot from the fog
    // must not draw a line pointing at the shooter — that would be a free ward.
    let mut effects = Effects::new();
    effects.ingest(
        &[NetEvent::Damaged {
            source: None,
            target: 1,
            amount: 40 * 65536,
        }],
        Some(1),
    );
    assert!(!effects
        .iter()
        .any(|e| matches!(e.kind, EffectKind::HitLine { .. })));
    assert!(effects
        .iter()
        .any(|e| matches!(e.kind, EffectKind::DamageNumber { .. })));
}

#[test]
fn effects_expire_so_the_screen_clears_itself() {
    let mut effects = Effects::new();
    effects.ingest(
        &[
            NetEvent::AbilityCast {
                entity: 1,
                ability: 0,
                x: 0,
                y: 0,
            },
            NetEvent::Died { entity: 2 },
            NetEvent::Damaged {
                source: Some(1),
                target: 2,
                amount: 65536,
            },
        ],
        Some(1),
    );
    assert!(!effects.is_empty());

    effects.advance(3.0);
    assert!(
        effects.is_empty(),
        "{} effects outlived their lifetimes",
        effects.len()
    );
}

#[test]
fn a_teamfight_cannot_grow_the_effect_list_without_bound() {
    // Ten heroes and forty creeps produce a great many damage events per second. An unbounded
    // list would make the worst moment of the game the slowest one.
    let mut effects = Effects::new();
    for tick in 0..5000u64 {
        effects.ingest(
            &[NetEvent::Damaged {
                source: Some(1),
                target: tick,
                amount: 65536,
            }],
            Some(1),
        );
    }
    assert!(
        effects.len() <= 200,
        "the effect list grew to {}",
        effects.len()
    );
}

#[test]
fn progress_runs_from_zero_to_one_over_an_effects_lifetime() {
    let mut effects = Effects::new();
    effects.ingest(&[NetEvent::Died { entity: 1 }], None);
    let start = effects.iter().next().unwrap().progress();
    effects.advance(0.3);
    let later = effects.iter().next().map(|e| e.progress()).unwrap_or(1.0);
    assert!(start < 0.1, "an effect began part-way through its life");
    assert!(later > start, "progress did not advance");
}

#[test]
fn damage_landing_on_someone_else_is_not_marked_as_landing_on_you() {
    let mut effects = Effects::new();
    effects.ingest(
        &[NetEvent::Damaged {
            source: Some(1),
            target: 5,
            amount: 65536,
        }],
        Some(1),
    );
    assert!(effects
        .iter()
        .any(|e| matches!(e.kind, EffectKind::DamageNumber { on_self: false, .. })));
}
