//! The minimap.
//!
//! ## Why it is fog-correct for free
//!
//! A minimap in this genre is the single most information-dense thing on screen, and the usual
//! way to get it wrong is to draw it from a different source than the world — a source that
//! knows about enemies the main view is hiding. That cannot happen here, because the client only
//! ever *has* what the server filtered for its team. The minimap draws the same snapshot the
//! world does, so an enemy in the fog is missing from both or neither.
//!
//! The geometry lives here rather than in the renderer for the reason the ability bar's does:
//! the same rectangle has to be drawn *and* hit-tested, and a layout computed twice is a layout
//! that drifts until clicks land somewhere other than where they were aimed.

/// Where the minimap sits and how big it is.
#[derive(Clone, Copy, Debug)]
pub struct Minimap {
    pub x: f32,
    pub y: f32,
    pub size: f32,
    /// The world is this many units across, and square.
    pub world_size: f32,
}

impl Minimap {
    /// Lay it out in the bottom-left corner.
    ///
    /// Bottom-left rather than bottom-right because the ability bar is centred along the bottom
    /// and the right side is where a scoreboard will go. Sized as a fraction of the viewport
    /// with a floor and a ceiling: a fixed pixel size is either a postage stamp on a monitor or
    /// half the screen on a phone.
    pub fn layout(width: f32, height: f32, world_size: f32) -> Minimap {
        let size = (height * 0.26).clamp(120.0, 260.0).min(width * 0.4);
        Minimap {
            x: 12.0,
            // Clear of the ability bar, which is centred but tall.
            y: height - size - 12.0,
            size,
            world_size: world_size.max(1.0),
        }
    }

    /// A world position as a point on the minimap.
    pub fn world_to_map(&self, wx: f32, wy: f32) -> (f32, f32) {
        (
            self.x + (wx / self.world_size).clamp(0.0, 1.0) * self.size,
            self.y + (wy / self.world_size).clamp(0.0, 1.0) * self.size,
        )
    }

    /// A point on the minimap as a world position.
    ///
    /// The inverse of [`world_to_map`], and the reason a click on the minimap can be an order.
    pub fn map_to_world(&self, mx: f32, my: f32) -> (f32, f32) {
        (
            ((mx - self.x) / self.size).clamp(0.0, 1.0) * self.world_size,
            ((my - self.y) / self.size).clamp(0.0, 1.0) * self.world_size,
        )
    }

    pub fn contains(&self, x: f32, y: f32) -> bool {
        x >= self.x && x <= self.x + self.size && y >= self.y && y <= self.y + self.size
    }

    /// How big a dot of a given world radius should be drawn.
    ///
    /// Floored at one pixel: a creep scaled honestly onto a 200-pixel map is a third of a pixel
    /// and simply does not appear, which makes a wave invisible exactly when knowing where it is
    /// matters most.
    pub fn dot(&self, world_radius: f32) -> f32 {
        (world_radius / self.world_size * self.size).max(1.0)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn map() -> Minimap {
        Minimap::layout(1280.0, 720.0, 6000.0)
    }

    #[test]
    fn world_and_map_round_trip() {
        // The symptom of getting this wrong is a click on the minimap sending your hero
        // somewhere other than where you pointed — obvious in play, maddening to trace.
        let m = map();
        for (wx, wy) in [
            (0.0, 0.0),
            (3000.0, 3000.0),
            (5999.0, 100.0),
            (700.0, 5300.0),
        ] {
            let (mx, my) = m.world_to_map(wx, wy);
            let (bx, by) = m.map_to_world(mx, my);
            assert!((bx - wx).abs() < 1.0, "x {wx} round-tripped to {bx}");
            assert!((by - wy).abs() < 1.0, "y {wy} round-tripped to {by}");
        }
    }

    #[test]
    fn the_corners_of_the_world_are_the_corners_of_the_map() {
        let m = map();
        let (x0, y0) = m.world_to_map(0.0, 0.0);
        let (x1, y1) = m.world_to_map(6000.0, 6000.0);
        assert!((x0 - m.x).abs() < 0.01 && (y0 - m.y).abs() < 0.01);
        assert!((x1 - (m.x + m.size)).abs() < 0.01 && (y1 - (m.y + m.size)).abs() < 0.01);
    }

    #[test]
    fn anything_off_the_world_is_clamped_onto_the_edge() {
        // Nothing should be drawn outside the minimap's own box, whatever the sim hands us.
        let m = map();
        let (x, y) = m.world_to_map(-5000.0, 99999.0);
        assert!(x >= m.x && x <= m.x + m.size);
        assert!(y >= m.y && y <= m.y + m.size);
    }

    #[test]
    fn it_stays_on_screen_at_every_viewport_size() {
        for (w, h) in [
            (320.0f32, 568.0f32),
            (414.0, 896.0),
            (1280.0, 720.0),
            (2560.0, 1440.0),
        ] {
            let m = Minimap::layout(w, h, 6000.0);
            assert!(m.x >= 0.0, "off the left at {w}x{h}");
            assert!(m.y >= 0.0, "off the top at {w}x{h}");
            assert!(m.x + m.size <= w, "off the right at {w}x{h}");
            assert!(m.y + m.size <= h, "off the bottom at {w}x{h}");
            // And never so large it swallows a phone.
            assert!(m.size <= w * 0.45, "half the screen at {w}x{h}");
        }
    }

    #[test]
    fn hit_testing_matches_what_is_drawn() {
        let m = map();
        assert!(m.contains(m.x + 1.0, m.y + 1.0));
        assert!(m.contains(m.x + m.size - 1.0, m.y + m.size - 1.0));
        assert!(!m.contains(m.x - 5.0, m.y));
        assert!(!m.contains(m.x, m.y + m.size + 5.0));
    }

    #[test]
    fn a_creep_is_at_least_one_pixel() {
        // Scaled honestly a creep is a third of a pixel and never appears, which hides a wave
        // exactly when knowing where it is matters most.
        let m = map();
        assert!(m.dot(14.0) >= 1.0);
        assert!(
            m.dot(52.0) > m.dot(14.0),
            "a base is no bigger than a creep"
        );
    }
}
