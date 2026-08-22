//! The battlefield: three lanes, the terrain between them, and where the structures stand.
//!
//! ## The shape
//!
//! The genre's standard square, rotated so the two bases sit on a diagonal: Blue bottom-left,
//! Red top-right. Mid runs straight between them; top and bottom hug the edges. That is not
//! decoration — it is what makes mid the short lane and the side lanes the long ones, which is
//! where most of the strategy in the genre comes from.
//!
//! ## Why creeps still walk waypoints
//!
//! MOBA.md's third finding says the collision grid must be mutable at runtime, and infers flow
//! fields from that. It is right about the grid and premature about the fields.
//!
//! A lane creep does not want the *shortest* path — if it did, every creep on the map would walk
//! mid. It wants its own lane, which is a polyline, and following a polyline is what a waypoint
//! list already is. The terrain grid exists for the things that genuinely need it: heroes
//! walking off-lane, and fog of war once it knows about walls. Flow fields become necessary the
//! day Relay's Barrier can block a lane and creeps must route around it — and that is the day to
//! write them, rather than guessing now at the shape of a problem that does not exist yet.

use crate::entity::Team;
use crate::fixed::{Fx, Vec2};

/// A position, in whole map units, made concise at the call site.
fn at(x: i32, y: i32) -> Vec2 {
    Vec2::new(Fx::from_int(x), Fx::from_int(y))
}

/// Which lane something belongs to.
///
/// Named rather than indexed, so a wave spawned into the wrong lane is a mistake you can see at
/// the call site rather than an off-by-one nobody notices until the minimap looks odd.
#[derive(Clone, Copy, PartialEq, Eq, Debug, PartialOrd, Ord, Hash)]
pub enum LaneId {
    Top,
    Mid,
    Bot,
}

impl LaneId {
    pub const ALL: [LaneId; 3] = [LaneId::Top, LaneId::Mid, LaneId::Bot];

    pub fn index(self) -> usize {
        match self {
            LaneId::Top => 0,
            LaneId::Mid => 1,
            LaneId::Bot => 2,
        }
    }
}

pub struct Lane {
    pub id: LaneId,
    /// Ordered from the Blue end to the Red end. Red creeps walk it backwards.
    pub waypoints: Vec<Vec2>,
}

/// Where a structure stands, and what it is.
pub struct StructureSite {
    pub pos: Vec2,
    /// `None` for a base, which belongs to no lane.
    pub lane: Option<LaneId>,
    /// 0 is the outermost tower, 1 the one behind it.
    pub tier: u8,
    pub is_base: bool,
}

/// Terrain, as a grid of blocked cells.
///
/// Coarse on purpose: 64 cells across a 6000-unit map is a cell of about 94 units, roughly a
/// creep's width. Finer costs memory and buys precision nothing in the game can use; coarser and
/// a hero visibly clips the corner of a wall.
pub struct Terrain {
    pub cells_across: usize,
    pub cell_size: Fx,
    /// Row-major, `cells_across * cells_across`. `true` means impassable.
    blocked: Vec<bool>,
}

impl Terrain {
    pub fn open(cells_across: usize, world_size: i32) -> Terrain {
        Terrain {
            cells_across,
            cell_size: Fx::from_int(world_size) / Fx::from_int(cells_across as i32),
            blocked: vec![false; cells_across * cells_across],
        }
    }

    fn index(&self, cx: i32, cy: i32) -> Option<usize> {
        if cx < 0 || cy < 0 || cx >= self.cells_across as i32 || cy >= self.cells_across as i32 {
            return None;
        }
        Some(cy as usize * self.cells_across + cx as usize)
    }

    /// The cell a world position falls in.
    pub fn cell_of(&self, pos: Vec2) -> (i32, i32) {
        (
            (pos.x / self.cell_size).floor_int(),
            (pos.y / self.cell_size).floor_int(),
        )
    }

    /// Whether a world position is inside a wall — or off the map, which counts as blocked.
    ///
    /// A hero ordered past the edge should stop at it; the alternative is a unit walking into
    /// coordinates the rest of the sim has no opinion about.
    pub fn is_blocked(&self, pos: Vec2) -> bool {
        let (cx, cy) = self.cell_of(pos);
        match self.index(cx, cy) {
            Some(index) => self.blocked[index],
            None => true,
        }
    }

    /// Block every cell in a world-space rectangle.
    pub fn block_rect(&mut self, min: Vec2, max: Vec2) {
        let (x0, y0) = self.cell_of(min);
        let (x1, y1) = self.cell_of(max);
        for cy in y0..=y1 {
            for cx in x0..=x1 {
                if let Some(index) = self.index(cx, cy) {
                    self.blocked[index] = true;
                }
            }
        }
    }

    /// Carve a corridor of `half_width` along a polyline, clearing whatever it crosses.
    ///
    /// Lanes are cut *after* the terrain is filled in, rather than routed around it. That order
    /// is what guarantees every lane is walkable end to end; doing it the other way — placing
    /// jungle and hoping the lanes stayed clear — is how a wave ends up stuck against a rock
    /// forty seconds into a match.
    pub fn carve(&mut self, points: &[Vec2], half_width: Fx) {
        for pair in points.windows(2) {
            let (from, to) = (pair[0], pair[1]);
            let span = to - from;
            let length = span.len();
            if length == Fx::ZERO {
                continue;
            }
            let direction = span.normalized();
            // Step by half a cell so no cell is skipped between samples.
            let step = self.cell_size / Fx::from_int(2);
            let mut travelled = Fx::ZERO;
            while travelled <= length {
                self.clear_disc(from + direction.scale(travelled), half_width);
                travelled += step;
            }
        }
    }

    fn clear_disc(&mut self, centre: Vec2, radius: Fx) {
        let (cx, cy) = self.cell_of(centre);
        let reach = (radius / self.cell_size).floor_int() + 1;
        for oy in -reach..=reach {
            for ox in -reach..=reach {
                if let Some(index) = self.index(cx + ox, cy + oy) {
                    self.blocked[index] = false;
                }
            }
        }
    }

    /// Block every cell within `radius` of a point. Relay's Barrier.
    pub fn block_disc(&mut self, centre: Vec2, radius: Fx) {
        let (cx, cy) = self.cell_of(centre);
        let reach = (radius / self.cell_size).floor_int();
        for oy in -reach..=reach {
            for ox in -reach..=reach {
                if let Some(index) = self.index(cx + ox, cy + oy) {
                    self.blocked[index] = true;
                }
            }
        }
    }

    /// Unblock every cell within `radius` of a point.
    ///
    /// Used to retire a Barrier. Note that this clears whatever was there *before* as well —
    /// harmless while barriers are only ever cast in lanes, and a real bug the day one is cast
    /// against a jungle wall. Recorded here rather than fixed, because fixing it properly means
    /// storing what each cell was, which is a cost every cell pays for a case that does not
    /// exist yet.
    pub fn clear_disc_public(&mut self, centre: Vec2, radius: Fx) {
        self.clear_disc(centre, radius);
    }

    /// Whether an unobstructed line runs between two points.
    ///
    /// A grid walk rather than a geometric intersection test: the terrain *is* cells, so
    /// stepping through them is both exact and cheap. Amanatides–Woo, which advances to whichever
    /// axis boundary comes first and therefore visits every cell the line actually crosses —
    /// unlike a naive "sample every N units", which skips a cell whenever the line clips a
    /// corner and produces vision that leaks through walls at particular angles only. That is
    /// the worst kind of bug to have in fog of war: rare, angle-dependent, and indistinguishable
    /// from a cheat when someone reports it.
    ///
    /// The endpoints themselves do not block. A unit shoved against a wall stands *in* a blocked
    /// cell, and it should still be visible from the open ground it is standing next to.
    pub fn line_is_clear(&self, from: Vec2, to: Vec2) -> bool {
        let (mut cx, mut cy) = self.cell_of(from);
        let (tx, ty) = self.cell_of(to);
        if (cx, cy) == (tx, ty) {
            return true;
        }

        let delta = to - from;
        let step_x = if delta.x >= Fx::ZERO { 1 } else { -1 };
        let step_y = if delta.y >= Fx::ZERO { 1 } else { -1 };

        // Distance along the line to the next cell boundary on each axis, and how much distance
        // one whole cell costs. A zero component means that axis never crosses, which is
        // represented as an effectively infinite cost so the other axis always wins.
        let far = Fx::from_int(30_000);
        let (mut next_x, per_x) = axis_walk(from.x, delta.x, self.cell_size, cx, step_x, far);
        let (mut next_y, per_y) = axis_walk(from.y, delta.y, self.cell_size, cy, step_y, far);

        // Bounded so a degenerate line cannot spin forever; four cells per grid width is far
        // more than any straight line can need.
        let limit = self.cells_across * 4;
        for _ in 0..limit {
            if next_x < next_y {
                cx += step_x;
                next_x += per_x;
            } else {
                cy += step_y;
                next_y += per_y;
            }

            if (cx, cy) == (tx, ty) {
                return true;
            }
            match self.index(cx, cy) {
                Some(index) if self.blocked[index] => return false,
                // Walked off the map without reaching the target: nothing to see.
                None => return false,
                _ => {}
            }
        }
        false
    }

    /// Every blocked cell, as `(cx, cy)`. For sending the map to a client, and for tests.
    pub fn blocked_cells(&self) -> Vec<(u16, u16)> {
        self.blocked
            .iter()
            .enumerate()
            .filter(|(_, blocked)| **blocked)
            .map(|(index, _)| {
                (
                    (index % self.cells_across) as u16,
                    (index / self.cells_across) as u16,
                )
            })
            .collect()
    }
}

pub struct Map {
    pub size: i32,
    pub lanes: Vec<Lane>,
    pub terrain: Terrain,
    pub blue_spawn: Vec2,
    pub red_spawn: Vec2,
    pub blue_structures: Vec<StructureSite>,
    pub red_structures: Vec<StructureSite>,
}

/// The map is this many units square.
const SIZE: i32 = 6000;

impl Map {
    /// The real map: three lanes, two towers each, a base a side.
    pub fn three_lane() -> Map {
        let blue_base = at(700, 5300);
        let red_base = at(5300, 700);

        let mid = vec![
            blue_base,
            at(1600, 4400),
            at(3000, 3000),
            at(4400, 1600),
            red_base,
        ];
        // The side lanes hug two edges, which is what makes them long and mid short.
        let top = vec![
            blue_base,
            at(600, 3000),
            at(600, 700),
            at(3000, 600),
            red_base,
        ];
        let bot = vec![
            blue_base,
            at(3000, 5400),
            at(5400, 5400),
            at(5400, 3000),
            red_base,
        ];

        let mut terrain = Terrain::open(64, SIZE);
        // Fill the interior, then cut the lanes back out of it. What is left over is the jungle.
        terrain.block_rect(at(900, 900), at(5100, 5100));
        for lane in [&top, &mid, &bot] {
            terrain.carve(lane, Fx::from_int(260));
        }
        // A wall around the outside.
        terrain.block_rect(at(0, 0), at(SIZE - 1, 120));
        terrain.block_rect(at(0, SIZE - 121), at(SIZE - 1, SIZE - 1));
        terrain.block_rect(at(0, 0), at(120, SIZE - 1));
        terrain.block_rect(at(SIZE - 121, 0), at(SIZE - 1, SIZE - 1));

        let lanes = vec![
            Lane {
                id: LaneId::Top,
                waypoints: top,
            },
            Lane {
                id: LaneId::Mid,
                waypoints: mid,
            },
            Lane {
                id: LaneId::Bot,
                waypoints: bot,
            },
        ];

        Map {
            size: SIZE,
            blue_spawn: at(900, 5100),
            red_spawn: at(5100, 900),
            blue_structures: Map::structures_for(Team::Blue, &lanes, blue_base),
            red_structures: Map::structures_for(Team::Red, &lanes, red_base),
            lanes,
            terrain,
        }
    }

    /// Two towers per lane along the owner's half of it, plus the base.
    ///
    /// Interpolated along the lane rather than hand-placed, so changing a lane's route moves its
    /// towers with it instead of leaving them stranded in the jungle.
    fn structures_for(team: Team, lanes: &[Lane], base: Vec2) -> Vec<StructureSite> {
        let mut out = vec![StructureSite {
            pos: base,
            lane: None,
            tier: 2,
            is_base: true,
        }];
        for lane in lanes {
            for (tier, fraction) in [(0u8, Fx::ratio(40, 100)), (1, Fx::ratio(18, 100))] {
                out.push(StructureSite {
                    pos: point_along(&lane.waypoints, fraction, team),
                    lane: Some(lane.id),
                    tier,
                    is_base: false,
                });
            }
        }
        out
    }

    /// The lane as this team walks it — Red's is Blue's reversed.
    pub fn lane_for(&self, team: Team, lane: LaneId) -> Vec<Vec2> {
        let points = self
            .lanes
            .iter()
            .find(|l| l.id == lane)
            .map(|l| l.waypoints.clone())
            .unwrap_or_default();
        match team {
            Team::Red => points.into_iter().rev().collect(),
            _ => points,
        }
    }

    pub fn spawn_for(&self, team: Team) -> Vec2 {
        match team {
            Team::Red => self.red_spawn,
            _ => self.blue_spawn,
        }
    }

    /// An empty field: no lanes, no structures, no terrain.
    ///
    /// For tests about one ability rather than about a match. Without it a test arena silently
    /// contains a tower with a 700-unit reach, which shoots a control subject standing well away
    /// from the thing under test — a false failure that reads exactly like the ability being too
    /// greedy. That happened once already.
    pub fn empty() -> Map {
        Map {
            size: SIZE,
            lanes: Vec::new(),
            terrain: Terrain::open(64, SIZE),
            blue_spawn: at(900, 5100),
            red_spawn: at(5100, 900),
            blue_structures: Vec::new(),
            red_structures: Vec::new(),
        }
    }

    /// One lane and one tower a side — the phase-1 map, kept for the tests written against it.
    pub fn one_lane() -> Map {
        let blue_base = at(700, 5300);
        let red_base = at(5300, 700);
        let mid = vec![
            blue_base,
            at(1600, 4400),
            at(3000, 3000),
            at(4400, 1600),
            red_base,
        ];

        let mut terrain = Terrain::open(64, SIZE);
        terrain.carve(&mid, Fx::from_int(260));

        Map {
            size: SIZE,
            blue_spawn: at(900, 5100),
            red_spawn: at(5100, 900),
            blue_structures: vec![
                StructureSite {
                    pos: blue_base,
                    lane: None,
                    tier: 2,
                    is_base: true,
                },
                StructureSite {
                    pos: point_along(&mid, Fx::ratio(30, 100), Team::Blue),
                    lane: Some(LaneId::Mid),
                    tier: 0,
                    is_base: false,
                },
            ],
            red_structures: vec![
                StructureSite {
                    pos: red_base,
                    lane: None,
                    tier: 2,
                    is_base: true,
                },
                StructureSite {
                    pos: point_along(&mid, Fx::ratio(30, 100), Team::Red),
                    lane: Some(LaneId::Mid),
                    tier: 0,
                    is_base: false,
                },
            ],
            lanes: vec![Lane {
                id: LaneId::Mid,
                waypoints: mid,
            }],
            terrain,
        }
    }
}

/// Set up one axis of the grid walk: the distance to its first cell boundary, and the distance
/// between boundaries thereafter.
fn axis_walk(origin: Fx, delta: Fx, cell: Fx, current: i32, step: i32, far: Fx) -> (Fx, Fx) {
    if delta == Fx::ZERO {
        return (far, far);
    }
    let boundary = Fx::from_int(if step > 0 { current + 1 } else { current }) * cell;
    let to_boundary = (boundary - origin) / delta;
    let per_cell = cell / delta.abs();
    (to_boundary.abs(), per_cell)
}

/// A point `fraction` of the way along a polyline, measured from `team`'s end of it.
fn point_along(points: &[Vec2], fraction: Fx, team: Team) -> Vec2 {
    let mut ordered: Vec<Vec2> = points.to_vec();
    if team == Team::Red {
        ordered.reverse();
    }
    let total: Fx = ordered
        .windows(2)
        .map(|pair| (pair[1] - pair[0]).len())
        .fold(Fx::ZERO, |a, b| a + b);

    let mut wanted = total * fraction;
    for pair in ordered.windows(2) {
        let segment = (pair[1] - pair[0]).len();
        if wanted <= segment {
            return pair[0] + (pair[1] - pair[0]).normalized().scale(wanted);
        }
        wanted -= segment;
    }
    *ordered.last().unwrap_or(&Vec2::ZERO)
}
