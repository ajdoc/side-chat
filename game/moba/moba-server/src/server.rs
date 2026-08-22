//! The socket layer: accept connections, hand their messages to a [`Room`], push snapshots back.
//!
//! Everything here is plumbing. The one rule it enforces on its own is the protocol version
//! check, which happens before a connection is given a seat.

use std::collections::BTreeMap;
use std::sync::Arc;

use futures_util::{SinkExt, StreamExt};
use moba_proto::{ClientMessage, ServerMessage, SlotId, PROTOCOL_VERSION, TICK_HZ};
use moba_sim::entity::Team;
use moba_sim::sim::{Command, MatchConfig};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::{mpsc, Mutex};
use tokio_tungstenite::tungstenite::Message;

use crate::report::{MatchResult, PlayerResult, Reporter};
use crate::room::{ClientIntent, HeroChoice, Phase, Room};
use crate::ticket;

/// A connected player's outbound queue.
type Outbound = mpsc::UnboundedSender<ServerMessage>;

/// Everything the socket layer shares between tasks.
///
/// One `Mutex<Room>` rather than a lock per field: the tick is the only writer and it runs to
/// completion, so finer locking would buy contention management for a lock that is uncontended
/// by construction.
/// Everything the process was told at startup.
#[derive(Clone)]
pub struct ServerConfig {
    pub addr: String,
    pub match_config: MatchConfig,
    /// Shared with the API. `None` runs the server open — no ticket required — which is the
    /// development harness and must never be a deployment.
    pub secret: Option<String>,
    /// Which match this process is running, if it was told. `None` accepts any valid ticket.
    pub match_id: Option<i64>,
    /// Where to report the result. `None` keeps no score.
    pub api_base: Option<String>,
}

pub struct Shared {
    pub room: Mutex<Room>,
    /// Where to send each seat's messages. `BTreeMap` rather than `HashMap` because snapshots
    /// are pushed by iterating it, and this crate keeps to ordered collections everywhere the
    /// order could reach the game.
    pub clients: Mutex<BTreeMap<SlotId, Outbound>>,
    pub inbound: mpsc::UnboundedSender<(SlotId, ClientIntent)>,
    pub config: ServerConfig,
    /// Which match the room currently holds, when tickets are in use. A ticket for a *different*
    /// match is the signal that the previous one is over and this process has been handed
    /// another — see the reset in `handle`.
    pub current_match: Mutex<Option<i64>>,
    /// Set once the finished match has been reported, and cleared by a reset.
    pub reported: Mutex<bool>,
}

impl Shared {
    async fn broadcast(&self, message: ServerMessage) {
        let clients = self.clients.lock().await;
        for tx in clients.values() {
            let _ = tx.send(message.clone());
        }
    }
}

/// Listen, and run the match until it ends.
pub async fn run(config: ServerConfig) -> std::io::Result<()> {
    let (inbound_tx, inbound_rx) = mpsc::unbounded_channel();
    let addr = config.addr.clone();
    let match_config = config.match_config;
    let shared = Arc::new(Shared {
        room: Mutex::new(Room::new(match_config)),
        clients: Mutex::new(BTreeMap::new()),
        inbound: inbound_tx,
        config,
        current_match: Mutex::new(None),
        reported: Mutex::new(false),
    });

    let listener = TcpListener::bind(&addr).await?;
    println!(
        "moba-server listening on {addr} — {}v{} — protocol {PROTOCOL_VERSION} — tickets {}",
        match_config.team_size,
        match_config.team_size,
        if shared.config.secret.is_some() {
            "required"
        } else {
            "OFF (development)"
        }
    );

    tokio::spawn(tick_loop(shared.clone(), inbound_rx));

    while let Ok((stream, peer)) = listener.accept().await {
        let shared = shared.clone();
        tokio::spawn(async move {
            if let Err(e) = handle(stream, shared).await {
                eprintln!("connection from {peer} ended: {e}");
            }
        });
    }
    Ok(())
}

/// One connection, from handshake to disconnect.
async fn handle(stream: TcpStream, shared: Arc<Shared>) -> Result<(), String> {
    let ws = tokio_tungstenite::accept_async(stream)
        .await
        .map_err(|e| e.to_string())?;
    let (mut sink, mut source) = ws.split();

    // The first message must be a Hello. Anything else, and the connection never gets a seat.
    let first = source
        .next()
        .await
        .ok_or("closed before hello")?
        .map_err(|e| e.to_string())?;
    let hello: ClientMessage = parse(&first).ok_or("unreadable hello")?;

    let (protocol, ticket) = match hello {
        ClientMessage::Hello { protocol, ticket } => (protocol, ticket),
        _ => return Err("first message was not a hello".into()),
    };

    // The version check that turns a stale cached bundle into a clear error rather than an
    // evening of chasing a desync. See MOBA.md.
    if protocol != PROTOCOL_VERSION {
        let reason = format!("protocol {protocol} but this server speaks {PROTOCOL_VERSION}");
        let _ = sink.send(encode(&ServerMessage::Rejected { reason })).await;
        let _ = sink.close().await;
        return Ok(());
    }

    // The ticket, when this server was given a secret to check one against.
    //
    // A refusal says only "invalid ticket" and never which part failed: distinguishing a bad
    // signature from a bad payload tells whoever is trying how close they got. The real reason
    // goes to the log, where it is useful and nobody hostile is reading it.
    let seat_claim = match shared.config.secret.as_deref() {
        Some(secret) => {
            let now = std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map(|d| d.as_secs() as i64)
                .unwrap_or(0);
            match ticket::verify_for_match(&ticket, secret, shared.config.match_id, now) {
                Ok(seat) => Some(seat),
                Err(why) => {
                    eprintln!("moba: refused a ticket ({why:?})");
                    let _ = sink
                        .send(encode(&ServerMessage::Rejected {
                            reason: "invalid ticket".into(),
                        }))
                        .await;
                    let _ = sink.close().await;
                    return Ok(());
                }
            }
        }
        // No secret: the server runs open. That is the development harness, announced in the
        // startup line, and never a deployment.
        None => None,
    };

    let slot = {
        let mut room = shared.room.lock().await;

        // Recycle the room when this connection belongs to a different match than the one it
        // holds, or when the match it holds is over and nobody is left in it.
        //
        // The production shape is one process per match, where neither case arises. A
        // development server is started once and played against all afternoon, and without this
        // the second match reconnects into the first: a room in `Over` no longer ticks, so the
        // hero does not move, no snapshots arrive, and the map never redraws.
        {
            let mut current = shared.current_match.lock().await;
            let arriving = seat_claim.as_ref().map(|s| s.match_id);

            let different_match = matches!((arriving, *current), (Some(a), Some(c)) if a != c);
            let finished_and_empty =
                room.phase == Phase::Over && shared.clients.lock().await.is_empty();

            if different_match || finished_and_empty {
                room.reset();
                *shared.reported.lock().await = false;
            }
            if arriving.is_some() {
                *current = arriving;
            }
        }

        let seated = match &seat_claim {
            // Ticketed. Matchmaking already decided the seat, the side and the hero; the server
            // honours that rather than re-deriving it, so arrival order is irrelevant and the
            // last player to connect may well hold slot 0.
            Some(seat) => {
                let choice = HeroChoice::from_id(&seat.hero).unwrap_or(HeroChoice::Ironclad);
                room.claim(seat.slot, choice)
            }
            // Open. Seats fill in arrival order and heroes rotate through the roster, so one
            // person with several tabs sees more than one hero.
            //
            // **Reconnect before seating** in either case: a refreshed tab is the common
            // situation, not the exotic one, and its old seat is still there with a hero
            // standing on the map. Trying to seat it as a newcomer first refuses it as a full
            // room — the bug that surfaced in the browser as "WebSocket is already in CLOSING
            // or CLOSED state" on the next click.
            None => {
                let choice = HeroChoice::for_slot(room.seats.len() as u8);
                room.join(choice).or_else(|| room.reclaim())
            }
        };

        match seated {
            Some(slot) => slot,
            None => {
                let reason = if room.is_full() {
                    "match is full — every seat has a live connection".to_string()
                } else {
                    "that seat is not available".to_string()
                };
                let _ = sink.send(encode(&ServerMessage::Rejected { reason })).await;
                // Close cleanly rather than dropping the socket. A client told *why* can show
                // it; one whose socket merely vanishes can only report that it vanished.
                let _ = sink.close().await;
                return Ok(());
            }
        }
    };

    let (out_tx, mut out_rx) = mpsc::unbounded_channel();
    shared.clients.lock().await.insert(slot, out_tx);

    // Pump the outbound queue. Split into its own task so a slow client cannot stall the tick.
    let writer = tokio::spawn(async move {
        while let Some(message) = out_rx.recv().await {
            if sink.send(encode(&message)).await.is_err() {
                break;
            }
        }
    });

    {
        let room = shared.room.lock().await;
        let team = room.seat(slot).map(|s| s.team).unwrap_or(Team::Blue);
        let clients = shared.clients.lock().await;
        if let Some(tx) = clients.get(&slot) {
            let _ = tx.send(ServerMessage::Welcome {
                protocol: PROTOCOL_VERSION,
                slot,
                team: match team {
                    Team::Blue => moba_proto::NetTeam::Blue,
                    Team::Red => moba_proto::NetTeam::Red,
                    Team::Neutral => moba_proto::NetTeam::Neutral,
                },
                // Zero while the match is still filling — the hero does not exist yet, and the
                // first snapshot's `own` block fills it in. On a *reconnect* it is already
                // there, which is what lets a returning player's camera find their hero on the
                // first frame instead of after the first snapshot.
                hero_id: room
                    .seat(slot)
                    .and_then(|s| s.hero)
                    .map(|h| h.to_net())
                    .unwrap_or(0),
                team_size: room.capacity() as u8 / 2,
                tick_hz: TICK_HZ,
                map: room.sim.net_map(),
            });
        }
        drop(clients);

        let full = room.is_full() && room.phase == Phase::Filling;
        let running = room.phase == Phase::Running;
        let resumed_at = room.sim.tick;
        let lobby = room.lobby_message();
        drop(room);
        shared.broadcast(lobby).await;

        if full {
            let mut room = shared.room.lock().await;
            room.start();
            let tick = room.sim.tick;
            drop(room);
            shared.broadcast(ServerMessage::Started { tick }).await;
        } else if running {
            // A reconnect into a match already in progress. Without this the returning client
            // sits on the lobby screen forever while snapshots pile up behind it.
            let clients = shared.clients.lock().await;
            if let Some(tx) = clients.get(&slot) {
                let _ = tx.send(ServerMessage::Started { tick: resumed_at });
            }
        }
    }

    while let Some(Ok(raw)) = source.next().await {
        let Some(message) = parse(&raw) else { continue };
        match message {
            ClientMessage::Ping { nonce } => {
                let clients = shared.clients.lock().await;
                if let Some(tx) = clients.get(&slot) {
                    let _ = tx.send(ServerMessage::Pong { nonce });
                }
            }
            other => {
                if let Some(intent) = ClientIntent::from_message(&other) {
                    let _ = shared.inbound.send((slot, intent));
                }
            }
        }
    }

    // The socket is gone, the seat is not. A hero left standing is a hero a reconnect can pick
    // back up, and deleting it would let one dropped connection delete a team's carry.
    shared.clients.lock().await.remove(&slot);
    shared.room.lock().await.disconnect(slot);
    writer.abort();
    Ok(())
}

/// The heartbeat: one step every 1/`TICK_HZ` of a second, for as long as the match lasts.
async fn tick_loop(
    shared: Arc<Shared>,
    mut inbound: mpsc::UnboundedReceiver<(SlotId, ClientIntent)>,
) {
    let period = std::time::Duration::from_nanos(1_000_000_000 / TICK_HZ as u64);
    let mut ticker = tokio::time::interval(period);
    // If the loop falls behind — a long GC pause on the host, a hiccup — skip the missed ticks
    // rather than running them back to back. Catching up would fast-forward the match for every
    // player at once, which is far more disruptive than the fraction of a second that was lost.
    ticker.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Skip);

    loop {
        ticker.tick().await;

        let mut commands: Vec<Command> = Vec::new();
        let mut room = shared.room.lock().await;

        // Drain everything that arrived since the last tick. Orders are applied in arrival
        // order, which is the only fair rule available: the alternative is sorting by slot,
        // which would make being player one an advantage in every race.
        while let Ok((slot, intent)) = inbound.try_recv() {
            if let Some(command) = room.command_from(slot, intent) {
                commands.push(command);
            }
        }

        if room.phase == Phase::Over {
            let already = {
                let mut flag = shared.reported.lock().await;
                let was = *flag;
                *flag = true;
                was
            };
            if !already {
                let winner = room.sim.winner();
                let board = room.scoreboard();
                drop(room);

                // Spawned rather than awaited: reporting retries for up to half a minute, and
                // the tick loop must not stop for it — players are still connected to a
                // finished match looking at the scoreboard.
                let reporter = Reporter {
                    api_base: shared.config.api_base.clone(),
                    secret: shared.config.secret.clone().unwrap_or_default(),
                    match_id: shared.config.match_id,
                };
                tokio::spawn(async move {
                    let Some(winner) = winner else { return };
                    reporter
                        .send(&MatchResult {
                            winning_team: match winner {
                                moba_sim::entity::Team::Blue => 0,
                                _ => 1,
                            },
                            players: board
                                .into_iter()
                                .map(|(slot, stats)| PlayerResult {
                                    slot,
                                    kills: stats.kills,
                                    deaths: stats.deaths,
                                    assists: stats.assists,
                                    gold: stats.gold,
                                    damage: stats.damage,
                                })
                                .collect(),
                        })
                        .await;
                });
            }
            continue;
        }

        let due = room.tick(&commands);
        if !due {
            continue;
        }

        let snapshots: Vec<(SlotId, moba_proto::Snapshot)> = room
            .seats
            .iter()
            .filter(|s| s.connected)
            .filter_map(|s| room.snapshot_for(s.slot).map(|snap| (s.slot, snap)))
            .collect();
        room.clear_pending();
        drop(room);

        let clients = shared.clients.lock().await;
        for (slot, snapshot) in snapshots {
            if let Some(tx) = clients.get(&slot) {
                let _ = tx.send(ServerMessage::Snapshot(snapshot));
            }
        }
    }
}

/// JSON on the wire for now.
///
/// Readable in a browser's network tab, which is worth a great deal while the client is being
/// written. `postcard` is a drop-in replacement here once the shape stops changing, and the
/// encoding lives behind these two functions precisely so that swap is two lines.
fn encode(message: &ServerMessage) -> Message {
    Message::Text(serde_json::to_string(message).unwrap_or_default())
}

fn parse(raw: &Message) -> Option<ClientMessage> {
    match raw {
        Message::Text(text) => serde_json::from_str(text).ok(),
        Message::Binary(bytes) => serde_json::from_slice(bytes).ok(),
        _ => None,
    }
}
