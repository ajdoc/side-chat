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
//! ## What is deliberately absent
//!
//! **Skill points.** Levelling raises every ability together rather than letting a player choose
//! which to raise. Choosing is better — it is a real decision every game in the genre has — but
//! it needs a UI, a respec rule and a per-ability rank in the wire format, and none of that is
//! worth having before anyone has played with levels at all. The one rank rule that *is* here is
//! the ultimate's, because an ultimate available at minute zero is a different game.

use crate::fixed::Fx;

/// Nobody goes past this.
pub const MAX_LEVEL: u32 = 18;

/// The level an ultimate unlocks at.
///
/// Six is the genre's convention and it earns its place: it puts the first ultimate about six
/// minutes in, which is roughly when lanes start to be worth ganking. Available from the start,
/// every hero's strongest ability would be up for the least consequential fight of the match.
pub const ULTIMATE_LEVEL: u32 = 6;

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
        // +6% ability damage per level. Abilities scale with level rather than with a chosen
        // rank, which is the simplification this module's header explains.
        ability_scale: steps * Fx::ratio(6, 100),
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
