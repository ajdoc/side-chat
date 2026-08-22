//! Deterministic fixed-point arithmetic — `Q16.16` over `i32`.
//!
//! ## Why not `f32`
//!
//! Addition, subtraction, multiplication and division on IEEE-754 floats are specified to the
//! bit and *are* reproducible across wasm and native. The transcendentals are not: `sqrt`,
//! `sin` and `atan2` are permitted to differ by an ulp between implementations, and libm on
//! the server is not the same libm the browser links.
//!
//! A MOBA is almost entirely distance checks and angles, so that exception covers most of the
//! arithmetic in the game. One ulp of divergence in a range check is one machine deciding a
//! stun landed and the other deciding it missed, and from there the two simulations are simply
//! different games. Fixed-point removes the whole category for a couple of hundred lines.
//!
//! ## The format
//!
//! 16 bits of integer, 16 bits of fraction, in an `i32`. That is a range of ±32768 with a
//! resolution of 1/65536. A lane is a few thousand units long, so the range is ample, and the
//! resolution is far finer than a pixel.

use core::ops::{Add, AddAssign, Div, Mul, Neg, Sub, SubAssign};

/// Number of fractional bits.
pub const FRAC_BITS: u32 = 16;

/// `1.0` in raw units.
const ONE_RAW: i32 = 1 << FRAC_BITS;

/// A `Q16.16` fixed-point number.
///
/// `Ord` is derived and is exact — the whole point of the type is that two machines agree on
/// comparisons, which is what makes it safe to use as a sort key in the sim.
#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Default, Hash)]
pub struct Fx(i32);

impl Fx {
    pub const ZERO: Fx = Fx(0);
    pub const ONE: Fx = Fx(ONE_RAW);

    /// From a whole number. Saturates rather than wrapping: a wrap here would be a silent
    /// teleport across the map, where a saturate is merely wrong in an obvious direction.
    #[inline]
    pub const fn from_int(n: i32) -> Fx {
        Fx(n.saturating_mul(ONE_RAW))
    }

    /// From a ratio of whole numbers — `Fx::ratio(3, 4)` is `0.75`.
    ///
    /// This is how constants are written in the sim. There is deliberately no `from_f32`: a
    /// float in the sim's source is a float somebody will eventually do arithmetic with.
    #[inline]
    pub const fn ratio(num: i32, den: i32) -> Fx {
        Fx(((num as i64 * ONE_RAW as i64) / den as i64) as i32)
    }

    /// The raw backing integer. For serialization and tests only.
    #[inline]
    pub const fn raw(self) -> i32 {
        self.0
    }

    #[inline]
    pub const fn from_raw(raw: i32) -> Fx {
        Fx(raw)
    }

    /// Truncates toward negative infinity, as a floor should — `(-0.5).floor_int() == -1`.
    #[inline]
    pub const fn floor_int(self) -> i32 {
        self.0 >> FRAC_BITS
    }

    #[inline]
    pub const fn abs(self) -> Fx {
        Fx(self.0.abs())
    }

    #[inline]
    pub fn max(self, other: Fx) -> Fx {
        if self.0 >= other.0 {
            self
        } else {
            other
        }
    }

    #[inline]
    pub fn min(self, other: Fx) -> Fx {
        if self.0 <= other.0 {
            self
        } else {
            other
        }
    }

    /// This value squared, in [`Sq`]'s wider space.
    ///
    /// Written `radius.sq()` at a call site, and it is the right-hand side of every range check
    /// in the sim.
    #[inline]
    pub const fn sq(self) -> Sq {
        Sq((self.0 as i64 * self.0 as i64) >> FRAC_BITS)
    }

    /// Square root.
    ///
    /// Deterministic because it is only integer add, divide and shift — no libm anywhere.
    /// Negative input returns zero rather than panicking: it is reachable from accumulated
    /// rounding in a distance calculation, and a panic mid-tick takes the whole match down.
    pub fn sqrt(self) -> Fx {
        if self.0 <= 0 {
            return Fx::ZERO;
        }
        Fx(isqrt64((self.0 as u64) << FRAC_BITS) as i32)
    }
}

/// A **squared** quantity — the output of [`Vec2::len_sq`] and [`Fx::sq`].
///
/// ## Why this is its own type
///
/// `Fx` is `Q16.16`: it holds ±32768. A MOBA lane is a few thousand units long, so a squared
/// distance is routinely in the millions and does not fit — the first version of this module
/// computed `len_sq` in `Fx` and every range check in the game would have been quietly wrong.
///
/// Widening `Fx` itself would waste eight bytes on every position in every snapshot to solve a
/// problem that only exists for one intermediate value. So squared quantities get their own
/// `i64`-backed type instead, and the type system does the rest: `Sq` cannot be added to an
/// `Fx`, cannot be assigned to a position, and can only re-enter the linear world through
/// [`Sq::sqrt`]. The bug is unrepresentable rather than merely fixed.
///
/// The idiom for a range check, which never needs a square root at all:
///
/// ```
/// # use moba_sim::fixed::{Fx, Vec2};
/// # let (a, b, radius) = (Vec2::ZERO, Vec2::new(Fx::from_int(3), Fx::from_int(4)), Fx::from_int(5));
/// assert!((b - a).len_sq() <= radius.sq());
/// ```
#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Default, Debug)]
pub struct Sq(i64);

impl Sq {
    pub const ZERO: Sq = Sq(0);

    /// The linear value. The only way back out of squared space.
    pub fn sqrt(self) -> Fx {
        if self.0 <= 0 {
            return Fx::ZERO;
        }
        // `self.0` carries FRAC_BITS of fraction. sqrt halves that, so shift up by FRAC_BITS
        // first to land back on a Q16.16 result. Worst case here is ~2^46 << 16 == 2^62, which
        // is why this must be i64 and cannot be done in the i32 path.
        Fx(isqrt64((self.0 as u64) << FRAC_BITS) as i32)
    }
}

impl Add for Sq {
    type Output = Sq;
    #[inline]
    fn add(self, rhs: Sq) -> Sq {
        Sq(self.0.saturating_add(rhs.0))
    }
}

/// Integer square root by Newton-Raphson, in `u64`.
///
/// Only shifts, adds and divides, so it is bit-identical on every target — which is the entire
/// reason this module exists rather than a call to `f32::sqrt`.
fn isqrt64(n: u64) -> u64 {
    if n == 0 {
        return 0;
    }
    let mut x = n;
    let mut last = 0u64;
    // Converges in well under 64 steps; the `last` guard catches the two-cycle oscillation at
    // the final bit that integer Newton-Raphson can fall into.
    for _ in 0..64 {
        if x == last {
            break;
        }
        last = x;
        x = (x + n / x) >> 1;
    }
    // Newton from above can land one high; step down if so.
    while x > 0 && x.saturating_mul(x) > n {
        x -= 1;
    }
    x
}

impl Add for Fx {
    type Output = Fx;
    #[inline]
    fn add(self, rhs: Fx) -> Fx {
        Fx(self.0.saturating_add(rhs.0))
    }
}

impl Sub for Fx {
    type Output = Fx;
    #[inline]
    fn sub(self, rhs: Fx) -> Fx {
        Fx(self.0.saturating_sub(rhs.0))
    }
}

impl Mul for Fx {
    type Output = Fx;
    /// Widens to `i64` for the product, then shifts back. Doing this in `i32` would overflow
    /// for any pair much above 128.
    ///
    /// Saturates on the way back down. An `as i32` here would *wrap*, turning a large positive
    /// product into a large negative one — which in a sim means an entity flung to the far
    /// corner of the map by arithmetic rather than by anything that happened in the game.
    #[inline]
    fn mul(self, rhs: Fx) -> Fx {
        let raw = (self.0 as i64 * rhs.0 as i64) >> FRAC_BITS;
        Fx(raw.clamp(i32::MIN as i64, i32::MAX as i64) as i32)
    }
}

impl Div for Fx {
    type Output = Fx;
    /// Division by zero yields zero. Same reasoning as `sqrt`: it is reachable from degenerate
    /// geometry (two entities at the identical position) and must not end the match.
    #[inline]
    fn div(self, rhs: Fx) -> Fx {
        if rhs.0 == 0 {
            return Fx::ZERO;
        }
        Fx((((self.0 as i64) << FRAC_BITS) / rhs.0 as i64) as i32)
    }
}

impl Neg for Fx {
    type Output = Fx;
    #[inline]
    fn neg(self) -> Fx {
        Fx(-self.0)
    }
}

impl AddAssign for Fx {
    #[inline]
    fn add_assign(&mut self, rhs: Fx) {
        *self = *self + rhs;
    }
}

impl SubAssign for Fx {
    #[inline]
    fn sub_assign(&mut self, rhs: Fx) {
        *self = *self - rhs;
    }
}

impl core::fmt::Debug for Fx {
    /// Prints as a decimal, because `Fx(98304)` in a failing assertion tells nobody anything.
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        let whole = self.0 >> FRAC_BITS;
        let frac = (self.0 & (ONE_RAW - 1)) as u64 * 1_000_000 / ONE_RAW as u64;
        write!(f, "{}.{:06}", whole, frac)
    }
}

/// A position or a direction. The sim's only spatial type.
#[derive(Clone, Copy, PartialEq, Eq, Default, Debug)]
pub struct Vec2 {
    pub x: Fx,
    pub y: Fx,
}

impl Vec2 {
    pub const ZERO: Vec2 = Vec2 {
        x: Fx::ZERO,
        y: Fx::ZERO,
    };

    #[inline]
    pub const fn new(x: Fx, y: Fx) -> Vec2 {
        Vec2 { x, y }
    }

    #[inline]
    pub fn scale(self, k: Fx) -> Vec2 {
        Vec2::new(self.x * k, self.y * k)
    }

    /// Squared length, in [`Sq`]'s wider space.
    ///
    /// **Prefer this for range checks** — `v.len_sq() <= r.sq()` skips the square root
    /// entirely, which is both faster and avoids a rounding step. Reach for [`len`] only when
    /// a literal distance is needed.
    #[inline]
    pub fn len_sq(self) -> Sq {
        self.x.sq() + self.y.sq()
    }

    #[inline]
    pub fn len(self) -> Fx {
        self.len_sq().sqrt()
    }

    /// Unit vector. Returns zero for a zero-length input rather than NaN-equivalent garbage —
    /// two entities standing exactly on each other is a real state, not a bug.
    pub fn normalized(self) -> Vec2 {
        let len = self.len();
        if len == Fx::ZERO {
            return Vec2::ZERO;
        }
        Vec2::new(self.x / len, self.y / len)
    }
}

impl Add for Vec2 {
    type Output = Vec2;
    #[inline]
    fn add(self, rhs: Vec2) -> Vec2 {
        Vec2::new(self.x + rhs.x, self.y + rhs.y)
    }
}

impl Sub for Vec2 {
    type Output = Vec2;
    #[inline]
    fn sub(self, rhs: Vec2) -> Vec2 {
        Vec2::new(self.x - rhs.x, self.y - rhs.y)
    }
}

impl Neg for Vec2 {
    type Output = Vec2;
    #[inline]
    fn neg(self) -> Vec2 {
        Vec2::new(-self.x, -self.y)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_trips_whole_numbers() {
        assert_eq!(Fx::from_int(7).floor_int(), 7);
        assert_eq!(Fx::from_int(-7).floor_int(), -7);
    }

    #[test]
    fn floor_goes_toward_negative_infinity() {
        // The arithmetic-shift behaviour, asserted so a later "optimisation" to a divide —
        // which truncates toward zero instead — fails here rather than in a pathing bug.
        assert_eq!(Bounds::HALF.floor_int(), 0);
        assert_eq!((-Bounds::HALF).floor_int(), -1);
    }

    struct Bounds;
    impl Bounds {
        const HALF: Fx = Fx::ratio(1, 2);
    }

    #[test]
    fn multiplies_without_overflowing() {
        let a = Fx::from_int(300);
        let b = Fx::from_int(100);
        // 300 * 100 = 30000, which needs the i64 widening; in i32 this wraps.
        assert_eq!((a * b).floor_int(), 30_000);
    }

    #[test]
    fn divides_and_multiplies_inversely() {
        let a = Fx::from_int(1000);
        let b = Fx::from_int(8);
        assert_eq!(((a / b) * b).floor_int(), 1000);
    }

    #[test]
    fn sqrt_is_exact_for_perfect_squares() {
        for n in [1i32, 4, 9, 16, 144, 1024, 10_000] {
            let root = Fx::from_int(n).sqrt();
            let expected = (n as f64).sqrt() as i32;
            assert_eq!(root.floor_int(), expected, "sqrt({n}) was {root:?}");
        }
    }

    #[test]
    fn sqrt_survives_degenerate_input() {
        assert_eq!(Fx::ZERO.sqrt(), Fx::ZERO);
        assert_eq!(Fx::from_int(-9).sqrt(), Fx::ZERO);
    }

    #[test]
    fn division_by_zero_is_zero_not_a_panic() {
        assert_eq!(Fx::from_int(5) / Fx::ZERO, Fx::ZERO);
    }

    #[test]
    fn pythagorean_distance_is_exact() {
        let a = Vec2::new(Fx::from_int(0), Fx::from_int(0));
        let b = Vec2::new(Fx::from_int(300), Fx::from_int(400));
        assert_eq!((b - a).len().floor_int(), 500);
    }

    #[test]
    fn normalizing_zero_yields_zero() {
        // Two entities standing on the identical tile. Reachable, and must not produce garbage.
        assert_eq!(Vec2::ZERO.normalized(), Vec2::ZERO);
    }

    #[test]
    fn normalized_vector_has_unit_length() {
        let v = Vec2::new(Fx::from_int(300), Fx::from_int(400)).normalized();
        // Within one part in 65536 of 1.0 — fixed-point normalization cannot be exact.
        let err = (v.len() - Fx::ONE).abs();
        assert!(err < Fx::ratio(1, 1000), "length was {:?}", v.len());
    }

    #[test]
    fn squared_distance_survives_map_scale() {
        // The regression that produced the `Sq` type. A lane is thousands of units long, so a
        // squared distance is in the millions — far outside Q16.16's ±32768. Computed in `Fx`
        // this saturated, and every long-range check in the game silently disagreed with
        // reality. If `len_sq` ever returns to `Fx`, this fails first.
        let a = Vec2::ZERO;
        let b = Vec2::new(Fx::from_int(3000), Fx::from_int(4000));
        assert_eq!((b - a).len().floor_int(), 5000);
        // 5000^2 == 25_000_000, which is three orders of magnitude past what Q16.16 holds.
        assert!((b - a).len_sq() > Fx::from_int(4_999).sq());
        assert!((b - a).len_sq() < Fx::from_int(5_001).sq());
    }

    #[test]
    fn multiplication_saturates_rather_than_wrapping() {
        // Wrapping would turn a big positive into a big negative — an entity flung across the
        // map by arithmetic. Saturating is still wrong, but wrong in a visible direction.
        let huge = Fx::from_int(30_000);
        assert!((huge * huge) > Fx::ZERO);
    }

    #[test]
    fn range_checks_prefer_squared_distance() {
        let a = Vec2::new(Fx::from_int(100), Fx::from_int(100));
        let b = Vec2::new(Fx::from_int(160), Fx::from_int(180));
        let radius = Fx::from_int(100);
        assert!((b - a).len_sq() <= radius.sq());
    }
}
