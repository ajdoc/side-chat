//! The socket layer, end to end: two real WebSocket clients against a real listener.
//!
//! `room.rs` covers the match logic without a port; this covers the part that only exists once
//! there is one — the handshake, the version gate, the lobby filling, and snapshots actually
//! arriving over the wire.

use futures_util::{SinkExt, StreamExt};
use moba_proto::{ClientMessage, ServerMessage, PROTOCOL_VERSION};
use moba_server::server::ServerConfig;
use moba_sim::sim::MatchConfig;
use tokio::net::TcpStream;
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::{MaybeTlsStream, WebSocketStream};

type Client = WebSocketStream<MaybeTlsStream<TcpStream>>;

/// Ports are picked per test rather than shared: these run concurrently by default, and a shared
/// port makes them fail in whichever order the scheduler happens to pick.
async fn serve(port: u16, team_size: u8) {
    let addr = format!("127.0.0.1:{port}");
    tokio::spawn(async move {
        // No secret: these tests are about the socket layer, and an open server is what the
        // development harness runs. Ticketed seating is covered by `ticket.rs`.
        let _ = moba_server::server::run(ServerConfig {
            addr,
            match_config: MatchConfig {
                team_size,
                ..MatchConfig::default()
            },
            secret: None,
            match_id: None,
            api_base: None,
        })
        .await;
    });
    // Give the listener a moment to bind. Polling the connect below would be tidier, but this is
    // a test fixture and the retry loop in `connect` already covers a slow start.
    tokio::time::sleep(std::time::Duration::from_millis(150)).await;
}

async fn connect(port: u16) -> Client {
    for _ in 0..40 {
        if let Ok((ws, _)) =
            tokio_tungstenite::connect_async(format!("ws://127.0.0.1:{port}")).await
        {
            return ws;
        }
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    }
    panic!("could not connect to the server on {port}");
}

async fn say(client: &mut Client, message: ClientMessage) {
    client
        .send(Message::Text(serde_json::to_string(&message).unwrap()))
        .await
        .expect("send failed");
}

/// Read messages until `pick` matches one, or time runs out.
async fn wait_for<T>(
    client: &mut Client,
    mut pick: impl FnMut(&ServerMessage) -> Option<T>,
) -> Option<T> {
    let deadline = std::time::Duration::from_secs(5);
    tokio::time::timeout(deadline, async {
        while let Some(Ok(raw)) = client.next().await {
            let Message::Text(text) = raw else { continue };
            let Ok(message) = serde_json::from_str::<ServerMessage>(&text) else {
                continue;
            };
            if let Some(found) = pick(&message) {
                return Some(found);
            }
        }
        None
    })
    .await
    .ok()
    .flatten()
}

#[tokio::test]
async fn a_mismatched_protocol_is_rejected_with_a_readable_reason() {
    // The check that turns a stale wasm bundle in someone's cache into an error message rather
    // than an evening of chasing a desync that was never in the code.
    serve(41931, 1).await;
    let mut client = connect(41931).await;

    say(
        &mut client,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION + 7,
            ticket: "dev".into(),
        },
    )
    .await;

    let reason = wait_for(&mut client, |m| match m {
        ServerMessage::Rejected { reason } => Some(reason.clone()),
        _ => None,
    })
    .await
    .expect("a mismatched client was not rejected");

    assert!(
        reason.contains(&PROTOCOL_VERSION.to_string()),
        "unhelpful reason: {reason}"
    );
}

#[tokio::test]
async fn two_clients_fill_a_one_v_one_and_start_receiving_snapshots() {
    serve(41932, 1).await;

    let mut first = connect(41932).await;
    say(
        &mut first,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;

    let slot = wait_for(&mut first, |m| match m {
        ServerMessage::Welcome { slot, .. } => Some(*slot),
        _ => None,
    })
    .await
    .expect("no welcome");
    assert_eq!(slot, 0);

    // One player in a 1v1 waits rather than starting alone.
    let lobby = wait_for(&mut first, |m| match m {
        ServerMessage::Lobby { present, needed } => Some((*present, *needed)),
        _ => None,
    })
    .await
    .expect("no lobby message");
    assert_eq!(lobby, (1, 2));

    let mut second = connect(41932).await;
    say(
        &mut second,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;

    // The match begins the moment the room is full.
    assert!(
        wait_for(&mut first, |m| matches!(m, ServerMessage::Started { .. })
            .then_some(()))
        .await
        .is_some(),
        "the match never started once both seats were taken"
    );

    // And the world starts arriving.
    let snapshot = wait_for(&mut first, |m| match m {
        ServerMessage::Snapshot(s) => Some(s.clone()),
        _ => None,
    })
    .await
    .expect("no snapshot arrived");

    assert!(!snapshot.entities.is_empty(), "an empty world was sent");
    assert!(
        snapshot.own.is_some(),
        "the snapshot carried no own-hero block"
    );
}

#[tokio::test]
async fn a_third_client_is_turned_away_from_a_full_one_v_one() {
    serve(41933, 1).await;

    let mut a = connect(41933).await;
    say(
        &mut a,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;
    let mut b = connect(41933).await;
    say(
        &mut b,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;

    wait_for(&mut b, |m| {
        matches!(m, ServerMessage::Started { .. }).then_some(())
    })
    .await
    .expect("the match never started");

    let mut c = connect(41933).await;
    say(
        &mut c,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;

    let reason = wait_for(&mut c, |m| match m {
        ServerMessage::Rejected { reason } => Some(reason.clone()),
        _ => None,
    })
    .await
    .expect("a full match seated a third player");
    assert!(reason.contains("full"), "unhelpful reason: {reason}");
}

#[tokio::test]
async fn an_order_sent_over_the_socket_moves_the_hero() {
    // The whole loop closed: a client's click becomes bytes, becomes a command, becomes a
    // position change that comes back in a later snapshot.
    serve(41934, 1).await;

    let mut a = connect(41934).await;
    say(
        &mut a,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;
    let mut b = connect(41934).await;
    say(
        &mut b,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;

    let own_position = |snapshot: &moba_proto::Snapshot| {
        let own = snapshot.own.as_ref()?;
        snapshot
            .entities
            .iter()
            .find(|e| e.id == own.id)
            .map(|e| (e.x, e.y))
    };

    let start = wait_for(&mut a, |m| match m {
        ServerMessage::Snapshot(s) => own_position(s),
        _ => None,
    })
    .await
    .expect("no snapshot with the player's own hero in it");

    // Somewhere clearly elsewhere on the map.
    say(
        &mut a,
        ClientMessage::MoveTo {
            x: moba_sim::fixed::Fx::from_int(1500).raw(),
            y: moba_sim::fixed::Fx::from_int(1500).raw(),
        },
    )
    .await;

    let moved = tokio::time::timeout(std::time::Duration::from_secs(6), async {
        loop {
            if let Some(pos) = wait_for(&mut a, |m| match m {
                ServerMessage::Snapshot(s) => own_position(s),
                _ => None,
            })
            .await
            {
                if pos != start {
                    return pos;
                }
            }
        }
    })
    .await;

    let moved = moved.expect("the hero never moved after a MoveTo order");
    assert_ne!(moved, start, "the order round-tripped but changed nothing");
}

#[tokio::test]
async fn a_refreshed_tab_gets_its_seat_and_its_hero_back() {
    // The bug that produced "WebSocket is already in CLOSING or CLOSED state" in the browser
    // console: a refreshed tab could not rejoin, the server refused it as full, the socket
    // closed, and the next click called `send` on a dead socket. The console message named the
    // symptom; the cause was a `connected` flag that nothing ever read.
    serve(41935, 1).await;

    let mut a = connect(41935).await;
    say(
        &mut a,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;
    let mut b = connect(41935).await;
    say(
        &mut b,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;

    let hero = wait_for(&mut a, |m| match m {
        ServerMessage::Snapshot(s) => s.own.as_ref().map(|o| o.id),
        _ => None,
    })
    .await
    .expect("no snapshot with an own-hero block");

    // The refresh.
    drop(a);
    tokio::time::sleep(std::time::Duration::from_millis(200)).await;

    let mut returning = connect(41935).await;
    say(
        &mut returning,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;

    let welcome = wait_for(&mut returning, |m| match m {
        ServerMessage::Welcome { slot, hero_id, .. } => Some((*slot, *hero_id)),
        ServerMessage::Rejected { reason } => panic!("a refreshed tab was refused: {reason}"),
        _ => None,
    })
    .await
    .expect("no welcome after reconnecting");

    assert_eq!(welcome.0, 0, "the returning player got a different seat");
    assert_eq!(welcome.1, hero, "the returning player got a different hero");

    // And it is put straight back into the running match rather than left on the lobby screen.
    assert!(
        wait_for(&mut returning, |m| matches!(
            m,
            ServerMessage::Started { .. }
        )
        .then_some(()))
        .await
        .is_some(),
        "a reconnecting client was left waiting in the lobby"
    );
    assert!(
        wait_for(&mut returning, |m| matches!(m, ServerMessage::Snapshot(_))
            .then_some(()))
        .await
        .is_some(),
        "a reconnecting client received no snapshots"
    );
}

#[tokio::test]
async fn a_newcomer_is_still_refused_while_a_seat_is_live() {
    // Reconnection must not become a back door for seating an eleventh player mid-match.
    serve(41936, 1).await;

    let mut a = connect(41936).await;
    say(
        &mut a,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;
    let mut b = connect(41936).await;
    say(
        &mut b,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;
    wait_for(&mut b, |m| {
        matches!(m, ServerMessage::Started { .. }).then_some(())
    })
    .await
    .expect("the match never started");

    // Both seats still have live sockets, so there is nothing to reclaim.
    let mut c = connect(41936).await;
    say(
        &mut c,
        ClientMessage::Hello {
            protocol: PROTOCOL_VERSION,
            ticket: "dev".into(),
        },
    )
    .await;
    let reason = wait_for(&mut c, |m| match m {
        ServerMessage::Rejected { reason } => Some(reason.clone()),
        _ => None,
    })
    .await
    .expect("a third player was seated in a full 1v1");
    assert!(reason.contains("full"), "unhelpful reason: {reason}");
}
