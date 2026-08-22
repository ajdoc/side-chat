//! The ground: which texture belongs where, and loading them.
//!
//! ## Why the art does not decide the shape
//!
//! The terrain is data — a grid of blocked cells authored in `moba-sim`'s `map.rs` and sent over
//! the wire — and the tiles are only its skin. Nothing here may change what is walkable, and the
//! renderer never asks a texture where a wall is. That is what keeps the art and the collision
//! from drifting: a lane moved in the sim moves on screen for free, and there is no second
//! source of truth to forget to update.
//!
//! It is also why the map is not one painted image. No painting can be guaranteed to line up
//! with a 64×64 grid that the sim is free to change, and one that is slightly out is a hero
//! walking through a cliff.

/// How many world units one repeat of a tile covers.
///
/// Not the cell size. A cell is about 94 units, and a texture repeating every 94 units reads as
/// obvious wallpaper — the eye finds the grid immediately. Five and a half cells is large enough
/// that the repeat is not the first thing you see.
///
/// It is 512 to match the textures' own resolution, which makes the common case a straight copy
/// rather than a resample: the renderer builds each pattern in a scratch canvas this many pixels
/// square, so one texture pixel is one world unit and no pattern transform is needed at all.
pub const TILE_WORLD: f32 = 512.0;

/// How many cells in from the grid edge count as the outer wall rather than jungle.
///
/// The sim walls the map with a 120-unit band, which at ~94 units a cell lands inside the second
/// ring. Cosmetic only: both are blocked either way, and getting this wrong paints rock as trees
/// rather than letting anybody walk anywhere new.
pub const BORDER_CELLS: u16 = 2;

/// How dark the unseen parts of the map are drawn.
///
/// Not black. The terrain under the fog is public knowledge — every player has seen this map a
/// hundred times, and hiding the walls would make navigating it guesswork rather than a
/// decision. What the fog hides is who is *in* it, and that is enforced by the server not
/// sending them at all.
pub const FOG_ALPHA: f32 = 0.72;

/// How far past a vision radius the fog fades out, in world units. A hard edge reads as a
/// spotlight and makes the radius look like a wall.
pub const FOG_FEATHER: f32 = 260.0;

/// The radius, in world units, of the plaza painted under each base.
pub const BASE_PLAZA: f32 = 650.0;

impl Tile {
    /// Whether this tile repeats across the ground, or is drawn once at a known size.
    ///
    /// The base plazas are a centred mandala. Tiling one would cut the circle into quarters and
    /// repeat the fragments, so a plaza is drawn as a single stamp fitted to its own radius.
    pub fn tiles(self) -> bool {
        !matches!(self, Tile::BaseBlue | Tile::BaseRed)
    }
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Tile {
    Grass,
    Brush,
    Lane,
    Jungle,
    Cliff,
    BaseBlue,
    BaseRed,
}

impl Tile {
    pub const ALL: [Tile; 7] = [
        Tile::Grass,
        Tile::Brush,
        Tile::Lane,
        Tile::Jungle,
        Tile::Cliff,
        Tile::BaseBlue,
        Tile::BaseRed,
    ];

    /// The file, relative to the terrain asset directory.
    pub fn file(self) -> &'static str {
        match self {
            Tile::Grass => "ground_grass.png",
            Tile::Brush => "brush.png",
            Tile::Lane => "lane_path.png",
            Tile::Jungle => "jungle_rock.png",
            Tile::Cliff => "cliff_edge.png",
            Tile::BaseBlue => "base_blue.png",
            Tile::BaseRed => "base_red.png",
        }
    }

    /// What to paint when the texture has not loaded, or is not there at all.
    ///
    /// These are the colours the client used before there was any art, and they stay: a missing
    /// file should cost you a nice-looking map, never a playable one. It also means the six
    /// tiles can arrive one at a time.
    pub fn fallback(self) -> &'static str {
        match self {
            Tile::Grass => "#11141b",
            // No art for this one yet, so the fallback is what you will actually see: a
            // lighter green than the ground, dense enough to read as cover. Dropping a
            // `brush.png` into the terrain directory replaces it with no code change.
            Tile::Brush => "#2f5c35",
            Tile::Lane => "#222a35",
            Tile::Jungle => "#161b23",
            Tile::Cliff => "#10131a",
            Tile::BaseBlue => "#1b2740",
            Tile::BaseRed => "#3a1c22",
        }
    }
}

/// Whether a blocked cell is part of the wall around the map rather than jungle inside it.
pub fn is_border(cx: u16, cy: u16, cells_across: u16) -> bool {
    let last = cells_across.saturating_sub(1);
    cx < BORDER_CELLS
        || cy < BORDER_CELLS
        || cx + BORDER_CELLS > last
        || cy + BORDER_CELLS > last
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn corners_and_edges_are_border() {
        assert!(is_border(0, 0, 64));
        assert!(is_border(63, 63, 64));
        assert!(is_border(1, 30, 64));
        assert!(is_border(30, 62, 64));
    }

    #[test]
    fn the_interior_is_jungle() {
        assert!(!is_border(2, 2, 64));
        assert!(!is_border(32, 32, 64));
        assert!(!is_border(61, 61, 64));
    }

    #[test]
    fn only_the_plazas_are_stamped() {
        assert!(Tile::Grass.tiles());
        assert!(Tile::Jungle.tiles());
        assert!(!Tile::BaseBlue.tiles());
        assert!(!Tile::BaseRed.tiles());
    }

    #[test]
    fn every_tile_has_a_distinct_file() {
        let mut files: Vec<&str> = Tile::ALL.iter().map(|t| t.file()).collect();
        files.sort_unstable();
        let count = files.len();
        files.dedup();
        assert_eq!(files.len(), count);
    }
}
