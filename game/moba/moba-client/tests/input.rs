//! Clicks and keys becoming orders.
//!
//! Small rules, but each one is felt immediately when it is wrong: an armed ability with no way
//! out, a cancelled cast that also walks you into the enemy team, a message sent on every
//! selection click.

use moba_client::camera::Camera;
use moba_client::input::{slot_for_key, Armed, Input, MouseButton};
use moba_client::interp::RenderEntity;
use moba_proto::{ClientMessage, NetKind, NetTarget, NetTeam};

#[test]
fn right_click_commands_and_left_click_only_selects() {
    // The genre's convention, and the opposite of most games. Sending a message on every left
    // click would be a packet per selection for no effect.
    let mut input = Input::new();
    assert!(matches!(
        input.click(MouseButton::Right, 100, 200),
        Some(ClientMessage::MoveTo { x: 100, y: 200 })
    ));
    assert!(input.click(MouseButton::Left, 100, 200).is_none());
}

#[test]
fn a_right_click_on_an_enemy_attacks_it_rather_than_walking_into_it() {
    let mut input = Input::new();
    input.hovered = Some(77);
    assert!(matches!(
        input.click(MouseButton::Right, 0, 0),
        Some(ClientMessage::Attack { target: 77 })
    ));
}

#[test]
fn pressing_the_same_ability_twice_disarms_it() {
    // Without this a misclick is forced to become a cast, because there is no way out of an
    // armed ability. Every game in the genre has it and its absence is noticed at once.
    let mut input = Input::new();
    input.press_ability(0);
    assert_eq!(input.armed, Armed::Slot(0));
    input.press_ability(0);
    assert_eq!(input.armed, Armed::None);

    // A different ability replaces rather than stacking.
    input.press_ability(0);
    input.press_ability(2);
    assert_eq!(input.armed, Armed::Slot(2));
}

#[test]
fn an_armed_ability_fires_at_the_ground_or_at_whatever_is_hovered() {
    let mut input = Input::new();
    input.press_ability(1);
    match input.click(MouseButton::Left, 500, 600) {
        Some(ClientMessage::Cast {
            slot: 1,
            target: NetTarget::Point { x, y },
        }) => {
            assert_eq!((x, y), (500, 600))
        }
        other => panic!("expected a ground cast, got {other:?}"),
    }
    assert_eq!(
        input.armed,
        Armed::None,
        "the ability stayed armed after firing"
    );

    input.press_ability(3);
    input.hovered = Some(9);
    assert!(matches!(
        input.click(MouseButton::Left, 0, 0),
        Some(ClientMessage::Cast {
            slot: 3,
            target: NetTarget::Unit(9)
        })
    ));
}

#[test]
fn right_clicking_while_armed_cancels_without_also_walking() {
    // Issuing both would make every cancelled cast a walk into whatever you were aiming at.
    let mut input = Input::new();
    input.press_ability(0);
    assert!(
        input.click(MouseButton::Right, 900, 900).is_none(),
        "the cancel also issued an order"
    );
    assert_eq!(input.armed, Armed::None);
}

#[test]
fn stop_clears_an_armed_ability_too() {
    let mut input = Input::new();
    input.press_ability(2);
    assert!(matches!(input.stop(), ClientMessage::Stop));
    assert_eq!(input.armed, Armed::None);
}

#[test]
fn movement_keys_add_up_to_a_direction() {
    use moba_client::input::HeldKeys;

    let mut held = HeldKeys::default();
    assert_eq!(held.direction(), (0.0, 0.0));

    assert!(held.press("w"));
    assert_eq!(held.direction(), (0.0, -1.0), "up is negative y");

    // Diagonals are the whole reason keys are held as a set rather than as a last-pressed
    // direction: W and D together must mean north-east.
    assert!(held.press("d"));
    let (x, y) = held.direction();
    assert!(
        x > 0.6 && y < -0.6,
        "W+D did not produce a diagonal: {x},{y}"
    );
    assert!(
        (x * x + y * y - 1.0).abs() < 0.01,
        "the diagonal was not normalised"
    );

    // Opposite keys cancel, which is what holding both means.
    assert!(held.press("a"));
    let (x, _) = held.direction();
    assert!(x.abs() < 0.01, "A and D did not cancel");

    assert!(held.release("w"));
    assert!(
        !held.release("w"),
        "releasing a key twice reported a change"
    );

    // Losing focus lets go of everything, or a hero walks into the fog because the key-up
    // never arrived.
    held.press("s");
    assert!(held.clear());
    assert!(held.is_empty());
    assert_eq!(held.direction(), (0.0, 0.0));
}

#[test]
fn arrow_keys_work_like_wasd() {
    use moba_client::input::HeldKeys;
    let mut wasd = HeldKeys::default();
    let mut arrows = HeldKeys::default();
    wasd.press("a");
    arrows.press("ArrowLeft");
    assert_eq!(wasd.direction(), arrows.direction());
}

#[test]
fn abilities_avoid_the_movement_keys() {
    use moba_client::input::{direction_for_key, slot_for_key};
    // The rule that makes both schemes coexist: nothing is both a movement key and an ability
    // key. Q E R F are the four keys nearest WASD that WASD does not use.
    for key in [
        "w",
        "a",
        "s",
        "d",
        "ArrowUp",
        "ArrowDown",
        "ArrowLeft",
        "ArrowRight",
    ] {
        assert!(
            slot_for_key(key).is_none(),
            "{key} is both a movement key and an ability key"
        );
    }
    for key in ["q", "e", "r", "f", "1", "6"] {
        assert!(direction_for_key(key).is_none(), "{key} moves and casts");
    }
}

#[test]
fn qerf_are_the_hero_and_the_number_row_is_the_inventory() {
    // Must match the slot layout in `AbilitySlots`: 0..4 hero, 4..10 items. A mismatch here casts
    // the wrong thing, which is the kind of bug that survives a long time because it still does
    // *something*.
    assert_eq!(slot_for_key("q"), Some(0));
    assert_eq!(slot_for_key("e"), Some(1));
    assert_eq!(slot_for_key("R"), Some(2));
    assert_eq!(slot_for_key("f"), Some(3));
    assert_eq!(slot_for_key("1"), Some(4));
    assert_eq!(slot_for_key("6"), Some(9));
    assert_eq!(slot_for_key("z"), None);
    assert_eq!(slot_for_key("w"), None, "W is a movement key now");
}

// ── Camera ──────────────────────────────────────────────────────────────────────────────────

fn drawn(id: u64, x: f32, y: f32) -> RenderEntity {
    RenderEntity {
        id,
        kind: NetKind::Hero,
        team: NetTeam::Blue,
        x,
        y,
        hp_fraction: 1.0,
        level: 1,
    }
}

#[test]
fn screen_and_world_round_trip() {
    // The symptom of getting this wrong is clicks landing somewhere other than where they were
    // aimed — obvious in play, and maddening to trace back to arithmetic.
    let mut camera = Camera::new(1280.0, 720.0);
    camera.x = 400.0;
    camera.y = 900.0;
    camera.zoom = 0.7;

    let (sx, sy) = camera.world_to_screen(1234.0, -56.0);
    let (wx, wy) = camera.screen_to_world(sx, sy);
    assert!((wx - 1234.0).abs() < 0.01, "x round-tripped to {wx}");
    assert!((wy + 56.0).abs() < 0.01, "y round-tripped to {wy}");
}

#[test]
fn picking_prefers_the_nearest_thing_under_the_cursor() {
    let camera = Camera::new(800.0, 600.0);
    let entities = vec![drawn(1, 0.0, 0.0), drawn(2, 40.0, 0.0)];

    let (sx, sy) = camera.world_to_screen(35.0, 0.0);
    assert_eq!(camera.pick(&entities, sx, sy).map(|e| e.id), Some(2));

    // Well away from anything picks nothing rather than the least-far thing.
    let (sx, sy) = camera.world_to_screen(5000.0, 5000.0);
    assert!(camera.pick(&entities, sx, sy).is_none());
}

#[test]
fn the_camera_eases_rather_than_locking_to_the_hero() {
    // A hard lock transfers every interpolation wobble in the hero's position onto the whole
    // world, which reads as the map shaking.
    let mut camera = Camera::new(800.0, 600.0);
    camera.follow(1000.0, 0.0, 1.0 / 60.0);
    assert!(camera.x > 0.0, "the camera did not move at all");
    assert!(
        camera.x < 1000.0,
        "the camera snapped straight to the target"
    );
}

// ── Touch ───────────────────────────────────────────────────────────────────────────────────

#[test]
fn a_tap_is_a_right_click_because_a_finger_has_one_button() {
    // The genre's controls are built on the second mouse button. On touch there is only one
    // gesture available, so it has to be the *command* one — mapping it to left-click would
    // produce a game where nothing a phone player does moves their hero.
    let mut input = Input::new();
    assert!(matches!(
        input.tap(400, 500),
        Some(ClientMessage::MoveTo { x: 400, y: 500 })
    ));

    input.hovered = Some(12);
    assert!(matches!(
        input.tap(0, 0),
        Some(ClientMessage::Attack { target: 12 })
    ));
}

#[test]
fn a_tap_while_armed_fires_the_ability_instead_of_moving() {
    let mut input = Input::new();
    input.press_ability(2);
    match input.tap(700, 800) {
        Some(ClientMessage::Cast {
            slot: 2,
            target: NetTarget::Point { x, y },
        }) => {
            assert_eq!((x, y), (700, 800))
        }
        other => panic!("expected a cast, got {other:?}"),
    }
    assert_eq!(input.armed, Armed::None);
}

#[test]
fn an_ability_button_arms_unless_it_has_nothing_to_aim_at() {
    let mut input = Input::new();

    // The ordinary case: arm, then tap to aim.
    assert!(input.press_button(0, false).is_none());
    assert_eq!(input.armed, Armed::Slot(0));

    // A self-cast has no target, so making a phone player confirm it with a second tap would be
    // two gestures for what the keyboard does in one.
    input.cancel();
    match input.press_button(1, true) {
        Some(ClientMessage::Cast {
            slot: 1,
            target: NetTarget::None,
        }) => {}
        other => panic!("expected an immediate self-cast, got {other:?}"),
    }
    assert_eq!(input.armed, Armed::None);
}
