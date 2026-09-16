//! Which picture each unit is drawn with, and how it sits on the ground.
//!
//! Rules only — no browser types — so the mapping is testable on the host. The loading and the
//! drawing live in [`crate::tileset`] and [`crate::web`].
//!
//! ## Rotated, not animated
//!
//! A creep is about forty pixels across at play zoom. Eight directions of walk and attack frames
//! per creep is thirty-two sprites nobody can see the difference between, so a creep is one
//! overhead drawing turned to point where it is going — `facing` is already on the wire. The
//! structures never turn at all, which is what lets them be drawn in three-quarter view with
//! real height, the way the genre has always drawn them.

use moba_proto::{NetKind, NetTeam};

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Sprite {
    TowerBlue1,
    TowerBlue2,
    CoreBlue,
    TowerRed1,
    TowerRed2,
    CoreRed,
    CreepMeleeBlue,
    CreepRangedBlue,
    CreepMeleeRed,
    CreepRangedRed,
    Ironclad,
    Emberwitch,
    Jukebox,
    Ghostuser,
    Overclock,
    Relay,
}

/// The heroes, in the order the sim's catalogue lists them.
///
/// That order is what arrives in `variant`, and it is already load-bearing on the PHP side too —
/// see `Heroes::ROSTER`. Three copies of one ordering is not something to be pleased about; what
/// keeps it honest is that a wrong entry here is a hero wearing the wrong coat, while the ids
/// that actually decide anything are checked by the server at seat time.
pub const HERO_ORDER: [Sprite; 6] = [
    Sprite::Ironclad,
    Sprite::Emberwitch,
    Sprite::Jukebox,
    Sprite::Ghostuser,
    Sprite::Overclock,
    Sprite::Relay,
];

impl Sprite {
    pub const ALL: [Sprite; 16] = [
        Sprite::TowerBlue1,
        Sprite::TowerBlue2,
        Sprite::CoreBlue,
        Sprite::TowerRed1,
        Sprite::TowerRed2,
        Sprite::CoreRed,
        Sprite::CreepMeleeBlue,
        Sprite::CreepRangedBlue,
        Sprite::CreepMeleeRed,
        Sprite::CreepRangedRed,
        Sprite::Ironclad,
        Sprite::Emberwitch,
        Sprite::Jukebox,
        Sprite::Ghostuser,
        Sprite::Overclock,
        Sprite::Relay,
    ];

    pub fn file(self) -> &'static str {
        match self {
            Sprite::TowerBlue1 => "tower_blue_1.png",
            Sprite::TowerBlue2 => "tower_blue_2.png",
            Sprite::CoreBlue => "core_blue.png",
            Sprite::TowerRed1 => "tower_red_1.png",
            Sprite::TowerRed2 => "tower_red_2.png",
            Sprite::CoreRed => "core_red.png",
            Sprite::CreepMeleeBlue => "creep_melee_blue.png",
            Sprite::CreepRangedBlue => "creep_ranged_blue.png",
            Sprite::CreepMeleeRed => "creep_melee_red.png",
            Sprite::CreepRangedRed => "creep_ranged_red.png",
            Sprite::Ironclad => "ironclad.png",
            Sprite::Emberwitch => "emberwitch.png",
            Sprite::Jukebox => "jukebox.png",
            Sprite::Ghostuser => "ghostuser.png",
            Sprite::Overclock => "overclock.png",
            Sprite::Relay => "relay.png",
        }
    }

    /// Whether the drawing turns to face the way the unit is moving.
    ///
    /// True only for the overhead art. Rotating a three-quarter drawing tips the building over.
    pub fn rotates(self) -> bool {
        !matches!(
            self,
            Sprite::TowerBlue1
                | Sprite::TowerBlue2
                | Sprite::CoreBlue
                | Sprite::TowerRed1
                | Sprite::TowerRed2
                | Sprite::CoreRed
        )
    }

    /// Whether this is one of the heroes, which are drawn larger than a creep and are the only
    /// units shared between the two teams.
    pub fn is_hero(self) -> bool {
        HERO_ORDER.contains(&self)
    }

    /// Where the entity's position sits inside the picture, as a fraction of its height.
    ///
    /// A creep is centred on itself. A tower is not: it is drawn from slightly in front, so the
    /// point it *occupies* is the base of it, and centring one would leave it standing a hundred
    /// units behind the thing that shoots you.
    pub fn ground_anchor(self) -> f32 {
        if self.rotates() {
            0.5
        } else {
            0.82
        }
    }

    /// The file for one pose of a directional sprite: `ironclad_downright.png`.
    ///
    /// Not every character has these. One that does not falls back to its single overhead
    /// drawing and the rotation that goes with it, which is what lets the direction sheets
    /// arrive one hero at a time instead of all ten at once.
    pub fn pose_file(self, pose: Pose) -> String {
        let stem = self.file().trim_end_matches(".png");
        format!("{stem}_{}.png", pose.suffix())
    }

    /// Which directory the file is served from. Heroes are kept apart from the units because
    /// they are the batch most likely to be redrawn, and a hero is not a creep.
    pub fn directory(self) -> &'static str {
        if self.is_hero() {
            "heroes"
        } else {
            "units"
        }
    }

    /// How wide to draw it, as a multiple of the placeholder disc's radius.
    ///
    /// Tied to the disc rather than to world units so the art inherits the sizes that were
    /// already tuned by playing — the discs are what everyone has been reading the game from.
    pub fn scale(self) -> f32 {
        if !self.rotates() {
            3.4
        } else if self.is_hero() {
            // Heroes read a little smaller than their art relative to a creep's, because the
            // disc radius they inherit was already tuned to make a hero stand out.
            2.3
        } else {
            2.6
        }
    }
}

/// One of the five drawings a directional character needs.
///
/// Named for how the pose *looks on screen*, not for the world heading it serves. That is the
/// way round an artist can work to — "facing the bottom-right of the image" is a thing you can
/// draw, "facing world +x" is not — and it keeps the projection's business inside
/// [`pose_for_bucket`] rather than spread across ten filenames.
#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Debug)]
pub enum Pose {
    Down,
    DownRight,
    Right,
    UpRight,
    Up,
}

impl Pose {
    pub const ALL: [Pose; 5] = [
        Pose::Down,
        Pose::DownRight,
        Pose::Right,
        Pose::UpRight,
        Pose::Up,
    ];

    pub fn suffix(self) -> &'static str {
        match self {
            Pose::Down => "down",
            Pose::DownRight => "downright",
            Pose::Right => "right",
            Pose::UpRight => "upright",
            Pose::Up => "up",
        }
    }
}

/// Which drawing to use for one of the eight world headings, and whether to flip it.
///
/// ## Five drawings, eight directions
///
/// Mirroring a sprite left-to-right turns a heading into its reflection about the screen's
/// vertical axis, so the four left-facing headings are the four right-facing ones flipped, and
/// straight-up and straight-down are their own mirrors. Five drawings therefore cover eight
/// directions, which is the difference between fifty pieces of art for this game and eighty.
///
/// The cost is that mirroring flips the character: a shield on the left arm is on the right arm
/// in half the directions. At the size a hero is drawn nobody sees it, and it is what every
/// two-dimensional isometric game did for twenty years.
///
/// The buckets are world headings — see [`crate::projection::direction_bucket`] — and the map
/// from those to screen appearances is the projection's doing: world +x is *down and to the
/// right* on an isometric screen, not to the right.
pub fn pose_for_bucket(bucket: usize) -> (Pose, bool) {
    match bucket % 8 {
        0 => (Pose::DownRight, false), // world +x
        1 => (Pose::Down, false),      // world +x +y
        2 => (Pose::DownRight, true),  // world +y
        3 => (Pose::Right, true),      // world -x +y
        4 => (Pose::UpRight, true),    // world -x
        5 => (Pose::Up, false),        // world -x -y
        6 => (Pose::UpRight, false),   // world -y
        _ => (Pose::Right, false),     // world +x -y
    }
}

/// Where a directional drawing's feet are, as a fraction of its height.
///
/// The very bottom, unlike the overhead art, and for a reason the isometric view makes
/// unavoidable: a posed character is a figure *standing* on the ground, so the point it occupies
/// is under its feet. Centring one would sink it to the waist, and — worse — would put it at the
/// wrong depth, since the draw order sorts on where a unit stands.
///
/// Exactly one rather than nearly one: the sheet is cut to the character's own outline, so the
/// bottom edge of the image *is* the sole of the boot. Any less and the figure hovers.
pub const POSED_ANCHOR: f32 = 1.0;

/// How **tall** a directional drawing is, as a multiple of the placeholder disc's radius.
///
/// Height and not width, which is not a preference. The five poses of one character are not the
/// same width — Ironclad seen from the side is 306 pixels across and 425 from the front — so
/// sizing them by width draws the same hero at five different heights and he grows and shrinks
/// as he turns. Sizing by height makes every pose the same size as every other, which is the
/// only property that matters here.
pub const POSED_HEIGHT: f32 = 3.1;

/// How big a unit is drawn, in screen pixels before zoom.
///
/// One definition, used by the renderer to draw and by the camera to decide what a click landed
/// on. Two copies of this is how you get a game where the thing you can see and the thing you
/// can click are different sizes — which feels like bad input rather than like a bug, so nobody
/// reports it precisely enough to find.
pub fn draw_radius(kind: NetKind) -> f32 {
    match kind {
        NetKind::Hero => 26.0,
        NetKind::Creep => 14.0,
        NetKind::Tower => 34.0,
        NetKind::Base => 52.0,
        NetKind::Zone => 0.0,
        NetKind::Projectile => 5.0,
    }
}

/// The sprite for one entity, or `None` for anything still drawn as a disc.
pub fn for_entity(kind: NetKind, team: NetTeam, variant: u8) -> Option<Sprite> {
    let blue = team == NetTeam::Blue;
    match kind {
        NetKind::Tower => Some(match (blue, variant) {
            (true, 0) => Sprite::TowerBlue1,
            (true, _) => Sprite::TowerBlue2,
            (false, 0) => Sprite::TowerRed1,
            (false, _) => Sprite::TowerRed2,
        }),
        NetKind::Base => Some(if blue { Sprite::CoreBlue } else { Sprite::CoreRed }),
        // Heroes are one picture apiece rather than one per team: you tell a hero apart by its
        // silhouette, and the team by the ring the renderer draws under it. Twelve drawings to
        // say what a coloured circle already says would be twelve drawings to keep in step.
        NetKind::Hero => HERO_ORDER.get(variant as usize).copied(),
        NetKind::Creep => Some(match (blue, variant) {
            (true, 0) => Sprite::CreepMeleeBlue,
            (true, _) => Sprite::CreepRangedBlue,
            (false, 0) => Sprite::CreepMeleeRed,
            (false, _) => Sprite::CreepRangedRed,
        }),
        _ => None,
    }
}

/// The angle, in radians, to turn an overhead sprite so it points along `(x, y)`.
///
/// The art is drawn facing up the image, which is *negative* Y on a canvas — hence the offset.
/// A zero facing keeps the last angle's job to the caller; here it simply points up.
pub fn facing_angle(x: f32, y: f32) -> f32 {
    if x == 0.0 && y == 0.0 {
        return 0.0;
    }
    y.atan2(x) + std::f32::consts::FRAC_PI_2
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_structure_and_creep_has_a_sprite() {
        for team in [NetTeam::Blue, NetTeam::Red] {
            for kind in [NetKind::Tower, NetKind::Base, NetKind::Creep] {
                for variant in [0, 1] {
                    assert!(for_entity(kind, team, variant).is_some(), "{kind:?} {variant}");
                }
            }
        }
    }

    #[test]
    fn projectiles_and_zones_stay_discs() {
        for kind in [NetKind::Projectile, NetKind::Zone] {
            assert!(for_entity(kind, NetTeam::Blue, 0).is_none());
        }
    }

    #[test]
    fn every_hero_in_the_roster_has_a_picture() {
        for variant in 0..6 {
            assert!(for_entity(NetKind::Hero, NetTeam::Blue, variant).is_some(), "{variant}");
        }
    }

    #[test]
    fn a_hero_index_off_the_end_falls_back_to_a_disc() {
        // A client older than the roster must draw a circle, not the wrong hero.
        assert!(for_entity(NetKind::Hero, NetTeam::Blue, 6).is_none());
        assert!(for_entity(NetKind::Hero, NetTeam::Blue, 200).is_none());
    }

    #[test]
    fn both_teams_share_one_drawing_of_a_hero() {
        for variant in 0..6 {
            assert_eq!(
                for_entity(NetKind::Hero, NetTeam::Blue, variant),
                for_entity(NetKind::Hero, NetTeam::Red, variant),
            );
        }
    }

    #[test]
    fn the_teams_never_share_a_picture() {
        for kind in [NetKind::Tower, NetKind::Base, NetKind::Creep] {
            for variant in [0, 1] {
                assert_ne!(
                    for_entity(kind, NetTeam::Blue, variant),
                    for_entity(kind, NetTeam::Red, variant),
                );
            }
        }
    }

    #[test]
    fn every_sprite_has_a_distinct_file() {
        let mut files: Vec<&str> = Sprite::ALL.iter().map(|s| s.file()).collect();
        files.sort_unstable();
        let count = files.len();
        files.dedup();
        assert_eq!(files.len(), count);
    }

    #[test]
    fn the_five_poses_cover_all_eight_headings() {
        let mut covered = std::collections::BTreeSet::new();
        for bucket in 0..8 {
            covered.insert(pose_for_bucket(bucket));
        }
        assert_eq!(covered.len(), 8, "two headings resolved to the same drawing and flip");
    }

    #[test]
    fn opposite_headings_are_mirrors_of_each_other() {
        // World +x and world +y are reflections across the screen's vertical axis, so they are
        // the same drawing flipped. If this stops holding, the mirroring scheme is wrong and
        // half the directions will face the wrong way.
        assert_eq!(pose_for_bucket(0), (Pose::DownRight, false));
        assert_eq!(pose_for_bucket(2), (Pose::DownRight, true));
        assert_eq!(pose_for_bucket(7), (Pose::Right, false));
        assert_eq!(pose_for_bucket(3), (Pose::Right, true));
    }

    #[test]
    fn straight_up_and_straight_down_are_never_flipped() {
        // They are their own mirrors, so flipping them would be a wasted transform that also
        // swaps the character's gear for no gain.
        assert_eq!(pose_for_bucket(1), (Pose::Down, false));
        assert_eq!(pose_for_bucket(5), (Pose::Up, false));
    }

    #[test]
    fn a_pose_file_hangs_off_the_sprite_name() {
        assert_eq!(Sprite::Ironclad.pose_file(Pose::DownRight), "ironclad_downright.png");
        assert_eq!(Sprite::Ironclad.pose_file(Pose::Up), "ironclad_up.png");
    }

    #[test]
    fn every_pose_has_a_distinct_suffix() {
        let mut names: Vec<&str> = Pose::ALL.iter().map(|p| p.suffix()).collect();
        names.sort_unstable();
        let count = names.len();
        names.dedup();
        assert_eq!(names.len(), count);
    }

    #[test]
    fn only_the_overhead_art_turns() {
        assert!(Sprite::CreepMeleeBlue.rotates());
        assert!(Sprite::Ironclad.rotates());
        assert!(!Sprite::TowerBlue1.rotates());
        assert!(!Sprite::CoreRed.rotates());
    }

    #[test]
    fn facing_up_the_screen_is_no_rotation() {
        // Negative Y is up on a canvas, and the art is drawn pointing up.
        assert!(facing_angle(0.0, -1.0).abs() < 1e-5);
        assert!((facing_angle(1.0, 0.0) - std::f32::consts::FRAC_PI_2).abs() < 1e-5);
        assert_eq!(facing_angle(0.0, 0.0), 0.0);
    }
}
