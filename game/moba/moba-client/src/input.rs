//! Turning clicks and keys into orders.
//!
//! Kept apart from the renderer and from the socket because it is the part with rules: which
//! mouse button means what, what a click does while an ability is armed, and what the client is
//! allowed to assume before the server answers.

use moba_proto::{ClientMessage, NetId, NetTarget};

/// The genre's convention, and worth stating because it is the opposite of most games: **left
/// click selects, right click commands.** A left click on an enemy does nothing; a right click
/// on an enemy attacks it.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum MouseButton {
    Left,
    Right,
}

/// An ability waiting for its target. Pressing Q arms; the next click fires.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Armed {
    None,
    /// Waiting for a click to say where.
    Slot(u8),
}

/// What the client knows about aiming.
pub struct Input {
    pub armed: Armed,
    /// The entity under the cursor, if any. Supplied by the renderer, which is the only thing
    /// that knows where entities are on screen.
    pub hovered: Option<NetId>,
}

impl Default for Input {
    fn default() -> Input {
        Input::new()
    }
}

impl Input {
    pub fn new() -> Input {
        Input {
            armed: Armed::None,
            hovered: None,
        }
    }

    /// Arm an ability slot, or disarm if it was already armed.
    ///
    /// Pressing the same key twice cancelling is the behaviour every game in the genre has, and
    /// its absence is felt immediately: an armed ability with no way out means a misclick is
    /// forced to become a cast.
    pub fn press_ability(&mut self, slot: u8) {
        self.armed = match self.armed {
            Armed::Slot(current) if current == slot => Armed::None,
            _ => Armed::Slot(slot),
        };
    }

    /// Escape, or a right-click while armed. Both cancel.
    pub fn cancel(&mut self) {
        self.armed = Armed::None;
    }

    /// A click at a world position. Returns the message to send, if any.
    pub fn click(
        &mut self,
        button: MouseButton,
        world_x: i32,
        world_y: i32,
    ) -> Option<ClientMessage> {
        match (button, self.armed) {
            // Armed: a left click fires it, at whatever is under the cursor or at the ground.
            (MouseButton::Left, Armed::Slot(slot)) => {
                let target = match self.hovered {
                    Some(id) => NetTarget::Unit(id),
                    None => NetTarget::Point {
                        x: world_x,
                        y: world_y,
                    },
                };
                self.armed = Armed::None;
                Some(ClientMessage::Cast { slot, target })
            }
            // Armed: a right click cancels rather than issuing a move. Issuing both would make
            // every cancelled cast also a walk into danger.
            (MouseButton::Right, Armed::Slot(_)) => {
                self.armed = Armed::None;
                None
            }
            // Not armed: right click commands.
            (MouseButton::Right, Armed::None) => Some(match self.hovered {
                Some(id) => ClientMessage::Attack { target: id },
                None => ClientMessage::MoveTo {
                    x: world_x,
                    y: world_y,
                },
            }),
            // Not armed: left click is selection only, which the server does not need to hear
            // about. Sending it anyway would be a message per click for no effect.
            (MouseButton::Left, Armed::None) => None,
        }
    }

    /// Spend a skill point on an ability.
    ///
    /// A separate gesture from casting it — held modifier plus the key, the genre's convention —
    /// because the two are pressed in the same breath during a fight and a misfire in either
    /// direction is expensive: levelling the wrong ability cannot be undone, and casting when
    /// you meant to level wastes a cooldown.
    pub fn learn(&mut self, slot: u8) -> ClientMessage {
        // Learning cancels an armed ability. A player reaching for the modifier has changed
        // their mind about casting.
        self.armed = Armed::None;
        ClientMessage::Learn { slot }
    }

    /// `S` — stop. Also clears an armed ability, because a player pressing stop means it.
    pub fn stop(&mut self) -> ClientMessage {
        self.armed = Armed::None;
        ClientMessage::Stop
    }

    /// A tap in the world, on a touch screen.
    ///
    /// ## Why a tap is a right-click
    ///
    /// A finger has one button. The genre's whole control scheme is built on the *second* one —
    /// right-click to move and attack — so on touch the single available gesture has to be the
    /// command gesture, not the selection one. Mapping a tap to left-click instead would produce
    /// a game where nothing you do moves your hero.
    ///
    /// When an ability is armed the tap targets it instead, which is the same rule the mouse
    /// path follows; the difference is only which physical button gets you there.
    pub fn tap(&mut self, world_x: i32, world_y: i32) -> Option<ClientMessage> {
        match self.armed {
            Armed::Slot(_) => self.click(MouseButton::Left, world_x, world_y),
            Armed::None => self.click(MouseButton::Right, world_x, world_y),
        }
    }

    /// A tap on an ability button.
    ///
    /// Arms it, exactly as pressing the key would — except for a self-cast, which has nothing to
    /// aim at. Making a phone player arm a self-cast and then tap the world to confirm would be
    /// two gestures for something the keyboard does in one.
    ///
    /// The client cannot know a spec's targeting mode (the catalogue lives in the sim), so it
    /// cannot decide that here. Instead the caller passes what it knows, and the default is to
    /// arm — the safe direction, since a wrongly-armed ability can be cancelled and a wrongly
    /// *fired* one cannot.
    pub fn press_button(&mut self, slot: u8, self_cast: bool) -> Option<ClientMessage> {
        if self_cast {
            self.armed = Armed::None;
            return Some(ClientMessage::Cast {
                slot,
                target: NetTarget::None,
            });
        }
        self.press_ability(slot);
        None
    }
}

/// Which slot a key maps to.
///
/// ## Why not Q W E R
///
/// W is a movement key the moment WASD exists, and A, S and D are gone with it. Every game in
/// the genre that offers keyboard movement moves its abilities off the home row for exactly this
/// reason, and lands on **Q E R F** — the four keys nearest WASD that WASD does not use.
///
/// The inventory keeps `1`–`6`, which never conflicted.
pub fn slot_for_key(key: &str) -> Option<u8> {
    Some(match key {
        "q" | "Q" => 0,
        "e" | "E" => 1,
        "r" | "R" => 2,
        "f" | "F" => 3,
        "1" => 4,
        "2" => 5,
        "3" => 6,
        "4" => 7,
        "5" => 8,
        "6" => 9,
        _ => return None,
    })
}

/// Which way a movement key points, if it is one.
///
/// Arrows alongside WASD because they cost nothing and someone will reach for them — and on a
/// keyboard laid out for another language, WASD is not always where W A S D are.
///
/// Y grows downward, matching the world's axes and the canvas's, so "up" is negative.
pub fn direction_for_key(key: &str) -> Option<(f32, f32)> {
    Some(match key {
        "w" | "W" | "ArrowUp" => (0.0, -1.0),
        "s" | "S" | "ArrowDown" => (0.0, 1.0),
        "a" | "A" | "ArrowLeft" => (-1.0, 0.0),
        "d" | "D" | "ArrowRight" => (1.0, 0.0),
        _ => return None,
    })
}

/// The keys currently held, and the direction they add up to.
///
/// Held as a set rather than a single "last pressed" direction, because diagonals are the whole
/// point: W and D together must mean north-east, not whichever was pressed second.
#[derive(Default)]
pub struct HeldKeys {
    keys: Vec<String>,
}

impl HeldKeys {
    /// Record a key going down. Returns whether the direction changed.
    pub fn press(&mut self, key: &str) -> bool {
        if direction_for_key(key).is_none() || self.keys.iter().any(|k| k == key) {
            return false;
        }
        self.keys.push(key.to_string());
        true
    }

    /// Record a key coming up. Returns whether the direction changed.
    pub fn release(&mut self, key: &str) -> bool {
        let before = self.keys.len();
        self.keys.retain(|k| k != key);
        self.keys.len() != before
    }

    /// Everything let go at once — used when the window loses focus, which otherwise leaves a
    /// hero walking into the fog because the key-up never arrived.
    pub fn clear(&mut self) -> bool {
        let had = !self.keys.is_empty();
        self.keys.clear();
        had
    }

    /// The unit vector the held keys add up to, or zero for none.
    pub fn direction(&self) -> (f32, f32) {
        let (mut x, mut y) = (0.0f32, 0.0f32);
        for key in &self.keys {
            if let Some((dx, dy)) = direction_for_key(key) {
                x += dx;
                y += dy;
            }
        }
        // Opposite keys cancel, which is what a player holding both means.
        let length = (x * x + y * y).sqrt();
        if length < 0.001 {
            return (0.0, 0.0);
        }
        (x / length, y / length)
    }

    pub fn is_empty(&self) -> bool {
        self.keys.is_empty()
    }
}
