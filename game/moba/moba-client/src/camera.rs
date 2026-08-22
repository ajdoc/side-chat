//! Screen ↔ world.
//!
//! One place, for the same reason `spaceProjection.ts` is one place on the Side Space side: a
//! second copy of this arithmetic drifts from the first, and the symptom is clicks landing
//! somewhere other than where they were aimed.

use crate::interp::RenderEntity;

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
            zoom: 0.5,
            width,
            height,
        }
    }

    pub fn world_to_screen(&self, world_x: f32, world_y: f32) -> (f32, f32) {
        (
            (world_x - self.x) * self.zoom + self.width / 2.0,
            (world_y - self.y) * self.zoom + self.height / 2.0,
        )
    }

    pub fn screen_to_world(&self, screen_x: f32, screen_y: f32) -> (f32, f32) {
        (
            (screen_x - self.width / 2.0) / self.zoom + self.x,
            (screen_y - self.height / 2.0) / self.zoom + self.y,
        )
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

    /// The entity under a screen position, if any.
    ///
    /// Nearest-first within a generous radius, because a MOBA is played at a zoom where units are
    /// small and demanding pixel accuracy on a moving target is unkind.
    pub fn pick<'a>(
        &self,
        entities: &'a [RenderEntity],
        screen_x: f32,
        screen_y: f32,
    ) -> Option<&'a RenderEntity> {
        let (wx, wy) = self.screen_to_world(screen_x, screen_y);
        let radius = 70.0;
        entities
            .iter()
            .map(|e| (e, (e.x - wx).powi(2) + (e.y - wy).powi(2)))
            .filter(|(_, d2)| *d2 <= radius * radius)
            .min_by(|a, b| a.1.total_cmp(&b.1))
            .map(|(e, _)| e)
    }
}
