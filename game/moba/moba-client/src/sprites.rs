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
