//! Experience and levels.
//!
//! ## Why this matters more than it looks
//!
//! Without levels, gold is the only axis a match moves along, and most of the genre's shape
//! disappears with it: there is no reason to contest a lane beyond farm, a kill confers no
//! lasting advantage, and every Ironclad is the same Ironclad from the first second to the last.
//! Snowballing — the thing that makes an early fight worth having — is almost entirely a level
//! phenomenon.
//!
//! ## Shared, not awarded
//!
//! Experience for a dying unit is split between every enemy hero standing near it, rather than
//! given to whoever landed the hit. That one rule is what makes the genre's laning phase work:
//! gold rewards the last hit and demands precision, experience rewards *presence* and only
//! demands that you stay. A support who never last-hits still levels, and a hero who leaves the
//! lane falls behind whether or not they were farming elsewhere.
//!
//! ## Skill points
//!
//! A level grants a point, and a point raises one ability. That choice is the decision the genre
//! is built on — whether to max your wave clear or your escape first is most of what separates
//! two players on the same hero — and it was deliberately absent for one release while levels
//! themselves were being proven. See [`ranks`].

use crate::fixed::Fx;

/// Nobody goes past this.
pub const MAX_LEVEL: u32 = 18;

/// The level an ultimate unlocks at.
///
/// Six is the genre's convention and it earns its place: it puts the first ultimate about six
/// minutes in, which is roughly when lanes start to be worth ganking. Available from the start,
/// every hero's strongest ability would be up for the least consequential fight of the match.
pub const ULTIMATE_LEVEL: u32 = 6;

/// Ranks, and what a skill point may be spent on.
pub mod ranks {
    use crate::fixed::Fx;

    /// How high a basic ability goes.
    pub const MAX_BASIC: u8 = 4;
    /// How high an ultimate goes. Fewer ranks, each worth much more.
    pub const MAX_ULTIMATE: u8 = 3;

    /// The level each rank of the ultimate needs.
    ///
    /// Six, eleven, sixteen — the genre's spacing, and it paces a match: the ultimate arrives,
    /// then gets better twice, at roughly the points where fights change scale.
    pub const ULTIMATE_LEVELS: [u32; MAX_ULTIMATE as usize] = [6, 11, 16];

    /// The highest rank this ability may be raised to at this level.
    pub fn cap(slot: usize, level: u32) -> u8 {
        if slot == super::ULTIMATE_SLOT {
            return ULTIMATE_LEVELS
                .iter()
                .filter(|need| level >= **need)
                .count() as u8;
        }
        // A basic ability may be raised every other level. Without a cap a level-four hero could
        // pour every point into one ability and have it maxed before leaving lane, which is a
        // strictly better choice than any other and therefore not a choice.
        ((level as u8).div_ceil(2)).min(MAX_BASIC)
    }

    /// What a rank multiplies an ability's effect by.
    ///
    /// Rank one is the printed number and each rank adds 35% of it, so a maxed basic is roughly
    /// double. Zero for an unlearned ability, which cannot be cast at all.
    pub fn scale(rank: u8) -> Fx {
        if rank == 0 {
            return Fx::ZERO;
        }
        Fx::ONE + Fx::ratio(35, 100) * Fx::from_int(rank as i32 - 1)
    }
}

/// The ability slot the ultimate lives in.
pub const ULTIMATE_SLOT: usize = 3;

/// How far from a dying unit a hero has to be to share its experience.
///
/// Generous — wider than a creep's aggro range — because the alternative is a player standing
/// exactly on their wave to avoid missing out, which is both fiddly and dangerous in a way the
/// game never intended to ask for.
pub const XP_RADIUS: i32 = 1100;

/// Total experience needed to *reach* a given level.
///
/// Superlinear, so an early lead compounds a little and a late one compounds less. A flat cost
/// per level would make the last level as cheap as the first, which is when the game is already
/// decided.
pub fn xp_for_level(level: u32) -> u32 {
    if level <= 1 {
        return 0;
    }
    // 100, 240, 420, 640, … — each level costs 100 + 40·(n−1) more than being one below.
    (2..=level).map(|n| 100 + 40 * (n - 2)).sum()
}

/// The level a given amount of experience buys.
pub fn level_for_xp(xp: u32) -> u32 {
    let mut level = 1;
    while level < MAX_LEVEL && xp >= xp_for_level(level + 1) {
        level += 1;
    }
    level
}

/// What killing this is worth in experience.
///
/// A hero is worth several creeps but not a wave: making a kill worth a whole level is how a
/// single early gank ends a lane, which is the failure mode of every game that has tried it.
pub fn xp_bounty(kind: crate::entity::EntityKind, victim_level: u32) -> u32 {
    use crate::entity::EntityKind;
    match kind {
        EntityKind::Creep => 32,
        EntityKind::Hero => 60 + 20 * victim_level,
        EntityKind::Tower => 150,
        _ => 0,
    }
}

/// The stat bonus a hero has accumulated by a given level.
///
/// Multiplicative on health and flat on damage, which is the genre's usual shape: it keeps a
/// level-18 carry killable while making its damage matter, rather than producing two heroes who
/// cannot hurt each other.
pub struct LevelBonus {
    pub max_hp: Fx,
    pub attack_damage: Fx,
    pub armour: Fx,
    pub max_mana: Fx,
    /// Fractional scaling applied to ability damage — see `Sim::ability_power_bonus`.
    pub ability_scale: Fx,
}

pub fn bonus_for(level: u32) -> LevelBonus {
    let steps = Fx::from_int(level.saturating_sub(1) as i32);
    LevelBonus {
        max_hp: steps * Fx::from_int(85),
        attack_damage: steps * Fx::from_int(4),
        armour: steps * Fx::ratio(8, 10),
        max_mana: steps * Fx::from_int(45),
        // A small drift on top of the rank scaling, so a hero who has maxed an ability still
        // gains a little from levelling past that point.
        ability_scale: steps * Fx::ratio(2, 100),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn levels_cost_more_as_they_go_up() {
        let cost = |n: u32| xp_for_level(n) - xp_for_level(n - 1);
        assert!(cost(3) > cost(2), "level three cost no more than level two");
        assert!(cost(18) > cost(10));
    }

    #[test]
    fn experience_converts_back_to_the_level_that_bought_it() {
        for level in 1..=MAX_LEVEL {
            assert_eq!(
                level_for_xp(xp_for_level(level)),
                level,
                "level {level} round-trip"
            );
            if level > 1 {
                assert_eq!(
                    level_for_xp(xp_for_level(level) - 1),
                    level - 1,
                    "one point short of {level} should still be {}",
                    level - 1
                );
            }
        }
    }

    #[test]
    fn nobody_levels_past_the_cap() {
        assert_eq!(level_for_xp(u32::MAX / 2), MAX_LEVEL);
    }

    #[test]
    fn a_hero_is_worth_more_than_a_creep_but_less_than_a_wave() {
        use crate::entity::EntityKind;
        let creep = xp_bounty(EntityKind::Creep, 0);
        let hero = xp_bounty(EntityKind::Hero, 5);
        assert!(hero > creep * 3, "a hero kill is barely worth farming");
        // Six creeps is one wave. A kill worth a whole wave ends a lane outright.
        assert!(hero < creep * 12, "a single kill is worth two waves");
    }
}
