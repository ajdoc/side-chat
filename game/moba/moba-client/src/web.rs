//! The browser shim: a canvas, a socket, and a frame loop.
//!
//! Deliberately the thinnest layer in the crate. Everything with a rule in it lives in
//! [`crate::interp`], [`crate::input`] and [`crate::camera`], which are plain Rust and tested
//! without a browser. What remains here is wiring — and wiring is the part that cannot be unit
//! tested anyway, so the less of it there is, the better.
//!
//! Mounted from JavaScript:
//!
//! ```js
//! import init, { MobaGame } from "./moba_client.js";
//! await init();
//! const game = new MobaGame("game-canvas", "ws://localhost:930", "dev-ticket");
//! game.start();
//! ```

use std::cell::RefCell;
use std::rc::Rc;

use moba_proto::{ClientMessage, NetKind, NetTeam, ServerMessage, PROTOCOL_VERSION};
use wasm_bindgen::prelude::*;
use wasm_bindgen::JsCast;
use web_sys::{CanvasRenderingContext2d, HtmlCanvasElement, MessageEvent, WebSocket};

use crate::camera::Camera;
use crate::effects::{EffectKind, Effects};
use crate::hud::Hud;
use crate::input::{slot_for_key, Armed, Input, MouseButton};
use crate::interp::{from_fixed, RenderEntity, SnapshotBuffer};
use crate::spells::{look, short_name, Shape};

/// Everything the frame loop and the socket callbacks both need.
struct State {
    buffer: SnapshotBuffer,
    input: Input,
    camera: Camera,
    effects: Effects,
    /// Seconds left on the "you are taking damage" edge flash.
    hurt_flash: f32,
    /// Set once the match has ended, with who won.
    outcome: Option<String>,
    /// A short-lived line explaining why the last thing you pressed did nothing. Without it a
    /// refused cast and a broken ability are indistinguishable.
    notice: Option<(String, f32)>,
    socket: Option<WebSocket>,
    last_frame_ms: f64,
    own_id: Option<u64>,
    connected: bool,
    status: String,
    /// The map, once the handshake has delivered it. `None` until then, and the renderer draws
    /// nothing rather than guessing — the previous client drew a hardcoded diagonal that
    /// happened to match the one lane that existed, and would have kept drawing it over three.
    map: Option<moba_proto::NetMap>,
    /// Set when the server refused us outright. A refusal is a decision, not a hiccup, so the
    /// client stops retrying and leaves the reason on screen.
    give_up: bool,
    /// Set when the socket dropped and a fresh one should be opened.
    wants_reconnect: bool,
    /// Seconds until the next reconnect attempt.
    reconnect_in: f32,
}

#[wasm_bindgen]
pub struct MobaGame {
    canvas: HtmlCanvasElement,
    context: CanvasRenderingContext2d,
    url: String,
    ticket: String,
    state: Rc<RefCell<State>>,
}

#[wasm_bindgen]
impl MobaGame {
    #[wasm_bindgen(constructor)]
    pub fn new(canvas_id: &str, url: &str, ticket: &str) -> Result<MobaGame, JsValue> {
        let document = web_sys::window()
            .ok_or("no window")?
            .document()
            .ok_or("no document")?;
        let canvas: HtmlCanvasElement = document
            .get_element_by_id(canvas_id)
            .ok_or_else(|| JsValue::from_str(&format!("no element #{canvas_id}")))?
            .dyn_into()?;
        let context: CanvasRenderingContext2d = canvas
            .get_context("2d")?
            .ok_or("no 2d context")?
            .dyn_into()?;

        let (width, height) = (canvas.width() as f32, canvas.height() as f32);

        Ok(MobaGame {
            canvas,
            context,
            url: url.to_string(),
            ticket: ticket.to_string(),
            state: Rc::new(RefCell::new(State {
                buffer: SnapshotBuffer::new(),
                input: Input::new(),
                camera: Camera::new(width, height),
                effects: Effects::new(),
                hurt_flash: 0.0,
                outcome: None,
                notice: None,
                socket: None,
                last_frame_ms: 0.0,
                own_id: None,
                connected: false,
                status: "connecting".into(),
                map: None,
                give_up: false,
                wants_reconnect: false,
                reconnect_in: 0.0,
            })),
        })
    }

    /// Open the socket, wire the input handlers, and start rendering.
    pub fn start(&self) -> Result<(), JsValue> {
        self.connect()?;
        self.wire_input()?;
        self.run_frames();
        Ok(())
    }

    fn connect(&self) -> Result<(), JsValue> {
        open_socket(&self.url, &self.ticket, self.state.clone())
    }
}

/// Open a socket and wire its callbacks into `state`.
///
/// A free function rather than a method because the frame loop reopens it after a drop, and at
/// that point there is no `&self` to be had — only the shared state the closures already hold.
fn open_socket(url: &str, ticket: &str, state: Rc<RefCell<State>>) -> Result<(), JsValue> {
    let socket = WebSocket::new(url)?;
    state.borrow_mut().wants_reconnect = false;

    {
        let state = state.clone();
        let socket_for_open = socket.clone();
        let ticket = ticket.to_string();
        let on_open = Closure::<dyn FnMut()>::new(move || {
            // The very first message must be the hello, and it carries the protocol version
            // the server checks against. See MOBA.md.
            let hello = ClientMessage::Hello {
                protocol: PROTOCOL_VERSION,
                ticket: ticket.clone(),
            };
            if let Ok(text) = serde_json::to_string(&hello) {
                let _ = socket_for_open.send_with_str(&text);
            }
            state.borrow_mut().status = "waiting for the match to fill".into();
        });
        socket.set_onopen(Some(on_open.as_ref().unchecked_ref()));
        on_open.forget();
    }

    {
        let state = state.clone();
        let on_message = Closure::<dyn FnMut(MessageEvent)>::new(move |event: MessageEvent| {
            let Some(text) = event.data().as_string() else {
                return;
            };
            let Ok(message) = serde_json::from_str::<ServerMessage>(&text) else {
                return;
            };
            let mut state = state.borrow_mut();
            match message {
                ServerMessage::Welcome { slot, hero_id, .. } => {
                    // Non-zero on a reconnect, which lets the camera find the hero on the
                    // first frame rather than after the first snapshot arrives.
                    if hero_id != 0 {
                        state.own_id = Some(hero_id);
                    }
                    state.status = format!("seated — slot {slot}");
                }
                ServerMessage::Rejected { reason } => {
                    state.status = format!("rejected: {reason}");
                    // A refusal is final. Stop the reconnect loop from arguing with it.
                    state.give_up = true;
                }
                ServerMessage::Lobby { present, needed } => {
                    state.status = format!("waiting — {present}/{needed}");
                }
                ServerMessage::Started { .. } => {
                    state.connected = true;
                    state.status = "playing".into();
                }
                ServerMessage::Snapshot(snapshot) => {
                    // `own` is absent for a spectator and present for a player, including a dead
                    // one — so it is only overwritten when it is actually there, or a dead
                    // player's camera would lose its subject.
                    if let Some(block) = snapshot.own.as_ref() {
                        state.own_id = Some(block.id);
                    }

                    // **Feed the effects.** Everything the player sees happen — damage numbers,
                    // hit lines, cast rings, ability names, death marks — comes from this one
                    // call. Without it the effect list is advanced every frame and drawn every
                    // frame and is always empty, which is exactly as invisible as having no
                    // effects at all, and looks identical to the abilities not working.
                    let own_id = state.own_id;
                    state.effects.ingest(&snapshot.events, own_id);

                    for event in &snapshot.events {
                        match event {
                            // Taking damage flashes the screen edge. On a phone the health bar
                            // above your hero is a few pixels tall and under your thumb; this is
                            // the signal that reads at a glance.
                            moba_proto::NetEvent::Damaged { target, .. }
                                if Some(*target) == own_id =>
                            {
                                state.hurt_flash = 0.35;
                            }
                            moba_proto::NetEvent::MatchEnded { winner } => {
                                state.outcome = Some(format!("{winner:?} wins"));
                            }
                            // Why the last thing you pressed did nothing. A refusal with no
                            // feedback is indistinguishable from a broken ability.
                            moba_proto::NetEvent::CastRefused { reason, .. } => {
                                use moba_proto::NetRefusal as R;
                                let text = match reason {
                                    R::OnCooldown => "on cooldown",
                                    R::NotEnoughMana => "not enough mana",
                                    R::Silenced => "silenced",
                                    R::Stunned => "you cannot act",
                                    R::OutOfRange => "out of range — move closer",
                                    R::BadTarget => "no valid target",
                                    R::NotLearned => "unlocks at level 6",
                                    R::AlreadyCasting => "already casting",
                                    R::Unknown => "that did not work",
                                };
                                state.notice = Some((text.to_string(), 2.0));
                            }
                            _ => {}
                        }
                    }

                    state.buffer.push(snapshot);
                    state.connected = true;
                }
                ServerMessage::Pong { .. } => {}
            }
        });
        socket.set_onmessage(Some(on_message.as_ref().unchecked_ref()));
        on_message.forget();
    }

    {
        let state = state.clone();
        let on_close = Closure::<dyn FnMut()>::new(move || {
            let mut state = state.borrow_mut();
            state.connected = false;
            if !state.give_up {
                // The overwhelmingly common cause during development, and worth naming
                // rather than leaving as a bare "disconnected": the match server is a
                // separate process from the page you are looking at.
                state.status = "disconnected — is `make moba-server` running? retrying…".into();
                state.wants_reconnect = true;
            }
        });
        socket.set_onclose(Some(on_close.as_ref().unchecked_ref()));
        on_close.forget();
    }

    state.borrow_mut().socket = Some(socket);
    Ok(())
}

#[wasm_bindgen]
impl MobaGame {
    fn wire_input(&self) -> Result<(), JsValue> {
        let canvas = self.canvas.clone();

        // Right-click is the command button in this genre, so the browser menu has to go.
        {
            let on_context = Closure::<dyn FnMut(web_sys::Event)>::new(|e: web_sys::Event| {
                e.prevent_default();
            });
            canvas.set_oncontextmenu(Some(on_context.as_ref().unchecked_ref()));
            on_context.forget();
        }

        {
            let state = self.state.clone();
            let canvas_for_move = canvas.clone();
            let on_move =
                Closure::<dyn FnMut(web_sys::MouseEvent)>::new(move |e: web_sys::MouseEvent| {
                    let rect = canvas_for_move.get_bounding_client_rect();
                    let (sx, sy) = (
                        e.client_x() as f32 - rect.left() as f32,
                        e.client_y() as f32 - rect.top() as f32,
                    );
                    let mut state = state.borrow_mut();
                    let entities = state.buffer.sample();
                    state.input.hovered = state.camera.pick(&entities, sx, sy).map(|e| e.id);
                });
            canvas.set_onmousemove(Some(on_move.as_ref().unchecked_ref()));
            on_move.forget();
        }

        {
            let state = self.state.clone();
            let canvas_for_down = canvas.clone();
            let on_down =
                Closure::<dyn FnMut(web_sys::MouseEvent)>::new(move |e: web_sys::MouseEvent| {
                    e.prevent_default();
                    let button = match e.button() {
                        2 => MouseButton::Right,
                        0 => MouseButton::Left,
                        _ => return,
                    };
                    let rect = canvas_for_down.get_bounding_client_rect();
                    let mut state = state.borrow_mut();
                    let (wx, wy) = state.camera.screen_to_world(
                        e.client_x() as f32 - rect.left() as f32,
                        e.client_y() as f32 - rect.top() as f32,
                    );
                    let message = state.input.click(button, to_fixed(wx), to_fixed(wy));
                    send(&state, message);
                });
            canvas.set_onmousedown(Some(on_down.as_ref().unchecked_ref()));
            on_down.forget();
        }

        // ── Touch ───────────────────────────────────────────────────────────────────────
        //
        // A finger has one button, so a tap has to be the *command* gesture — see `Input::tap`.
        // Handled on `touchstart` rather than on a synthesised click so there is no 300ms
        // tap delay, which in a MOBA is a third of a second added to every order.
        {
            let state = self.state.clone();
            let canvas_for_touch = canvas.clone();
            let on_touch =
                Closure::<dyn FnMut(web_sys::TouchEvent)>::new(move |e: web_sys::TouchEvent| {
                    // Stops the browser turning the tap into a click, a scroll, or a zoom — all
                    // three of which would fight the game for the same gesture.
                    e.prevent_default();
                    let Some(touch) = e.changed_touches().get(0) else {
                        return;
                    };

                    let rect = canvas_for_touch.get_bounding_client_rect();
                    let (sx, sy) = (
                        touch.client_x() as f32 - rect.left() as f32,
                        touch.client_y() as f32 - rect.top() as f32,
                    );

                    let mut state = state.borrow_mut();
                    let hud =
                        Hud::layout(state.camera.width, state.camera.height, state.buffer.own());
                    if let Some(slot) = hud.hit(sx, sy) {
                        // Arming rather than firing: the client does not hold the ability
                        // catalogue, so it cannot know which abilities are self-cast. Arming is
                        // the safe direction — a wrongly-armed ability can be cancelled, a
                        // wrongly-fired one cannot.
                        let message = state.input.press_button(slot, false);
                        send(&state, message);
                        return;
                    }
                    if hud.contains(sx, sy) {
                        return;
                    }

                    let (wx, wy) = state.camera.screen_to_world(sx, sy);
                    // Whatever is under the finger becomes the target, exactly as hovering does
                    // with a mouse — a phone has no hover, so the pick happens at tap time.
                    let entities = state.buffer.sample();
                    state.input.hovered = state.camera.pick(&entities, sx, sy).map(|e| e.id);
                    let message = state.input.tap(to_fixed(wx), to_fixed(wy));
                    send(&state, message);
                });
            canvas.set_ontouchstart(Some(on_touch.as_ref().unchecked_ref()));
            on_touch.forget();
        }

        {
            let state = self.state.clone();
            let on_key = Closure::<dyn FnMut(web_sys::KeyboardEvent)>::new(
                move |e: web_sys::KeyboardEvent| {
                    let key = e.key();
                    let mut state = state.borrow_mut();
                    match key.as_str() {
                        "Escape" => state.input.cancel(),
                        "s" | "S" => {
                            let message = state.input.stop();
                            send(&state, Some(message));
                        }
                        other => {
                            if let Some(slot) = slot_for_key(other) {
                                // A self-cast has nothing to aim, so arming it and demanding a
                                // click is a second keystroke for no decision. The targeting
                                // mode comes from the server — it is a rule, not a decoration,
                                // and guessing it client-side is how a skillshot ends up firing
                                // at the caster's feet.
                                let self_cast = state
                                    .buffer
                                    .own()
                                    .and_then(|o| o.targeting.get(slot as usize).copied())
                                    .is_some_and(|t| t == moba_proto::NetTargeting::SelfCast);
                                let message = state.input.press_button(slot, self_cast);
                                send(&state, message);
                            }
                        }
                    }
                },
            );
            web_sys::window()
                .ok_or("no window")?
                .set_onkeydown(Some(on_key.as_ref().unchecked_ref()));
            on_key.forget();
        }

        Ok(())
    }

    /// The render loop, driven by `requestAnimationFrame`.
    ///
    /// Frame time comes from the callback's own timestamp rather than from a wall clock, which
    /// is what keeps the interpolation clock honest when the browser throttles a background tab.
    fn run_frames(&self) {
        let state = self.state.clone();
        let context = self.context.clone();
        let canvas = self.canvas.clone();
        let url = self.url.clone();
        let ticket = self.ticket.clone();

        let callback = Rc::new(RefCell::new(None::<Closure<dyn FnMut(f64)>>));
        let handle = callback.clone();
        let reconnect_state = self.state.clone();

        *callback.borrow_mut() = Some(Closure::<dyn FnMut(f64)>::new(move |now_ms: f64| {
            {
                let mut state = state.borrow_mut();
                let dt = if state.last_frame_ms == 0.0 {
                    1.0 / 60.0
                } else {
                    // Clamped: a tab that was hidden for a minute must not advance the clock by
                    // a minute in one step, and the snap in `SnapshotBuffer::push` handles the
                    // real catch-up more gracefully than a giant `dt` would.
                    (((now_ms - state.last_frame_ms) / 1000.0) as f32).clamp(0.0, 0.1)
                };
                state.last_frame_ms = now_ms;
                state.buffer.advance(dt);
                state.effects.advance(dt);
                state.hurt_flash = (state.hurt_flash - dt).max(0.0);
                if let Some((_, left)) = state.notice.as_mut() {
                    *left -= dt;
                }
                if state.notice.as_ref().is_some_and(|(_, left)| *left <= 0.0) {
                    state.notice = None;
                }

                // Reconnect on a timer rather than immediately: a server that is down stays
                // down for a while, and hammering it once a frame would be sixty connection
                // attempts a second for as long as the tab is open.
                if state.wants_reconnect && !state.give_up {
                    state.reconnect_in -= dt;
                    if state.reconnect_in <= 0.0 {
                        state.reconnect_in = 2.0;
                        // Drop the borrow before reconnecting: `open_socket` writes to the same
                        // `RefCell` these callbacks hold, and holding it across the call is a
                        // panic rather than a deadlock.
                        drop(state);
                        let _ = open_socket(&url, &ticket, reconnect_state.clone());
                        return request_frame(handle.borrow().as_ref().unwrap());
                    }
                }

                let entities = state.buffer.sample();
                if let Some(own) = state
                    .own_id
                    .and_then(|id| entities.iter().find(|e| e.id == id))
                {
                    let (x, y) = (own.x, own.y);
                    state.camera.follow(x, y, dt);
                }
                draw(&context, &canvas, &state, &entities);
            }
            request_frame(handle.borrow().as_ref().unwrap());
        }));

        request_frame(callback.borrow().as_ref().unwrap());
    }
}

fn request_frame(callback: &Closure<dyn FnMut(f64)>) {
    if let Some(window) = web_sys::window() {
        let _ = window.request_animation_frame(callback.as_ref().unchecked_ref());
    }
}

fn send(state: &State, message: Option<ClientMessage>) {
    let (Some(message), Some(socket)) = (message, state.socket.as_ref()) else {
        return;
    };
    if let Ok(text) = serde_json::to_string(&message) {
        let _ = socket.send_with_str(&text);
    }
}

const TAU: f64 = std::f64::consts::PI * 2.0;

fn to_fixed(v: f32) -> i32 {
    (v * 65536.0) as i32
}

/// Placeholder art: coloured discs and health bars.
///
/// Explicitly not the Dota-1-style sprite work MOBA.md describes — that is a separate and much
/// larger job, and it should not begin until the thing underneath is known to feel right. Discs
/// are enough to answer that question, and they answer it this week.
fn draw(
    context: &CanvasRenderingContext2d,
    canvas: &HtmlCanvasElement,
    state: &State,
    entities: &[RenderEntity],
) {
    let (w, h) = (canvas.width() as f64, canvas.height() as f64);
    context.set_fill_style_str("#11141b");
    context.fill_rect(0.0, 0.0, w, h);

    // ── The map ─────────────────────────────────────────────────────────────────────────
    //
    // Drawn from what the server sent, never from constants. The previous version drew a
    // hardcoded diagonal between two literal coordinates that happened to coincide with the only
    // lane there was; it looked right and was luck, and it would have drawn one stripe through
    // the middle of three lanes without complaining.
    if let Some(map) = &state.map {
        let cell = map.size as f32 / map.cells_across.max(1) as f32;

        // Terrain first, as one path: thousands of individual fill_rect calls per frame is the
        // kind of thing that quietly costs a third of the frame budget on a phone.
        context.set_fill_style_str("#161b23");
        context.begin_path();
        for (cx, cy) in &map.blocked {
            let (sx, sy) = state
                .camera
                .world_to_screen(*cx as f32 * cell, *cy as f32 * cell);
            let size = (cell * state.camera.zoom) as f64;
            // Skip anything off-screen. At full zoom-out most of the grid is not visible, and
            // the check is much cheaper than the rect.
            if sx < -size as f32 || sy < -size as f32 || sx > w as f32 || sy > h as f32 {
                continue;
            }
            // +1 closes the hairline seams between adjacent cells that rounding would leave.
            context.rect(sx as f64, sy as f64, size + 1.0, size + 1.0);
        }
        context.fill();

        context.set_stroke_style_str("#222a35");
        context.set_line_width((420.0 * state.camera.zoom) as f64);
        for lane in &map.lanes {
            context.begin_path();
            for (index, (x, y)) in lane.iter().enumerate() {
                let (sx, sy) = state.camera.world_to_screen(from_fixed(*x), from_fixed(*y));
                if index == 0 {
                    context.move_to(sx as f64, sy as f64);
                } else {
                    context.line_to(sx as f64, sy as f64);
                }
            }
            context.stroke();
        }
    }

    for entity in entities {
        let (sx, sy) = state.camera.world_to_screen(entity.x, entity.y);
        let radius = match entity.kind {
            NetKind::Hero => 26.0,
            NetKind::Creep => 14.0,
            NetKind::Tower => 34.0,
            NetKind::Base => 52.0,
            NetKind::Zone => 0.0,
            NetKind::Projectile => 5.0,
        } * state.camera.zoom as f64;

        if matches!(entity.kind, NetKind::Zone) {
            continue;
        }

        // A bolt in flight, drawn before the health-bar code below — it has no health, and a
        // four-pixel bar over a five-pixel dot is noise.
        if matches!(entity.kind, NetKind::Projectile) {
            context.set_fill_style_str("#ffd866");
            context.begin_path();
            let _ = context.arc(sx as f64, sy as f64, radius.max(2.5), 0.0, TAU);
            context.fill();
            continue;
        }

        let colour = match (entity.team, entity.kind) {
            (NetTeam::Blue, NetKind::Hero) => "#5b9cff",
            (NetTeam::Blue, _) => "#33608f",
            (NetTeam::Red, NetKind::Hero) => "#ff6b6b",
            (NetTeam::Red, _) => "#8f3a3a",
            (NetTeam::Neutral, _) => "#7a7f8a",
        };

        context.set_fill_style_str(colour);
        context.begin_path();
        let _ = context.arc(
            sx as f64,
            sy as f64,
            radius,
            0.0,
            std::f64::consts::PI * 2.0,
        );
        context.fill();

        if Some(entity.id) == state.own_id {
            context.set_stroke_style_str("#ffffff");
            context.set_line_width(2.0);
            context.stroke();

            // Your reach, drawn faintly on the ground. Without it there is no way to tell why an
            // attack order walked you forward, or how much closer you need to be — and no way at
            // all to see that one hero is melee and another is not.
            if let Some(own) = state.buffer.own() {
                let reach = from_fixed(own.attack_range) * state.camera.zoom;
                if reach > 1.0 {
                    context.set_global_alpha(0.16);
                    context.set_stroke_style_str("#ffffff");
                    context.set_line_width(1.5);
                    context.begin_path();
                    let _ = context.arc(sx as f64, sy as f64, reach as f64, 0.0, TAU);
                    context.stroke();
                    context.set_global_alpha(1.0);
                }
            }
        }

        // Health bar. Interpolated along with position, so it slides rather than stepping.
        let bar = radius * 2.0;
        context.set_fill_style_str("#000000");
        context.fill_rect(sx as f64 - bar / 2.0, sy as f64 - radius - 8.0, bar, 4.0);
        context.set_fill_style_str("#6ee787");
        context.fill_rect(
            sx as f64 - bar / 2.0,
            sy as f64 - radius - 8.0,
            bar * entity.hp_fraction as f64,
            4.0,
        );

        // A hero's level, beside its bar. Knowing an enemy is four levels up on you is most of
        // what decides whether to fight them, and it is invisible otherwise.
        if entity.kind == NetKind::Hero && entity.level > 0 {
            context.set_fill_style_str("#f0f6fc");
            context.set_font("10px monospace");
            let _ = context.fill_text(
                &entity.level.to_string(),
                sx as f64 + bar / 2.0 + 3.0,
                sy as f64 - radius - 4.0,
            );
        }
    }

    // ── Effects ─────────────────────────────────────────────────────────────────────────
    //
    // Drawn after the entities so nothing hides a hit marker, and looked up by anchor id
    // against the interpolated positions so a number follows the thing it belongs to.
    let position_of = |id: u64| entities.iter().find(|e| e.id == id).map(|e| (e.x, e.y));

    for effect in state.effects.iter() {
        let progress = effect.progress();
        // An effect with its own place is drawn there; everything else follows its anchor. That
        // is what puts Cinder's blast on the ground it is burning rather than on the witch.
        let Some((wx, wy)) = effect.at.or_else(|| position_of(effect.anchor)) else {
            continue;
        };
        let (sx, sy) = state.camera.world_to_screen(wx, wy);
        let fade = 1.0 - progress;

        match effect.kind {
            EffectKind::DamageNumber { amount, on_self } => {
                // Rises as it fades, which is what makes several hits in the same place
                // readable as several hits rather than one flickering number.
                let lift = progress * 34.0;
                context.set_global_alpha(fade as f64);
                context.set_fill_style_str(if on_self { "#ff7b72" } else { "#ffd866" });
                context.set_font(if on_self {
                    "bold 18px monospace"
                } else {
                    "14px monospace"
                });
                let _ = context.fill_text(
                    &amount.to_string(),
                    sx as f64 - 8.0,
                    (sy - 30.0 - lift) as f64,
                );
                context.set_global_alpha(1.0);
            }
            EffectKind::HitLine { from, .. } => {
                let Some((fx, fy)) = position_of(from) else {
                    continue;
                };
                let (ax, ay) = state.camera.world_to_screen(fx, fy);
                context.set_global_alpha((fade * 0.9) as f64);
                context.set_stroke_style_str("#ffd866");
                context.set_line_width(2.0);
                context.begin_path();
                context.move_to(ax as f64, ay as f64);
                context.line_to(sx as f64, sy as f64);
                context.stroke();
                context.set_global_alpha(1.0);
            }
            EffectKind::CastRing { ability } => {
                // Each ability draws its own shape and colour. Every one of them used to be the
                // same blue ring, which meant a player could tell that *something* had been
                // cast and nothing else — twenty-four abilities that look identical are, from
                // the player's side, one ability.
                let spell = look(ability);
                let zoom = state.camera.zoom;
                context.set_global_alpha(fade as f64);
                context.set_stroke_style_str(spell.colour);
                context.set_fill_style_str(spell.colour);
                context.set_line_width(3.0);

                match spell.shape {
                    Shape::Ring => {
                        let radius = (14.0 + progress * spell.reach.min(220.0)) * zoom;
                        context.begin_path();
                        let _ = context.arc(sx as f64, sy as f64, radius as f64, 0.0, TAU);
                        context.stroke();
                    }
                    Shape::Blast => {
                        // Lands at full size and fades, rather than growing: a ground-targeted
                        // area covers what it covers from the instant it goes off.
                        context.set_global_alpha((fade * 0.35) as f64);
                        context.begin_path();
                        let _ = context.arc(
                            sx as f64,
                            sy as f64,
                            (spell.reach * zoom) as f64,
                            0.0,
                            TAU,
                        );
                        context.fill();
                        context.set_global_alpha(fade as f64);
                        context.begin_path();
                        let _ = context.arc(
                            sx as f64,
                            sy as f64,
                            (spell.reach * zoom) as f64,
                            0.0,
                            TAU,
                        );
                        context.stroke();
                    }
                    Shape::Beam => {
                        // From the caster toward where it was aimed. Before the aim point was on
                        // the wire this drew due east regardless, which made every skillshot
                        // look like it had missed.
                        let (fx, fy) = position_of(effect.anchor).unwrap_or((wx, wy));
                        let (ax, ay) = state.camera.world_to_screen(fx, fy);
                        let (dx, dy) = (sx - ax, sy - ay);
                        let len = (dx * dx + dy * dy).sqrt().max(1.0);
                        let reach = (spell.reach * zoom).min(len.max(spell.reach * zoom * 0.35));
                        let grow = 0.35 + progress * 0.65;
                        context.set_line_width(6.0);
                        context.begin_path();
                        context.move_to(ax as f64, ay as f64);
                        context.line_to(
                            (ax + dx / len * reach * grow) as f64,
                            (ay + dy / len * reach * grow) as f64,
                        );
                        context.stroke();
                    }
                    Shape::Implode => {
                        // Tightening rather than spreading: something *arriving*.
                        let radius = (spell.reach.min(220.0) * (1.0 - progress) + 8.0) * zoom;
                        context.begin_path();
                        let _ = context.arc(sx as f64, sy as f64, radius as f64, 0.0, TAU);
                        context.stroke();
                    }
                    Shape::Pulse => {
                        // Two rings out of phase, so a channel reads as ongoing rather than as
                        // one event.
                        for offset in [0.0f32, 0.5] {
                            let phase = (progress + offset) % 1.0;
                            context.set_global_alpha(((1.0 - phase) * fade) as f64);
                            let radius = (14.0 + phase * spell.reach.min(300.0)) * zoom;
                            context.begin_path();
                            let _ = context.arc(sx as f64, sy as f64, radius as f64, 0.0, TAU);
                            context.stroke();
                        }
                    }
                }
                context.set_global_alpha(1.0);
            }
            EffectKind::CastName { ability } => {
                let spell = look(ability);
                if spell.name.is_empty() {
                    continue;
                }
                // Rises and fades, well above the health bar so it does not fight with it.
                context.set_global_alpha((fade * fade) as f64);
                context.set_fill_style_str(spell.colour);
                context.set_font("bold 14px monospace");
                let _ = context.fill_text(
                    spell.name,
                    sx as f64 - spell.name.len() as f64 * 4.0,
                    (sy - 52.0 - progress * 18.0) as f64,
                );
                context.set_global_alpha(1.0);
            }
            EffectKind::Death => {
                context.set_global_alpha(fade as f64);
                context.set_fill_style_str("#f0f6fc");
                context.set_font("16px monospace");
                let _ = context.fill_text("x", sx as f64 - 5.0, sy as f64 + 5.0);
                context.set_global_alpha(1.0);
            }
        }
    }

    // Taking damage flashes the edge of the screen. On a phone the health bar above your hero is
    // a few pixels tall and under your thumb; this is the signal that reads at a glance.
    if state.hurt_flash > 0.0 {
        context.set_global_alpha((state.hurt_flash * 0.9).clamp(0.0, 0.5) as f64);
        context.set_stroke_style_str("#ff7b72");
        context.set_line_width(18.0);
        context.stroke_rect(0.0, 0.0, w, h);
        context.set_global_alpha(1.0);
    }

    // ── The ability bar ─────────────────────────────────────────────────────────────────
    //
    // A readout on desktop and the controls on a phone. Geometry comes from `Hud` so that what
    // is drawn and what is tappable cannot drift apart.
    let hud = Hud::layout(state.camera.width, state.camera.height, state.buffer.own());
    let armed_slot = match state.input.armed {
        Armed::Slot(slot) => Some(slot),
        Armed::None => None,
    };

    for view in &hud.slots {
        let (x, y, bw, bh) = (
            view.rect.x as f64,
            view.rect.y as f64,
            view.rect.w as f64,
            view.rect.h as f64,
        );

        context.set_fill_style_str(if view.filled { "#1b2029" } else { "#141820" });
        context.fill_rect(x, y, bw, bh);

        // The cooldown sweep fills from the bottom, so "nearly ready" is a glance rather than a
        // number to read mid-fight.
        if view.cooldown > 0.0 {
            context.set_fill_style_str("rgba(0,0,0,0.62)");
            context.fill_rect(x, y, bw, bh * view.cooldown as f64);
        }

        context.set_stroke_style_str(if Some(view.slot) == armed_slot {
            "#f0b429"
        } else {
            "#2d333b"
        });
        context.set_line_width(if Some(view.slot) == armed_slot {
            3.0
        } else {
            1.0
        });
        context.stroke_rect(x, y, bw, bh);

        context.set_fill_style_str(if view.filled { "#c9d1d9" } else { "#484f58" });
        context.set_font("11px monospace");
        let _ = context.fill_text(view.label, x + 4.0, y + 13.0);

        // The ability's name on its own button — the other half of teaching a player what a key
        // does, alongside the name that floats up when it fires.
        if let Some(own) = state.buffer.own() {
            // Straight off the snapshot rather than a cached copy. A copy needs an assignment
            // somewhere, and a missing assignment is invisible: `short_name` on an unknown id
            // returns an empty string, the name is skipped, and the bar silently shows nothing
            // but Q W E R. Reading the one source has no such failure mode.
            let ability = own
                .abilities
                .get(view.slot as usize)
                .copied()
                .unwrap_or(u16::MAX);
            let name = short_name(ability);
            if !name.is_empty() {
                let locked = view.slot as usize == 3 && own.level < 6;
                context.set_fill_style_str(if locked { "#6e7681" } else { "#c9d1d9" });
                context.set_font("10px monospace");
                let _ = context.fill_text(name, x + 4.0, y + bh - 6.0);
                if locked {
                    context.set_fill_style_str("#f0b429");
                    let _ = context.fill_text("6", x + bw - 10.0, y + 13.0);
                }
            }
        }
    }

    // Status, and the armed ability, drawn plainly. A HUD is a later job; knowing whether the
    // socket is alive is needed from the first run.
    context.set_fill_style_str("#c9d1d9");
    context.set_font("14px monospace");
    let _ = context.fill_text(&state.status, 12.0, 22.0);

    // What an armed ability is waiting for. "armed: slot 0" told a player nothing they did not
    // already know and, crucially, never told them a click was expected.
    if let Armed::Slot(slot) = state.input.armed {
        let ability = state
            .buffer
            .own()
            .and_then(|o| o.abilities.get(slot as usize).copied())
            .unwrap_or(u16::MAX);
        let spell = look(ability);
        let name = if spell.name.is_empty() {
            "ability"
        } else {
            spell.name
        };
        context.set_fill_style_str("#f0b429");
        context.set_font("bold 15px monospace");
        let _ = context.fill_text(
            &format!("{name} — click a target   (Esc to cancel)"),
            12.0,
            h - 96.0,
        );
    }

    // Why the last thing you pressed did nothing.
    if let Some((text, _)) = &state.notice {
        context.set_fill_style_str("#ff7b72");
        context.set_font("bold 15px monospace");
        let _ = context.fill_text(text, 12.0, h - 74.0);
    }

    if let Some(own) = state.buffer.own() {
        let line = format!(
            "lvl {}   gold {}   mana {}/{}",
            own.level,
            own.gold / 65536,
            own.mana / 65536,
            own.max_mana / 65536
        );
        let _ = context.fill_text(&line, 12.0, 42.0);

        // The experience bar. Small and out of the way, but a player with no sense of how close
        // the next level is cannot decide whether a fight is worth taking.
        if own.xp_for_next > 0 {
            let fraction = (own.xp_into_level as f64 / own.xp_for_next as f64).clamp(0.0, 1.0);
            context.set_fill_style_str("#21262d");
            context.fill_rect(12.0, 50.0, 160.0, 5.0);
            context.set_fill_style_str("#d2a8ff");
            context.fill_rect(12.0, 50.0, 160.0 * fraction, 5.0);
        }
    }
    if !state.buffer.is_healthy() && state.connected {
        context.set_fill_style_str("#ffa657");
        let _ = context.fill_text("connection unstable", 12.0, 62.0);
    }

    // Dead. The one state the player most needs told about, and the one the world cannot show
    // them — their hero is not in the snapshot, because it is not on the map.
    if let Some(own) = state.buffer.own() {
        if own.respawn_in > 0 {
            let seconds = own.respawn_in.div_ceil(moba_proto::TICK_HZ);
            context.set_fill_style_str("rgba(0,0,0,0.55)");
            context.fill_rect(0.0, 0.0, w, h);

            context.set_fill_style_str("#ff7b72");
            context.set_font("bold 26px monospace");
            let _ = context.fill_text("You are dead", w / 2.0 - 90.0, h / 2.0 - 10.0);

            context.set_fill_style_str("#c9d1d9");
            context.set_font("18px monospace");
            let _ = context.fill_text(
                &format!("respawning in {seconds}s"),
                w / 2.0 - 85.0,
                h / 2.0 + 22.0,
            );
        }
    }

    if let Some(outcome) = &state.outcome {
        context.set_fill_style_str("rgba(0,0,0,0.65)");
        context.fill_rect(0.0, h / 2.0 - 40.0, w, 80.0);
        context.set_fill_style_str("#f0f6fc");
        context.set_font("bold 28px monospace");
        let _ = context.fill_text(outcome, w / 2.0 - 90.0, h / 2.0 + 10.0);
    }
}
