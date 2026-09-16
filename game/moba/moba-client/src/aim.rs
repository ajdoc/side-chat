//! What an armed ability is about to do, worked out before the click.
//!
//! ## Why this is its own module rather than a few lines in the renderer
//!
//! Aiming is the only part of the client that makes the player a *promise*: draw a ring and they
//! will believe a cast at that spot lands. If the promise disagrees with the server the player
//! does not conclude that the ring is wrong — they conclude the game is unreliable, and then
//! stop trusting the parts that were right. So the range check is written once, tested, and
//! measured against the same number the server refuses with rather than a mirror of it.
//!
//! Everything here is pure geometry over values that arrived on the wire. Nothing is decided
//! locally; a cast the client believes is out of range is still *sent*, and the server is still
//! the one that says no. The preview is a courtesy, never a gate.

use moba_proto::NetTargeting;

/// What to draw for the currently armed ability.
#[derive(Clone, Copy, PartialEq, Debug)]
pub enum Aim {
    /// Nothing to preview: no ability armed, or one that fires the instant it is pressed.
    None,
    /// A place on the ground, with the effect's own footprint.
    Point {
        x: f32,
        y: f32,
        in_range: bool,
    },
    /// A direction from the caster. Skillshots travel their own length whatever the cursor is
    /// doing, so this reports where the shot actually ends rather than where the cursor is.
    Vector {
        to_x: f32,
        to_y: f32,
    },
    /// A unit. There is nothing to draw on the ground beyond the range ring, but whether the
    /// thing under the cursor is reachable still matters.
    Unit {
        in_range: bool,
    },
}

impl Aim {
    /// Whether the player is currently aiming somewhere the cast will be refused.
    pub fn out_of_range(self) -> bool {
        matches!(
            self,
            Aim::Point { in_range: false, .. } | Aim::Unit { in_range: false }
        )
    }
}

/// Work out the preview for one armed ability.
///
/// `range` is the ability's own limit in world units, where **zero means unlimited** — the sim's
/// convention, kept rather than translated so the two cannot disagree about what zero means.
/// `reach` is how far a skillshot travels, which is a different number from how far it may be
/// aimed and is why `Vector` does not clamp to `range`.
pub fn plan(
    targeting: NetTargeting,
    caster: (f32, f32),
    cursor: (f32, f32),
    range: f32,
    reach: f32,
) -> Aim {
    let (dx, dy) = (cursor.0 - caster.0, cursor.1 - caster.1);
    let distance = (dx * dx + dy * dy).sqrt();
    let in_range = range <= 0.0 || distance <= range;

    match targeting {
        // Fires on the keypress; there is no aiming step to preview.
        NetTargeting::SelfCast | NetTargeting::None => Aim::None,
        NetTargeting::Point => Aim::Point {
            x: cursor.0,
            y: cursor.1,
            in_range,
        },
        NetTargeting::Unit => Aim::Unit { in_range },
        NetTargeting::Vector => {
            // A cursor exactly on the caster has no direction in it. Pointing the preview at the
            // caster's own feet would be a lie about where the shot goes, so draw nothing.
            if distance < 1.0 {
                return Aim::None;
            }
            let length = if reach > 0.0 { reach } else { distance };
            Aim::Vector {
                to_x: caster.0 + dx / distance * length,
                to_y: caster.1 + dy / distance * length,
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const CASTER: (f32, f32) = (1000.0, 1000.0);

    #[test]
    fn a_point_inside_the_range_is_in_range() {
        let aim = plan(NetTargeting::Point, CASTER, (1300.0, 1000.0), 600.0, 0.0);
        assert_eq!(aim, Aim::Point { x: 1300.0, y: 1000.0, in_range: true });
        assert!(!aim.out_of_range());
    }

    #[test]
    fn a_point_past_the_range_is_flagged_but_still_aimed_there() {
        // Not clamped. Clamping would quietly cast somewhere the player did not click, which is
        // worse than refusing: they would blame their aim for a spell that went somewhere else.
        let aim = plan(NetTargeting::Point, CASTER, (3000.0, 1000.0), 600.0, 0.0);
        assert_eq!(aim, Aim::Point { x: 3000.0, y: 1000.0, in_range: false });
        assert!(aim.out_of_range());
    }

    #[test]
    fn zero_range_means_unlimited() {
        // The sim's convention, and the reason this is not written as `range == f32::MAX`.
        let aim = plan(NetTargeting::Point, CASTER, (9999.0, 9999.0), 0.0, 0.0);
        assert!(!aim.out_of_range());
    }

    #[test]
    fn a_skillshot_travels_its_own_length_not_to_the_cursor() {
        // Aimed at something 100 units away, a 900-unit shot still goes 900 units. Drawing it to
        // the cursor would teach the player it stops there.
        let aim = plan(NetTargeting::Vector, CASTER, (1100.0, 1000.0), 0.0, 900.0);
        match aim {
            Aim::Vector { to_x, to_y } => {
                assert!((to_x - 1900.0).abs() < 0.01, "{to_x}");
                assert!((to_y - 1000.0).abs() < 0.01, "{to_y}");
            }
            other => panic!("expected a vector, got {other:?}"),
        }
    }

    #[test]
    fn a_skillshot_aimed_at_your_own_feet_draws_nothing() {
        assert_eq!(plan(NetTargeting::Vector, CASTER, CASTER, 0.0, 900.0), Aim::None);
    }

    #[test]
    fn a_self_cast_has_nothing_to_aim() {
        assert_eq!(plan(NetTargeting::SelfCast, CASTER, (2000.0, 2000.0), 0.0, 0.0), Aim::None);
        assert_eq!(plan(NetTargeting::None, CASTER, (2000.0, 2000.0), 0.0, 0.0), Aim::None);
    }

    #[test]
    fn a_unit_target_reports_reachability() {
        assert!(!plan(NetTargeting::Unit, CASTER, (1200.0, 1000.0), 600.0, 0.0).out_of_range());
        assert!(plan(NetTargeting::Unit, CASTER, (5000.0, 1000.0), 600.0, 0.0).out_of_range());
    }

    #[test]
    fn the_boundary_is_inclusive() {
        // Exactly at maximum range is a legal cast in the sim, and the ring is drawn at exactly
        // that radius. An exclusive check here would make the ring's own edge read as refused.
        let aim = plan(NetTargeting::Point, CASTER, (1600.0, 1000.0), 600.0, 0.0);
        assert!(!aim.out_of_range());
    }
}
