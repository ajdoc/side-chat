//! The match lifecycle, and the fog-of-war guarantee, with no socket involved.
//!
//! That these run at all is the point of keeping `Room` free of networking: filling, starting,
//! disconnecting and snapshotting are the parts most likely to be wrong, and none of them needs
//! a port to exercise.

use moba_proto::{NetKind, NetTarget, ServerMessage};
use moba_server::room::{ClientIntent, HeroChoice, Phase, Room};
use moba_sim::entity::Team;
use moba_sim::fixed::{Fx, Vec2};
use moba_sim::sim::MatchConfig;

fn config(team_size: u8) -> MatchConfig {
    MatchConfig {
        team_size,
        ..MatchConfig::default()
    }
}

fn filled(team_size: u8) -> Room {
    let mut room = Room::new(config(team_size));
    for i in 0..room.capacity() {
        let choice = if i % 2 == 0 {
            HeroChoice::Ironclad
        } else {
            HeroChoice::Emberwitch
        };
        room.join(choice).expect("could not seat a player");
    }
    room.start();
    room
}

// ── Team size ───────────────────────────────────────────────────────────────────────────────

#[test]
fn a_one_v_one_needs_two_players_and_a_five_v_five_needs_ten() {
    // The whole reason team size is a parameter: a 5v5 cannot be played by one person, and a
    // mode that cannot be played until it is finished never gets tested.
    assert_eq!(Room::new(config(1)).capacity(), 2);
    assert_eq!(Room::new(config(3)).capacity(), 6);
    assert_eq!(Room::new(config(5)).capacity(), 10);
}

#[test]
fn the_room_fills_evenly_and_refuses_an_eleventh() {
    let mut room = Room::new(config(2));
    for _ in 0..4 {
        room.join(HeroChoice::Ironclad).expect("could not seat");
    }
    assert!(room.is_full());
    assert_eq!(
        room.join(HeroChoice::Ironclad),
        None,
        "a full room seated another player"
    );

    let blue = room.seats.iter().filter(|s| s.team == Team::Blue).count();
    let red = room.seats.iter().filter(|s| s.team == Team::Red).count();
    assert_eq!(
        (blue, red),
        (2, 2),
        "the room filled unevenly: {blue} blue, {red} red"
    );
}

#[test]
fn a_partly_filled_room_is_as_even_as_it_can_be() {
    // Matters when a 1v1 has one player in it: the next arrival must land on the other team.
    let mut room = Room::new(config(3));
    room.join(HeroChoice::Ironclad).unwrap();
    room.join(HeroChoice::Ironclad).unwrap();
    room.join(HeroChoice::Ironclad).unwrap();

    let blue = room.seats.iter().filter(|s| s.team == Team::Blue).count();
    let red = room.seats.iter().filter(|s| s.team == Team::Red).count();
    assert!(blue.abs_diff(red) <= 1, "three players split {blue}/{red}");
}

#[test]
fn structures_and_waves_scale_with_the_team_size() {
    // A 4500-hp base is a fair objective for five players and a twenty-minute chore for one.
    let small = Room::new(config(1));
    let large = Room::new(config(5));

    let base_hp = |room: &Room| {
        room.sim
            .entities
            .iter()
            .find(|(_, e)| e.kind == moba_sim::entity::EntityKind::Base)
            .map(|(_, e)| e.hp.floor_int())
            .expect("no base on the map")
    };

    assert!(
        base_hp(&small) < base_hp(&large),
        "a 1v1 base is as tough as a 5v5 base"
    );
    assert!(
        small.sim.wave_size() < large.sim.wave_size(),
        "wave size did not scale"
    );
    assert!(
        small.sim.wave_size() >= 1,
        "a 1v1 lane has no creeps in it at all"
    );
}

// ── Lifecycle ───────────────────────────────────────────────────────────────────────────────

#[test]
fn a_match_starts_only_once_it_is_full_and_gives_everyone_a_hero() {
    let mut room = Room::new(config(1));
    assert_eq!(room.phase, Phase::Filling);

    room.join(HeroChoice::Ironclad).unwrap();
    room.start();
    assert_eq!(room.phase, Phase::Filling, "a half-empty room started");

    room.join(HeroChoice::Emberwitch).unwrap();
    room.start();
    assert_eq!(room.phase, Phase::Running);
    assert!(
        room.seats.iter().all(|s| s.hero.is_some()),
        "a seat started without a hero"
    );
}

#[test]
fn the_lobby_message_reports_progress() {
    let mut room = Room::new(config(2));
    room.join(HeroChoice::Ironclad).unwrap();
    match room.lobby_message() {
        ServerMessage::Lobby { present, needed } => assert_eq!((present, needed), (1, 4)),
        other => panic!("expected a lobby message, got {other:?}"),
    }
}

#[test]
fn snapshots_are_due_at_the_snapshot_rate_not_the_tick_rate() {
    let mut room = filled(1);
    let due = (0..moba_proto::TICK_HZ).filter(|_| room.tick(&[])).count() as u32;
    // 20Hz over 30 ticks. Sending every tick would be half again the bandwidth for something
    // the client interpolates over anyway.
    assert!(
        (18..=22).contains(&due),
        "{due} snapshots in a second, expected about {}",
        moba_proto::SNAPSHOT_HZ
    );
}

#[test]
fn no_damage_event_is_lost_between_snapshots() {
    // Snapshots go out at 20Hz over a 30Hz tick, so one tick in three produces events that no
    // snapshot is sent on. Those must be held for the next one, or a third of every hit effect
    // silently never reaches a client.
    //
    // Asserted as a conservation law rather than "at least one event arrived": the damage the
    // victim actually lost must equal the damage the snapshots reported. That catches a dropped
    // event, a double-sent event, and a mis-scaled amount, where a presence check catches none
    // of them.
    let mut room = filled(1);
    let attacker = room.seats[0].hero.unwrap();
    let victim = room.seats[1].hero.unwrap();

    room.sim.entities.get_mut(attacker).unwrap().pos = Vec2::ZERO;
    room.sim.entities.get_mut(victim).unwrap().pos = Vec2::new(Fx::from_int(60), Fx::ZERO);
    let before = room.sim.entities.get(victim).unwrap().hp;

    let attack = room
        .command_from(
            0,
            ClientIntent::Attack {
                target: victim.to_net(),
            },
        )
        .expect("the attack order was refused");

    let mut reported = 0i64;
    for _ in 0..moba_proto::TICK_HZ * 5 {
        if room.tick(&[attack]) {
            let snapshot = room.snapshot_for(0).expect("no snapshot");
            for event in &snapshot.events {
                if let moba_proto::NetEvent::Damaged { amount, .. } = event {
                    reported += *amount as i64;
                }
            }
            room.clear_pending();
        }
    }

    let lost = (before - room.sim.entities.get(victim).unwrap().hp).raw() as i64;
    assert!(
        lost > 0,
        "the attacker never landed a hit, so the test proves nothing"
    );
    assert_eq!(
        reported, lost,
        "snapshots reported {reported} of {lost} damage actually dealt"
    );
}

// ── Fog of war ──────────────────────────────────────────────────────────────────────────────

#[test]
fn an_enemy_across_the_map_is_not_in_the_bytes() {
    // The anti-cheat, such as it is. A client cannot draw what it was never sent, so maphack has
    // nothing to hack — see `moba_sim::net`.
    let mut room = filled(1);
    let blue_hero = room.seats[0].hero.unwrap();
    let red_hero = room.seats[1].hero.unwrap();

    // Real, open positions on the three-lane map. The originals were (0,0) and (9000,9000),
    // which were fine when the map was scenery and are now *inside the boundary wall* — from
    // which nothing has line of sight to anything, so the test would have passed for the wrong
    // reason.
    room.sim.entities.get_mut(blue_hero).unwrap().pos =
        Vec2::new(Fx::from_int(3000), Fx::from_int(3000));
    room.sim.entities.get_mut(red_hero).unwrap().pos =
        Vec2::new(Fx::from_int(5100), Fx::from_int(900));
    room.tick(&[]);

    let snapshot = room.snapshot_for(0).expect("no snapshot");
    let red_id = red_hero.to_net();
    assert!(
        !snapshot.entities.iter().any(|e| e.id == red_id),
        "an enemy on the far side of the map was sent to the client anyway"
    );

    // Walk them into vision, in the open and with a clear line, and they appear.
    room.sim.entities.get_mut(red_hero).unwrap().pos =
        Vec2::new(Fx::from_int(3300), Fx::from_int(2700));
    room.tick(&[]);
    let snapshot = room.snapshot_for(0).expect("no snapshot");
    assert!(
        snapshot.entities.iter().any(|e| e.id == red_id),
        "an enemy standing next to you was invisible"
    );
}

#[test]
fn your_own_team_is_always_visible_however_far_away() {
    let mut room = filled(2);
    let mine = room.seats[0].hero.unwrap();
    let ally = room.seats[2].hero.unwrap();

    room.sim.entities.get_mut(mine).unwrap().pos =
        Vec2::new(Fx::from_int(3000), Fx::from_int(3000));
    room.sim.entities.get_mut(ally).unwrap().pos = Vec2::new(Fx::from_int(5100), Fx::from_int(900));
    room.tick(&[]);

    let snapshot = room.snapshot_for(0).expect("no snapshot");
    assert!(
        snapshot.entities.iter().any(|e| e.id == ally.to_net()),
        "a teammate across the map was fogged out"
    );
}

#[test]
fn only_your_own_gold_and_cooldowns_are_sent_to_you() {
    let mut room = filled(1);
    room.tick(&[]);

    let mine = room.snapshot_for(0).expect("no snapshot");
    let own = mine.own.expect("no own-hero block");
    assert_eq!(own.id, room.seats[0].hero.unwrap().to_net());
    assert!(own.gold > 0, "the own block carried no gold");

    // The other seat's snapshot describes the other hero, not this one.
    let theirs = room.snapshot_for(1).expect("no snapshot");
    assert_ne!(theirs.own.expect("no own block").id, own.id);
}

// ── Commands ────────────────────────────────────────────────────────────────────────────────

#[test]
fn a_client_cannot_name_someone_elses_hero() {
    // Every command is rewritten to name the sender's own hero. The entity id comes from the
    // seat, never from the wire, so there is no field in which to put a victim.
    let room = filled(1);
    let mine = room.seats[0].hero.unwrap();

    let command = room
        .command_from(
            0,
            ClientIntent::MoveTo {
                x: Fx::from_int(500).raw(),
                y: 0,
            },
        )
        .expect("the order was refused");

    match command {
        moba_sim::sim::Command::MoveTo { hero, .. } => {
            assert_eq!(hero, mine, "an order was applied to the wrong hero")
        }
        other => panic!("expected a move, got {other:?}"),
    }
}

#[test]
fn an_order_naming_an_entity_that_does_not_exist_is_dropped() {
    // A client acting on a stale snapshot is normal and not worth an error; a client inventing
    // ids deserves nothing at all. Both land here.
    let room = filled(1);
    assert!(room
        .command_from(0, ClientIntent::Attack { target: 999_999 })
        .is_none());
    assert!(room
        .command_from(
            0,
            ClientIntent::Cast {
                slot: 0,
                target: NetTarget::Unit(999_999)
            }
        )
        .is_none());
}

#[test]
fn orders_from_a_seat_with_no_hero_are_refused() {
    let mut room = Room::new(config(1));
    room.join(HeroChoice::Ironclad).unwrap();
    assert!(
        room.command_from(0, ClientIntent::Stop).is_none(),
        "a seat with no hero gave orders"
    );
}

#[test]
fn a_one_v_one_plays_from_lobby_to_a_finished_match() {
    // The end-to-end shape a person can actually sit down and test: two seats, a real lane, and
    // a conclusion — with no server, no client and no network.
    let mut room = filled(1);
    assert_eq!(room.phase, Phase::Running);

    let heroes: Vec<_> = room.seats.iter().filter_map(|s| s.hero).collect();
    assert_eq!(heroes.len(), 2);

    for _ in 0..moba_proto::TICK_HZ * 60 * 5 {
        room.tick(&[]);
        if room.phase == Phase::Over {
            break;
        }
    }

    // Creeps alone may not finish a 1v1 inside five minutes, and that is fine — what must hold
    // is that the room stayed coherent and kept producing snapshots the whole way.
    let snapshot = room
        .snapshot_for(0)
        .expect("the room stopped producing snapshots");
    assert!(
        snapshot.entities.iter().any(|e| e.kind == NetKind::Base),
        "the map lost its structures partway through"
    );
}

// ── Reconnection ────────────────────────────────────────────────────────────────────────────

#[test]
fn a_player_who_drops_can_take_their_seat_back() {
    // Refreshing the tab is the single most common thing anyone does while developing this, and
    // without a way back into your own seat the room is permanently full of a ghost — the next
    // connection is refused, its socket closes, and the client reports "WebSocket is already in
    // CLOSING or CLOSED state" on the next click, which names the symptom and not the cause.
    let mut room = filled(1);
    let hero_before = room.seats[0].hero;

    room.disconnect(0);
    assert!(!room.seats[0].connected);

    let slot = room
        .reclaim()
        .expect("a dropped seat could not be reclaimed");
    assert_eq!(slot, 0, "reclaiming handed out the wrong seat");
    assert!(room.seats[0].connected);
    assert_eq!(
        room.seats[0].hero, hero_before,
        "reconnecting gave the player a different hero than the one on the map"
    );
}

#[test]
fn reclaiming_takes_the_longest_absent_seat_first() {
    let mut room = filled(2);
    room.disconnect(2);
    room.disconnect(0);

    // Slot order, not drop order: with two seats free, which one you get does not matter, but
    // *which one you get* must be the same on every machine given the same history.
    assert_eq!(room.reclaim(), Some(0));
    assert_eq!(room.reclaim(), Some(2));
    assert_eq!(
        room.reclaim(),
        None,
        "reclaimed a seat that was already occupied"
    );
}

#[test]
fn a_running_match_admits_a_reconnect_but_not_a_newcomer() {
    // The distinction that makes this safe: a reconnect fills a seat that already exists, so the
    // roster never changes. Seating a *new* player mid-match would be a different game than the
    // one everyone agreed to.
    let mut room = filled(1);
    assert_eq!(
        room.join(HeroChoice::Ironclad),
        None,
        "a running match seated a newcomer"
    );

    room.disconnect(1);
    assert_eq!(room.reclaim(), Some(1));
}

// ── Playing more than once ──────────────────────────────────────────────────────────────────

#[test]
fn a_reset_room_is_a_fresh_match_not_the_old_one_tidied() {
    // The bug this closes produced three symptoms that looked unrelated: the hero would not
    // move, no snapshots arrived, and the map never redrew. All three were one cause — a room
    // in `Over` no longer ticks, and a development server started once and played against all
    // afternoon put the second match back into the first one's corpse.
    let mut room = filled(1);

    // Wreck the match: kill a tower, damage a hero, end it.
    let tower = room
        .sim
        .entities
        .iter()
        .find(|(_, e)| e.kind == moba_sim::entity::EntityKind::Tower)
        .map(|(id, _)| id)
        .expect("no towers on the map");
    room.sim.entities.get_mut(tower).unwrap().hp = Fx::ZERO;
    room.tick(&[]);
    room.phase = Phase::Over;

    room.reset();

    assert_eq!(
        room.phase,
        Phase::Filling,
        "a reset room was not ready to fill again"
    );
    assert!(room.seats.is_empty(), "the old roster survived the reset");
    assert_eq!(room.sim.tick, 0, "the clock carried over");

    // Every structure back, at full health. Clearing fields one at a time is how a second match
    // inherits the first one's dead towers.
    let structures = room
        .sim
        .entities
        .iter()
        .filter(|(_, e)| e.kind.is_structure())
        .count();
    assert!(structures > 0, "the reset room has no structures");
    assert!(
        room.sim
            .entities
            .iter()
            .filter(|(_, e)| e.kind.is_structure())
            .all(|(_, e)| e.is_alive()),
        "a structure destroyed in the previous match was still dead"
    );
}

#[test]
fn a_reset_room_can_be_filled_and_started_again() {
    let mut room = filled(1);
    room.phase = Phase::Over;
    room.reset();

    for i in 0..room.capacity() {
        let choice = if i % 2 == 0 {
            HeroChoice::Ironclad
        } else {
            HeroChoice::Emberwitch
        };
        room.join(choice).expect("a reset room refused a player");
    }
    room.start();

    assert_eq!(room.phase, Phase::Running);
    assert!(room.seats.iter().all(|s| s.hero.is_some()));
    // And it ticks, which is the thing that was actually broken.
    assert!(
        (0..moba_proto::TICK_HZ).any(|_| room.tick(&[])),
        "a restarted room produced no snapshots"
    );
}

#[test]
fn a_ticketed_seat_lands_in_the_slot_the_ticket_names() {
    // Matchmaking decided the seat; arrival order must not override it. The last player to
    // connect may hold slot 0.
    let mut room = Room::new(config(2));
    assert_eq!(room.claim(3, HeroChoice::Relay), Some(3));
    assert_eq!(room.claim(0, HeroChoice::Jukebox), Some(0));

    let seat = room.seat(3).expect("slot 3 was not seated");
    assert_eq!(seat.team, Team::Red, "odd slots belong to Red");
    assert_eq!(
        room.seat(0).unwrap().team,
        Team::Blue,
        "even slots belong to Blue"
    );

    // Taken, and out of range, are both refused.
    assert_eq!(
        room.claim(3, HeroChoice::Ironclad),
        None,
        "a live seat was handed out twice"
    );
    assert_eq!(
        room.claim(99, HeroChoice::Ironclad),
        None,
        "a seat beyond the match size"
    );

    // A dropped seat is reclaimable — that is a reconnect, not a second player.
    room.disconnect(3);
    assert_eq!(room.claim(3, HeroChoice::Relay), Some(3));
}
