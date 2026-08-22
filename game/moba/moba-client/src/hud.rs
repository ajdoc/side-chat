//! The ability bar: where the buttons are, and what they say.
//!
//! ## Why the layout is here and not in the renderer
//!
//! On desktop the bar is a readout — it shows cooldowns and which ability is armed. On a phone
//! it is also the *input*, because there is no keyboard to press Q with. Both of those need to
//! agree exactly on where each button is, and a layout computed twice is a layout that drifts
//! until taps land on the wrong ability.
//!
//! So the rectangles are computed once, here, in plain Rust, and both the renderer and the
//! touch handler ask this module.

use moba_proto::NetSelf;

/// Four hero abilities then six item slots. Mirrors `AbilitySlots` in the sim, which is the
/// contract that makes a tap on the third button cast the third ability.
pub const SLOT_COUNT: usize = 10;

#[derive(Clone, Copy, Debug, PartialEq)]
pub struct Rect {
    pub x: f32,
    pub y: f32,
    pub w: f32,
    pub h: f32,
}

impl Rect {
    pub fn contains(&self, x: f32, y: f32) -> bool {
        x >= self.x && x <= self.x + self.w && y >= self.y && y <= self.y + self.h
    }
}

#[derive(Clone, Copy, Debug)]
pub struct SlotView {
    pub slot: u8,
    pub rect: Rect,
    pub label: &'static str,
    /// 0.0 ready, 1.0 just used. What the renderer draws as a sweep over the button.
    pub cooldown: f32,
    /// Whether there is anything in this slot at all.
    pub filled: bool,
}

/// The bar's geometry for a given viewport.
pub struct Hud {
    pub slots: Vec<SlotView>,
    /// True when the viewport is narrow enough that this is probably a phone. Buttons get bigger
    /// and the bar gets taller, because a finger is not a mouse pointer.
    pub touch_sized: bool,
}

const LABELS: [&str; SLOT_COUNT] = ["Q", "W", "E", "R", "1", "2", "3", "4", "5", "6"];

impl Hud {
    /// Lay the bar out along the bottom of the viewport.
    ///
    /// Centred rather than left-aligned so it reads the same on a phone held either way, and
    /// sized from the viewport so it does not become a row of postage stamps on a large screen or
    /// swallow a small one.
    pub fn layout(width: f32, height: f32, own: Option<&NetSelf>) -> Hud {
        // Below this the pointer is almost certainly a finger. Apple's 44pt guidance is the floor
        // for a tap target and this keeps buttons above it on any phone.
        let touch_sized = width < 820.0;

        let button = if touch_sized { 56.0 } else { 46.0 };
        let gap = if touch_sized { 8.0 } else { 6.0 };
        let total = SLOT_COUNT as f32 * button + (SLOT_COUNT as f32 - 1.0) * gap;

        // Shrink to fit rather than overflowing: a bar wider than the screen puts the item slots
        // off the edge, and those are the ones a phone player most needs to reach.
        let scale = if total > width - 24.0 {
            (width - 24.0) / total
        } else {
            1.0
        };
        let button = button * scale;
        let gap = gap * scale;
        let total = SLOT_COUNT as f32 * button + (SLOT_COUNT as f32 - 1.0) * gap;

        let left = (width - total) / 2.0;
        let bottom_margin = if touch_sized { 24.0 } else { 16.0 };
        let y = height - button - bottom_margin;

        let slots = (0..SLOT_COUNT)
            .map(|index| {
                let cooldown = own
                    .and_then(|o| o.cooldowns.get(index).copied())
                    .map(|ticks| {
                        // No ability's cooldown is known to the client, so this is normalised
                        // against a nominal maximum purely to draw a sweep. It is a progress
                        // indicator, not a timer, and it is honest about being one.
                        (ticks as f32 / (moba_proto::TICK_HZ as f32 * 30.0)).clamp(0.0, 1.0)
                    })
                    .unwrap_or(0.0);

                SlotView {
                    slot: index as u8,
                    rect: Rect {
                        x: left + index as f32 * (button + gap),
                        y,
                        w: button,
                        h: button,
                    },
                    label: LABELS[index],
                    cooldown,
                    filled: own
                        .map(|o| index < 4 || o.items.len() > index - 4)
                        .unwrap_or(false),
                }
            })
            .collect();

        Hud { slots, touch_sized }
    }

    /// Which slot a point is inside, if any.
    ///
    /// The single source of truth for "did that tap hit a button", used by the touch handler and
    /// by the mouse handler alike — so a click on the bar never also issues a move order into
    /// the world behind it.
    pub fn hit(&self, x: f32, y: f32) -> Option<u8> {
        self.slots
            .iter()
            .find(|s| s.rect.contains(x, y))
            .map(|s| s.slot)
    }

    /// Whether a point is anywhere on the bar. Used to swallow clicks that missed a button but
    /// still landed on the HUD.
    pub fn contains(&self, x: f32, y: f32) -> bool {
        match (self.slots.first(), self.slots.last()) {
            (Some(first), Some(last)) => {
                y >= first.rect.y
                    && y <= first.rect.y + first.rect.h
                    && x >= first.rect.x
                    && x <= last.rect.x + last.rect.w
            }
            _ => false,
        }
    }
}
