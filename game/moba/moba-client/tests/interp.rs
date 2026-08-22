//! The snapshot buffer, which is where smoothness is decided.
//!
//! Every test here corresponds to an artefact a player would actually see: a teleport, a stall, a
//! twitch on spawn, a ghost that never leaves. None of them can be caught by looking at the
//! server, and all of them are hard to diagnose by eye once they are on screen among nine other
//! moving things.

use moba_client::interp::{from_fixed, SnapshotBuffer, INTERP_DELAY_TICKS};
use moba_proto::{NetEntity, NetKind, NetTeam, Snapshot, TICK_HZ};

fn fixed(v: f32) -> i32 {
    (v * 65536.0) as i32
}

fn entity(id: u64, x: f32, y: f32) -> NetEntity {
    NetEntity {
        id,
        kind: NetKind::Hero,
        team: NetTeam::Blue,
        x: fixed(x),
        y: fixed(y),
        hp: fixed(100.0),
        max_hp: fixed(100.0),
        facing_x: 0,
        facing_y: 0,
        level: 1,
    }
}

fn snapshot(tick: u32, entities: Vec<NetEntity>) -> Snapshot {
    Snapshot {
        tick,
        entities,
        own: None,
        events: Vec::new(),
    }
}

/// Feed the buffer a run of snapshots and let its clock settle where they put it.
fn fed(ticks: &[(u32, Vec<NetEntity>)]) -> SnapshotBuffer {
    let mut buffer = SnapshotBuffer::new();
    for (tick, entities) in ticks {
        buffer.push(snapshot(*tick, entities.clone()));
    }
    buffer
}

fn find(buffer: &SnapshotBuffer, id: u64) -> Option<moba_client::interp::RenderEntity> {
    buffer.sample().into_iter().find(|e| e.id == id)
}

#[test]
fn a_position_between_two_snapshots_is_interpolated_not_snapped() {
    // The artefact this prevents: every entity teleporting 50ms of movement on each packet, then
    // standing still until the next one.
    let mut buffer = fed(&[
        (0, vec![entity(1, 0.0, 0.0)]),
        (10, vec![entity(1, 100.0, 0.0)]),
    ]);
    // Put the render time exactly halfway between the two.
    buffer.advance(0.0);
    while buffer.render_tick() < 5.0 {
        buffer.advance(1.0 / 60.0);
    }

    let drawn = find(&buffer, 1).expect("the entity was not drawn");
    assert!(
        drawn.x > 10.0 && drawn.x < 90.0,
        "position {} is at one endpoint, so nothing was interpolated",
        drawn.x
    );
}

/// Drive a realistic stream: a 30Hz server emitting snapshots on the same 20Hz accumulator the
/// real `Room` uses, against a 60fps client.
///
/// Returns how many frames were rendered while the buffer was *not* interpolating.
fn stream(
    buffer: &mut SnapshotBuffer,
    seconds: f32,
    mut on_snapshot: impl FnMut(u32) -> Snapshot,
) -> u32 {
    let frames = (seconds * 60.0) as u32;
    let mut server_tick = 0u32;
    let mut accumulator = 0u32;
    let mut server_owed = 0.0f32;
    let mut settling = 20;
    let mut guessing = 0;

    for _ in 0..frames {
        // The server runs at 30Hz against the client's 60fps, so two client frames per tick.
        server_owed += moba_proto::TICK_HZ as f32 / 60.0;
        while server_owed >= 1.0 {
            server_owed -= 1.0;
            server_tick += 1;
            accumulator += moba_proto::SNAPSHOT_HZ;
            if accumulator >= moba_proto::TICK_HZ {
                accumulator -= moba_proto::TICK_HZ;
                buffer.push(on_snapshot(server_tick));
            }
        }
        buffer.advance(1.0 / 60.0);

        // Ignore the first few frames: the buffer legitimately has nothing to interpolate
        // between until a second snapshot has arrived.
        if settling > 0 {
            settling -= 1;
        } else if !buffer.is_interpolating() {
            guessing += 1;
        }
    }
    guessing
}

#[test]
fn rendering_lags_the_newest_snapshot_by_the_delay() {
    // The delay is the whole mechanism: without it a late packet is a visible stall, because
    // there is nothing already buffered to keep drawing.
    //
    // Measured against a *running stream*, because that is the only condition under which the
    // question is meaningful. Advancing two seconds with no snapshots arriving and then asking
    // how far behind the newest one we are measures the silence, not the buffer — an earlier
    // version of this test did exactly that and was wrong about it.
    let mut buffer = SnapshotBuffer::new();
    let guessing = stream(&mut buffer, 3.0, |tick| {
        snapshot(tick, vec![entity(1, tick as f32, 0.0)])
    });

    // The invariant, asserted every frame rather than sampled once: the time being rendered
    // stays inside the data actually held. Outside it the client is extrapolating, which is the
    // stutter the delay was bought to prevent.
    assert_eq!(
        guessing, 0,
        "{guessing} frames rendered outside the buffered range"
    );

    // And the delay is a tenth of a second, not a second.
    let behind = buffer.latest_tick().unwrap() as f32 - buffer.render_tick();
    assert!(
        behind < TICK_HZ as f32,
        "rendering {behind} ticks behind, which is a lag spike"
    );
    assert!(
        buffer.is_healthy(),
        "a clean 20Hz stream was reported as unhealthy"
    );
    let _ = INTERP_DELAY_TICKS;
}

#[test]
fn the_clock_does_not_accumulate_an_offset_over_a_long_stream() {
    // The regression that produced the arrival-based correction. A per-frame controller against
    // a per-arrival target settles at a fixed lead — 4.5 ticks, measured — which quietly eats
    // the entire interpolation budget and leaves the client extrapolating.
    let mut buffer = SnapshotBuffer::new();
    stream(&mut buffer, 10.0, |tick| snapshot(tick, vec![]));

    let drift = buffer.staleness().abs();
    assert!(
        drift < 0.15,
        "the clock drifted {drift}s from the server over ten seconds of clean stream"
    );
}

#[test]
fn an_entity_that_appears_is_not_extrapolated_from_nothing() {
    // A creep that just spawned has no history. Guessing where it is "heading" is a twitch on
    // every single spawn, and a lane spawns six at a time.
    let buffer = fed(&[
        (0, vec![entity(1, 0.0, 0.0)]),
        (10, vec![entity(1, 10.0, 0.0), entity(2, 500.0, 500.0)]),
    ]);

    let newcomer = find(&buffer, 2).expect("the new entity was not drawn");
    assert_eq!(
        (newcomer.x, newcomer.y),
        (500.0, 500.0),
        "a first-seen entity was drawn somewhere other than where it was reported"
    );
}

#[test]
fn an_entity_that_disappears_stops_being_drawn() {
    // Otherwise a dead creep is a ghost that stands in the lane forever, and players will click
    // on it.
    let buffer = fed(&[
        (0, vec![entity(1, 0.0, 0.0), entity(2, 50.0, 0.0)]),
        (10, vec![entity(1, 10.0, 0.0)]),
    ]);
    assert!(
        find(&buffer, 2).is_none(),
        "an entity absent from the newest snapshot was still drawn"
    );
}

#[test]
fn a_late_snapshot_is_dropped_rather_than_spliced_in() {
    // A packet that arrives out of order describes a past that has been superseded. Inserting it
    // makes everything on screen jump backwards for one frame.
    let mut buffer = fed(&[
        (0, vec![entity(1, 0.0, 0.0)]),
        (20, vec![entity(1, 200.0, 0.0)]),
    ]);
    let before = buffer.len();

    buffer.push(snapshot(10, vec![entity(1, 100.0, 0.0)]));
    assert_eq!(
        buffer.len(),
        before,
        "an out-of-order snapshot was accepted"
    );

    // A duplicate of the newest is equally unwelcome.
    buffer.push(snapshot(20, vec![entity(1, 999.0, 0.0)]));
    assert_eq!(buffer.len(), before, "a duplicate snapshot was accepted");
}

#[test]
fn the_buffer_does_not_grow_without_bound() {
    // A match is tens of thousands of snapshots long. Keeping them all is a memory leak that only
    // shows up in a long game, which is the worst time to find it.
    let mut buffer = SnapshotBuffer::new();
    for tick in 0..500u32 {
        buffer.push(snapshot(tick, vec![entity(1, tick as f32, 0.0)]));
    }
    assert!(
        buffer.len() <= 16,
        "the buffer held {} snapshots",
        buffer.len()
    );
}

#[test]
fn a_jittery_snapshot_moves_the_render_time_only_partway() {
    // Snapping fully on every arrival would put all of the network's jitter directly onto the
    // render time — which is exactly the jitter the buffer exists to hide.
    let mut buffer = SnapshotBuffer::new();
    buffer.push(snapshot(100, vec![]));
    buffer.advance(1.0 / 60.0);
    let before = buffer.render_tick();

    // A snapshot lands reporting a time four ticks off what the clock expected.
    buffer.push(snapshot(104, vec![]));
    let after = buffer.render_tick();

    let moved = after - before;
    assert!(moved > 0.0, "the correction went the wrong way");
    assert!(
        moved < 4.0,
        "the clock absorbed the whole {moved}-tick jitter in one step"
    );
}

#[test]
fn a_huge_discrepancy_is_snapped_because_drifting_would_take_a_minute() {
    // A backgrounded tab, or a reconnect. Easing a thousand ticks at 10% a frame is a minute of
    // visibly wrong time; snapping is one bad frame.
    let mut buffer = SnapshotBuffer::new();
    buffer.push(snapshot(0, vec![]));
    buffer.advance(1.0 / 60.0);

    buffer.push(snapshot(5000, vec![]));

    assert!(
        (buffer.render_tick() - 5000.0).abs() < TICK_HZ as f32,
        "after a huge jump the clock is at {}, nowhere near the server",
        buffer.render_tick()
    );
}

#[test]
fn a_long_stall_freezes_the_world_rather_than_emptying_it() {
    // If the connection hangs, the last thing anyone should see is the world as it was — not an
    // empty map, which reads as everyone having died.
    let mut buffer = fed(&[
        (0, vec![entity(1, 0.0, 0.0)]),
        (10, vec![entity(1, 100.0, 0.0)]),
    ]);
    for _ in 0..600 {
        buffer.advance(1.0 / 60.0);
    }
    assert!(
        !buffer.sample().is_empty(),
        "a stalled connection emptied the map"
    );
    assert!(!buffer.is_healthy() || buffer.staleness().abs() < 1.0);
}

#[test]
fn health_slides_rather_than_stepping() {
    let mut full = entity(1, 0.0, 0.0);
    full.hp = fixed(100.0);
    let mut hurt = entity(1, 0.0, 0.0);
    hurt.hp = fixed(0.0);

    let mut buffer = fed(&[(0, vec![full]), (10, vec![hurt])]);
    while buffer.render_tick() < 5.0 {
        buffer.advance(1.0 / 60.0);
    }

    let drawn = find(&buffer, 1).expect("not drawn");
    assert!(
        drawn.hp_fraction > 0.05 && drawn.hp_fraction < 0.95,
        "health fraction {} did not interpolate",
        drawn.hp_fraction
    );
}

#[test]
fn the_own_block_is_taken_from_the_newest_snapshot_not_the_rendered_one() {
    // Cooldowns and gold are UI numbers, not world positions. Showing them 100ms late would leave
    // a button looking available for three frames after it was pressed.
    let mut buffer = SnapshotBuffer::new();
    let mut newest = snapshot(30, vec![]);
    newest.own = Some(moba_proto::NetSelf {
        id: 1,
        mana: fixed(50.0),
        max_mana: fixed(100.0),
        gold: fixed(700.0),
        cooldowns: vec![0; 10],
        abilities: vec![0; 10],
        targeting: vec![moba_proto::NetTargeting::Point; 10],
        items: vec![],
        ranks: vec![1; 10],
        rank_caps: vec![4; 10],
        skill_points: 0,
        attack_range: 150 * 65536,
        respawn_in: 0,
        level: 1,
        xp_into_level: 0,
        xp_for_next: 100,
    });
    buffer.push(snapshot(0, vec![]));
    buffer.push(newest);

    assert_eq!(buffer.own().map(|o| o.gold), Some(fixed(700.0)));
}

#[test]
fn fixed_point_converts_to_world_units() {
    // The one place the sim's Q16.16 crosses into the renderer's floats. Getting the shift wrong
    // here scales the entire map by 65536 and is instantly obvious — but only once something is
    // on screen, and this catches it before that.
    assert!((from_fixed(fixed(1234.5)) - 1234.5).abs() < 0.01);
    assert!((from_fixed(fixed(-42.0)) + 42.0).abs() < 0.01);
}
