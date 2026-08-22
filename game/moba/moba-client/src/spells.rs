//! What each ability looks like when it fires.
//!
//! ## Why this exists
//!
//! Every ability drew the same expanding ring, which meant a player could tell that *something*
//! had been cast and nothing else — not which ability, not where it landed, not whether it hit.
//! Twenty-four abilities that all look identical is, from the player's side, indistinguishable
//! from one ability.
//!
//! ## Why the table is here and not sent from the server
//!
//! This is the second place ability ids are written down, and that is a real cost. The
//! alternative is worse in a specific way: the sim's catalogue holds *rules* — radii, costs,
//! cooldowns, targeting — and none of that is what a renderer needs. Shipping it would mean
//! sending an ability's mana cost so the client can pick a colour.
//!
//! What keeps the duplication honest is that everything here is **cosmetic**. A wrong entry is a
//! wrong colour, never a wrong effect, and an id with no entry falls back to a plain ring rather
//! than failing. The names are the exception, and they are the reason this is worth having at
//! all: seeing "Shield Charge" on screen is most of how a player learns what a key does.

/// The shape an ability's effect is drawn as.
#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Debug)]
pub enum Shape {
    /// An expanding ring on the caster. Self-buffs and toggles.
    Ring,
    /// A filled circle at the aim point. Ground-targeted areas.
    Blast,
    /// A line from the caster outward. Skillshots and dashes.
    Beam,
    /// A tightening ring — something arriving rather than spreading. Summons, blinks.
    Implode,
    /// A slow pulse on the caster, for channels.
    Pulse,
}

pub struct Look {
    pub name: &'static str,
    pub colour: &'static str,
    pub shape: Shape,
    /// Roughly how far the effect reaches, in world units. Cosmetic only.
    pub reach: f32,
}

/// The look of an ability, or a plain fallback for one this client has never heard of.
///
/// A client one release behind the server should draw *something* for a new hero's abilities
/// rather than nothing at all.
pub fn look(ability: u16) -> Look {
    match ability {
        // ── Ironclad ───────────────────────────────────────────────────────────────────
        0 => Look {
            name: "Shield Charge",
            colour: "#7fb3ff",
            shape: Shape::Beam,
            reach: 900.0,
        },
        1 => Look {
            name: "Bulwark",
            colour: "#9db4d0",
            shape: Shape::Ring,
            reach: 140.0,
        },
        2 => Look {
            name: "Taunt",
            colour: "#ffa657",
            shape: Shape::Ring,
            reach: 500.0,
        },
        3 => Look {
            name: "Last Stand",
            colour: "#ff7b72",
            shape: Shape::Pulse,
            reach: 600.0,
        },

        // ── Emberwitch ─────────────────────────────────────────────────────────────────
        4 => Look {
            name: "Cinder",
            colour: "#ff9f43",
            shape: Shape::Blast,
            reach: 250.0,
        },
        5 => Look {
            name: "Kindle",
            colour: "#ffb86c",
            shape: Shape::Ring,
            reach: 100.0,
        },
        6 => Look {
            name: "Flashstep",
            colour: "#d2a8ff",
            shape: Shape::Implode,
            reach: 450.0,
        },
        7 => Look {
            name: "Pyre",
            colour: "#ff6b6b",
            shape: Shape::Beam,
            reach: 1100.0,
        },

        // ── Jukebox ────────────────────────────────────────────────────────────────────
        8 => Look {
            name: "Drop the Beat",
            colour: "#7ee787",
            shape: Shape::Ring,
            reach: 900.0,
        },
        9 => Look {
            name: "Requiem",
            colour: "#56d364",
            shape: Shape::Implode,
            reach: 700.0,
        },
        10 => Look {
            name: "Feedback",
            colour: "#a5d6ff",
            shape: Shape::Blast,
            reach: 320.0,
        },
        11 => Look {
            name: "Encore",
            colour: "#7ee787",
            shape: Shape::Pulse,
            reach: 900.0,
        },

        // ── Ghostuser ──────────────────────────────────────────────────────────────────
        12 => Look {
            name: "Idle",
            colour: "#8b949e",
            shape: Shape::Implode,
            reach: 160.0,
        },
        13 => Look {
            name: "Read Receipt",
            colour: "#d2a8ff",
            shape: Shape::Beam,
            reach: 800.0,
        },
        14 => Look {
            name: "Backspace",
            colour: "#a5a5ff",
            shape: Shape::Implode,
            reach: 300.0,
        },
        15 => Look {
            name: "Ban",
            colour: "#ff7b72",
            shape: Shape::Blast,
            reach: 200.0,
        },

        // ── Overclock ──────────────────────────────────────────────────────────────────
        16 => Look {
            name: "Spool Up",
            colour: "#ffd866",
            shape: Shape::Ring,
            reach: 120.0,
        },
        17 => Look {
            name: "Vent",
            colour: "#79c0ff",
            shape: Shape::Ring,
            reach: 200.0,
        },
        18 => Look {
            name: "Rail",
            colour: "#ffd866",
            shape: Shape::Beam,
            reach: 1000.0,
        },
        19 => Look {
            name: "Meltdown",
            colour: "#ff7b72",
            shape: Shape::Pulse,
            reach: 200.0,
        },

        // ── Relay ──────────────────────────────────────────────────────────────────────
        20 => Look {
            name: "Deploy Drone",
            colour: "#79c0ff",
            shape: Shape::Implode,
            reach: 200.0,
        },
        21 => Look {
            name: "Link",
            colour: "#56d364",
            shape: Shape::Beam,
            reach: 900.0,
        },
        22 => Look {
            name: "Barrier",
            colour: "#8b949e",
            shape: Shape::Blast,
            reach: 220.0,
        },
        23 => Look {
            name: "Swarm",
            colour: "#79c0ff",
            shape: Shape::Implode,
            reach: 300.0,
        },

        // ── Items ──────────────────────────────────────────────────────────────────────
        100 => Look {
            name: "Firewall",
            colour: "#a5d6ff",
            shape: Shape::Ring,
            reach: 140.0,
        },

        _ => Look {
            name: "",
            colour: "#c9d1d9",
            shape: Shape::Ring,
            reach: 150.0,
        },
    }
}

/// The label to show on an ability button.
///
/// The first word, which is what fits: "Shield Charge" is unreadable in a 46-pixel square and
/// "Shield" is enough to connect the button to the effect that just fired.
pub fn short_name(ability: u16) -> &'static str {
    let full = look(ability).name;
    match full.split(' ').next() {
        Some(first) if !first.is_empty() => first,
        _ => "",
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_ability_in_the_roster_has_a_look() {
        // Twenty-four hero abilities plus one item active. An id with no entry is not a crash —
        // it falls back — but it is a hero whose abilities all look the same, which is the thing
        // this module exists to prevent.
        for id in 0..24u16 {
            assert!(!look(id).name.is_empty(), "ability {id} has no look");
        }
        assert!(!look(100).name.is_empty(), "the item active has no look");
    }

    #[test]
    fn an_unknown_ability_falls_back_rather_than_vanishing() {
        // A client one release behind the server should draw something for a new hero.
        let unknown = look(9999);
        assert!(unknown.name.is_empty());
        assert!(
            unknown.reach > 0.0,
            "an unknown ability would draw nothing at all"
        );
    }

    #[test]
    fn short_names_fit_on_a_button() {
        assert_eq!(short_name(0), "Shield");
        assert_eq!(short_name(4), "Cinder");
        for id in 0..24u16 {
            assert!(
                short_name(id).len() <= 12,
                "ability {id} has a long short name"
            );
        }
    }

    #[test]
    fn the_four_abilities_of_a_hero_do_not_all_look_the_same() {
        // The failure this whole module addresses: twenty-four abilities that all drew the same
        // ring were, from the player's side, one ability.
        for hero in 0..6u16 {
            let shapes: Vec<Shape> = (0..4).map(|slot| look(hero * 4 + slot).shape).collect();
            let distinct = shapes
                .iter()
                .collect::<std::collections::BTreeSet<_>>()
                .len();
            assert!(
                distinct >= 2,
                "hero {hero}'s four abilities use {distinct} shape(s) between them"
            );
        }
    }
}
