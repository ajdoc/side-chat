//! Where you are standing on the world, and how close.
//!
//! The *shape* of the projection lives in [`crate::projection`]; this adds the pan and the zoom.
//! One place, for the same reason `spaceProjection.ts` is one place on the Side Space side: a
//! second copy of this arithmetic drifts from the first, and the symptom is clicks landing
//! somewhere other than where they were aimed.

use crate::interp::RenderEntity;
use crate::sprites::draw_radius;
use moba_proto::NetKind;
use crate::projection::{project, unproject};

#[derive(Clone, Copy, Debug)]
pub struct Camera {
    /// World position at the centre of the viewport.
    pub x: f32,
    pub y: f32,
    pub zoom: f32,
    pub width: f32,
    pub height: f32,
}

impl Camera {
    pub fn new(width: f32, height: f32) -> Camera {
        Camera {
            x: 0.0,
            y: 0.0,
            zoom: 1.0,
            width,
            height,
        }
    }

    pub fn world_to_screen(&self, world_x: f32, world_y: f32) -> (f32, f32) {
        let (px, py) = project(world_x, world_y);
        let (cx, cy) = project(self.x, self.y);
        (
            (px - cx) * self.zoom + self.width / 2.0,
            (py - cy) * self.zoom + self.height / 2.0,
        )
    }

    pub fn screen_to_world(&self, screen_x: f32, screen_y: f32) -> (f32, f32) {
        let (cx, cy) = project(self.x, self.y);
        unproject(
            (screen_x - self.width / 2.0) / self.zoom + cx,
            (screen_y - self.height / 2.0) / self.zoom + cy,
        )
    }

    /// The six numbers that put the canvas into world space, for drawing the ground plane.
    ///
    /// The terrain is filled through this rather than point by point, which is what keeps it a
    /// single path and what makes its textures lie on the ground instead of on the screen.
    pub fn ground_matrix(&self) -> [f64; 6] {
        let (cx, cy) = project(self.x, self.y);
        crate::projection::matrix(
            self.zoom,
            -cx * self.zoom + self.width / 2.0,
            -cy * self.zoom + self.height / 2.0,
        )
    }

    /// How far out and how far in the wheel may go.
    ///
    /// Out far enough to see a whole lane and the fight at the end of it; in far enough to pick
    /// one creep out of a wave. Beyond either end the game stops being playable rather than
    /// becoming more so — zoomed fully out a hero is three pixels, and fully in you cannot see
    /// what is walking at you.
    /// Doubled when the view became isometric, and not as a matter of taste.
    ///
    /// The projection scales world x by `ISO_X`, which is a half — so at an unchanged zoom every
    /// hero, creep and lane came out half the size it had been, and the game read as small and
    /// far away. That is most of what "the movement feels clunky" turned out to be: nothing had
    /// slowed down, but a hero crossing a lane covered half as many pixels doing it.
    ///
    /// Doubling both ends restores the apparent scale that was tuned by playing, which is the
    /// scale these numbers were chosen at in the first place.
    pub const MIN_ZOOM: f32 = 0.36;
    pub const MAX_ZOOM: f32 = 2.8;

    /// Zoom by a wheel notch. Positive zooms in.
    ///
    /// Multiplicative rather than additive, so a notch feels the same at every distance — adding
    /// a fixed amount makes the last notch out enormous and the last notch in imperceptible.
    pub fn zoom_by(&mut self, notches: f32) {
        let factor = 1.15f32.powf(notches);
        self.zoom = (self.zoom * factor).clamp(Self::MIN_ZOOM, Self::MAX_ZOOM);
    }

    /// Follow a target, easing rather than snapping.
    ///
    /// A camera locked exactly to the hero transfers every interpolation wobble in the hero's
    /// position onto the entire world, which reads as the map shaking. Easing leaves the wobble
    /// on the hero, where it is a pixel and nobody notices.
    pub fn follow(&mut self, target_x: f32, target_y: f32, dt_seconds: f32) {
        let rate = (dt_seconds * 8.0).clamp(0.0, 1.0);
        self.x += (target_x - self.x) * rate;
        self.y += (target_y - self.y) * rate;
    }

    /// How much slack a click gets beyond a unit's own drawn size, in screen pixels.
    ///
    /// A MOBA is played at a zoom where units are small and moving, and demanding pixel accuracy
    /// on one is unkind. Constant in *pixels*, so the slack is the same however far out you are
    /// zoomed — the whole point is that it is forgiveness for the hand, and a hand does not zoom.
    const PICK_SLACK: f32 = 18.0;

    /// The entity under a screen position, if any.
    ///
    /// ## Why this is measured on screen and not in the world
    ///
    /// It used to compare world distance against a fixed world radius, which was wrong in two
    /// ways at once and got worse when the view became isometric.
    ///
    /// Wrong first because a world radius does not scale with zoom: seventy world units is a
    /// comfortable target zoomed in and about six pixels zoomed out, so targeting quietly got
    /// harder the further out you looked. Wrong again because on an isometric ground a world
    /// circle is a squashed ellipse on screen — so the click area was not even the shape of the
    /// thing being clicked, and was most generous in the direction the unit was thinnest.
    ///
    /// Measuring in screen pixels against what was actually drawn fixes both, and has the
    /// property worth insisting on: **you can click what you can see, and only that.**
    pub fn pick<'a>(
        &self,
        entities: &'a [RenderEntity],
        screen_x: f32,
        screen_y: f32,
    ) -> Option<&'a RenderEntity> {
        entities
            .iter()
            .filter(|e| !matches!(e.kind, NetKind::Zone | NetKind::Projectile))
            .filter_map(|e| {
                let (sx, sy) = self.world_to_screen(e.x, e.y);
                let reach = draw_radius(e.kind) * self.zoom + Self::PICK_SLACK;
                let d2 = (sx - screen_x).powi(2) + (sy - screen_y).powi(2);
                (d2 <= reach * reach).then_some((e, d2))
            })
            .min_by(|a, b| a.1.total_cmp(&b.1))
            .map(|(e, _)| e)
    }
}
