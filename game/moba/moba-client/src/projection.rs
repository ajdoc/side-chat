//! World ↔ the plane the world is drawn on.
//!
//! ## Why this is its own module
//!
//! The same reason `spaceProjection.ts` is one file on the Side Space side: every click, every
//! camera follow, every terrain fill and every sprite placement has to agree about where a world
//! point lands on screen. A second copy of this arithmetic drifts from the first, and the symptom
//! is clicks landing somewhere other than where they were aimed — which is miserable to diagnose,
//! because the game is not wrong, only the picture of it.
//!
//! [`Camera`](crate::camera::Camera) then adds the pan and the zoom on top. This module knows
//! nothing about either: it is the fixed shape of the ground, not where you are standing on it.
//!
//! ## The projection
//!
//! Two-to-one dimetric — the "isometric" of games rather than of draughtsmen. A world square
//! becomes a diamond twice as wide as it is tall, which is what makes the arithmetic exact in
//! halves and quarters rather than in irrational multiples of √3, and what every tile-based
//! renderer since the 1990s has used for the same reason.
//!
//! It is a *linear* map, with no perspective and no depth divide. That is load-bearing: it means
//! the whole ground plane can be handed to the canvas as one transform matrix, so terrain fills
//! and their textures come out sheared correctly with no per-point work, and it means the inverse
//! is exact rather than iterative.

/// Half the width of a world unit's footprint, along screen x.
const ISO_X: f32 = 0.5;
/// Quarter of a world unit's footprint along screen y. Half of [`ISO_X`], which is what makes it
/// two-to-one.
const ISO_Y: f32 = 0.25;

/// World to the drawing plane, before any camera.
#[inline]
pub fn project(x: f32, y: f32) -> (f32, f32) {
    ((x - y) * ISO_X, (x + y) * ISO_Y)
}

/// The drawing plane back to world. Exact — see the module note on linearity.
#[inline]
pub fn unproject(px: f32, py: f32) -> (f32, f32) {
    // From `u = x - y = px / ISO_X` and `v = x + y = py / ISO_Y`.
    let u = px / ISO_X;
    let v = py / ISO_Y;
    ((u + v) / 2.0, (v - u) / 2.0)
}

/// The projection as the six numbers a canvas transform wants, for a given zoom and screen
/// offset: `screen_x = a*wx + c*wy + e`, `screen_y = b*wx + d*wy + f`.
///
/// Handing the ground plane to the canvas this way rather than converting every point is what
/// keeps the terrain a single path — and it is why a `CanvasPattern` filled under this transform
/// comes out lying on the ground, sheared with it, instead of pasted flat on the screen.
pub fn matrix(zoom: f32, offset_x: f32, offset_y: f32) -> [f64; 6] {
    [
        (ISO_X * zoom) as f64,
        (ISO_Y * zoom) as f64,
        (-ISO_X * zoom) as f64,
        (ISO_Y * zoom) as f64,
        offset_x as f64,
        offset_y as f64,
    ]
}

/// How much shorter the ground is than it is wide, for anything that has to lie *on* it.
///
/// A vision radius, a spell's area and an attack range are circles in the world, and a circle on
/// this plane is an ellipse squashed by exactly this much. Drawing them as circles is the tell
/// that gives away a fake isometric view instantly.
pub const GROUND_SQUASH: f32 = ISO_Y / ISO_X;

/// Which of eight directions a world heading points, as an index.
///
/// Zero is world +x, and they run anticlockwise on screen. Buckets are on the *world* heading
/// rather than the projected one: a unit walking due east is drawn with its east sprite, and the
/// projection is what makes east look down-and-right. Bucketing after projecting would give two
/// different world directions the same sprite.
pub fn direction_bucket(dx: f32, dy: f32) -> usize {
    if dx == 0.0 && dy == 0.0 {
        return 0;
    }
    let angle = dy.atan2(dx);
    let step = std::f32::consts::TAU / 8.0;
    // +0.5 so each bucket is centred on its direction rather than starting at it.
    let index = (angle / step + 0.5).floor() as i32;
    index.rem_euclid(8) as usize
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn projection_round_trips() {
        for (x, y) in [(0.0, 0.0), (6000.0, 0.0), (0.0, 6000.0), (3000.0, 3000.0), (711.0, 4213.0)]
        {
            let (px, py) = project(x, y);
            let (bx, by) = unproject(px, py);
            assert!((bx - x).abs() < 0.01, "x {x} -> {bx}");
            assert!((by - y).abs() < 0.01, "y {y} -> {by}");
        }
    }

    #[test]
    fn the_world_becomes_a_diamond_twice_as_wide_as_tall() {
        let corners = [(0.0, 0.0), (6000.0, 0.0), (6000.0, 6000.0), (0.0, 6000.0)];
        let projected: Vec<(f32, f32)> = corners.iter().map(|(x, y)| project(*x, *y)).collect();
        let width = projected.iter().map(|p| p.0).fold(f32::MIN, f32::max)
            - projected.iter().map(|p| p.0).fold(f32::MAX, f32::min);
        let height = projected.iter().map(|p| p.1).fold(f32::MIN, f32::max)
            - projected.iter().map(|p| p.1).fold(f32::MAX, f32::min);
        assert!((width / height - 2.0).abs() < 0.001, "{width} x {height}");
    }

    #[test]
    fn the_matrix_agrees_with_the_function() {
        // The terrain is drawn through the matrix and the units through `project`. If these two
        // ever disagree the ground slides out from under everything standing on it.
        let (zoom, ox, oy) = (0.8f32, 120.0f32, -40.0f32);
        let m = matrix(zoom, ox, oy);
        for (x, y) in [(0.0, 0.0), (1234.0, 5678.0), (6000.0, 6000.0)] {
            let (px, py) = project(x, y);
            let (ex, ey) = (px * zoom + ox, py * zoom + oy);
            let mx = m[0] * x as f64 + m[2] * y as f64 + m[4];
            let my = m[1] * x as f64 + m[3] * y as f64 + m[5];
            assert!((mx - ex as f64).abs() < 0.01, "{mx} vs {ex}");
            assert!((my - ey as f64).abs() < 0.01, "{my} vs {ey}");
        }
    }

    #[test]
    fn depth_is_the_sum_of_the_coordinates() {
        // What the draw order sorts on. Anything with a larger x+y is nearer the viewer, so it
        // must be drawn later. Worth pinning: it is the whole of the depth rule.
        let near = project(5000.0, 5000.0);
        let far = project(1000.0, 1000.0);
        assert!(near.1 > far.1, "larger x+y should be further down the screen");
    }

    #[test]
    fn the_eight_directions_are_distinct_and_stable() {
        let mut seen = std::collections::BTreeSet::new();
        for i in 0..8 {
            let angle = std::f32::consts::TAU * i as f32 / 8.0;
            seen.insert(direction_bucket(angle.cos(), angle.sin()));
        }
        assert_eq!(seen.len(), 8, "eight headings should give eight buckets");
        assert_eq!(direction_bucket(1.0, 0.0), 0);
        assert_eq!(direction_bucket(0.0, 0.0), 0, "a still unit keeps a valid sprite");
    }

    #[test]
    fn a_bucket_is_centred_on_its_direction() {
        // A heading a few degrees either side of due east is still east. If the buckets were
        // aligned to their own edges instead, a unit walking straight would flicker between two
        // sprites on the smallest wobble in its facing.
        let small = std::f32::consts::TAU / 40.0;
        assert_eq!(direction_bucket(small.cos(), small.sin()), 0);
        assert_eq!(direction_bucket(small.cos(), -small.sin()), 0);
    }
}
