//! Abilities, and the small language they are written in.
//!
//! ## Why this is data and not code
//!
//! Twenty-four hero abilities plus five items' actives and passives is twenty-nine things that
//! must each be balanced, networked, replayed and explained to a player. Written as twenty-nine
//! bespoke functions they would be twenty-nine places to forget a cooldown check, a silence
//! check or a mana cost.
//!
//! So an ability is a [`AbilitySpec`]: a targeting mode, some costs, and a list of
//! (*who it hits*, *what it does*) pairs. The engine owns the verbs; a hero owns only which
//! verbs, in what order, with what numbers. Adding a hero becomes adding data — and the reason
//! that is possible at all is that MOBA.md designed all six heroes before this file existed, so
//! the verb list below is the union of what the whole roster needs rather than what the first
//! two happened to want.
//!
//! An item's active is the same [`AbilitySpec`] in a different slot. That was finding-adjacent:
//! Firewall's shield had to work identically to a hero-cast shield, and two systems would have
//! meant two shield implementations that drift.

use crate::damage::{DamageKind, Modifier};
use crate::entity::EntityId;
use crate::fixed::{Fx, Vec2};

/// How an ability is aimed.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Targeting {
    /// No aim at all. Bulwark, Vent, Flashstep.
    SelfCast,
    /// A specific entity. Taunt, Requiem, Ban.
    Unit,
    /// A place. Cinder, Feedback, Deploy Drone, Barrier.
    Point,
    /// A direction. Dashes — Shield Charge.
    Vector,
    /// A direction, resolved as a line that may pierce. Pyre, Rail.
    Skillshot,
}

/// What the player aimed at.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Target {
    None,
    Unit(EntityId),
    Point(Vec2),
}

impl Target {
    pub fn unit(self) -> Option<EntityId> {
        match self {
            Target::Unit(id) => Some(id),
            _ => None,
        }
    }

    pub fn point(self) -> Option<Vec2> {
        match self {
            Target::Point(p) => Some(p),
            _ => None,
        }
    }
}

/// Who an effect lands on.
///
/// Separating selection from effect is what stops the verb list from combinatorially exploding:
/// "damage in a circle" and "stun in a circle" are one selection and two effects, not two
/// bespoke abilities.
#[derive(Clone, Copy, Debug)]
pub enum Selection {
    Caster,
    /// The unit that was targeted. Nothing if the ability was not unit-targeted.
    TargetUnit,
    /// Everyone hostile to the caster within `radius` of the aim point.
    EnemiesInCircle {
        radius: Fx,
    },
    /// Everyone friendly to the caster within `radius` of the *caster*. Jukebox's auras.
    AlliesAroundCaster {
        radius: Fx,
    },
    /// Everyone hostile along a line from the caster toward the aim point.
    EnemiesInLine {
        length: Fx,
        width: Fx,
    },
}

/// A verb.
///
/// The list is the union extracted in MOBA.md, and it is deliberately closed: an ability that
/// needs a verb not on this list is a signal to look at the spec again, because the roster was
/// supposed to have already asked for everything.
#[derive(Clone, Copy, Debug)]
pub enum Effect {
    Damage {
        amount: Fx,
        kind: DamageKind,
    },
    /// Damage scaled by the fraction of the caster's health that is *missing*. Last Stand.
    DamageByMissingHealth {
        per_missing: Fx,
        kind: DamageKind,
    },
    Heal {
        amount: Fx,
    },
    Apply(Modifier),
    /// Attach `stacks` of Heat, refreshing the duration. Kindle.
    AddHeat {
        stacks: u32,
        duration: u32,
    },
    /// Remove all Heat from the selection and deal `per_stack` magic damage for each. Pyre.
    ConsumeHeat {
        per_stack: Fx,
    },
    /// Move the caster along the aim direction over time.
    Dash {
        speed: Fx,
        max_distance: Fx,
        stop_on_hero: bool,
    },
    /// Move the caster instantly, clamped to `max_range`. Flashstep, Backspace.
    Blink {
        max_range: Fx,
    },
    /// Shove the selection directly away from the caster.
    Knockback {
        distance: Fx,
    },
    /// Override the selection's orders, forcing them to attack the caster. Taunt.
    ForceAttackCaster {
        duration: u32,
    },
    /// Leave a persistent area effect at the aim point. Cinder.
    SpawnZone {
        radius: Fx,
        duration: u32,
        damage_per_tick: Fx,
        kind: DamageKind,
    },

    // ── Verbs the second four heroes asked for ──────────────────────────────────────────
    //
    // Every one of these was written down in MOBA.md before `ability.rs` existed, which is why
    // adding them is adding match arms rather than rethinking the engine. That was the bet of
    // designing the whole roster first, and this is where it either pays or does not.
    /// Heal over time; if the target *dies* while it runs, the caster is healed for the
    /// remainder instead. Jukebox's Requiem — a buff with a death trigger.
    HealOverTime {
        per_tick: Fx,
        duration: u32,
    },
    /// Blink to where the caster stood `ticks_ago` ticks ago, healing a fraction of the damage
    /// taken since. Ghostuser's Backspace, and the reason position history is sim state.
    Rewind {
        ticks_ago: u32,
        heal_fraction: Fx,
    },
    /// Take the target off the map entirely for a while. Ghostuser's Ban.
    Banish {
        duration: u32,
    },
    /// Strip modifiers of one category. Overclock's Vent.
    Dispel {
        slows: bool,
    },
    /// Damage scaled by the caster's attack-chain stacks. Overclock's Rail.
    DamageByStacks {
        per_stack: Fx,
        kind: DamageKind,
    },
    /// Summon autonomous units at the aim point. Relay's drones.
    Summon {
        count: u32,
        duration: u32,
        tether_share: Option<Fx>,
    },
    /// Block a patch of ground for a while. Relay's Barrier — the runtime terrain mutation
    /// MOBA.md's third finding warned about.
    Barrier {
        radius: Fx,
        duration: u32,
    },
}

/// A named ability. The `u16` is an index into the spec table, so a hero's four slots are four
/// integers on the wire.
#[derive(Clone, Copy, PartialEq, Eq, Debug, Hash)]
pub struct AbilityId(pub u16);

impl AbilityId {
    /// Stand-in for an entity id inside a catalogue entry.
    ///
    /// A spec is built once at startup and cannot know who will cast it, but two modifiers —
    /// Ghostuser's mark and Relay's tether — name an entity. They are written with this
    /// placeholder and the caster is substituted when the effect actually fires. Ugly, and the
    /// alternative was making every modifier's fields lazy, which is uglier.
    pub const PLACEHOLDER_ENTITY: crate::entity::EntityId = crate::entity::EntityId::PLACEHOLDER;
}

/// Everything the engine needs to run one ability.
#[derive(Clone, Debug)]
pub struct AbilitySpec {
    pub id: AbilityId,
    pub name: &'static str,
    pub targeting: Targeting,
    /// Ticks between casts.
    pub cooldown: u32,
    pub mana_cost: Fx,
    /// Ticks spent casting before the effects fire. A stun during this cancels it.
    pub cast_time: u32,
    /// Ticks spent channelling *after* the cast lands, during which the caster cannot act and
    /// an interrupt ends it early. Distinct from `cast_time` because a channel's effects fire
    /// repeatedly and a cast's fire once.
    pub channel_time: u32,
    /// How far the aim point may be from the caster. Zero means unlimited.
    pub range: Fx,
    /// Health charged per second while a toggle is on, instead of mana. Overclock's Meltdown is
    /// the only thing that uses it, and it is a field rather than a flag because "the drawback
    /// is that it costs health" is a number, not a mode.
    pub health_upkeep: Fx,
    /// A toggle turns on and off rather than firing; its effects are applied while on and its
    /// `mana_cost` is charged per second instead of per cast.
    pub toggle: bool,
    pub effects: Vec<(Selection, Effect)>,
}

impl AbilitySpec {
    fn blank(id: u16, name: &'static str, targeting: Targeting) -> AbilitySpec {
        AbilitySpec {
            id: AbilityId(id),
            name,
            targeting,
            cooldown: 0,
            mana_cost: Fx::ZERO,
            cast_time: 0,
            channel_time: 0,
            range: Fx::ZERO,
            toggle: false,
            health_upkeep: Fx::ZERO,
            effects: Vec::new(),
        }
    }
}

/// A cast in progress.
#[derive(Clone, Copy, Debug)]
pub struct Cast {
    pub ability: AbilityId,
    pub slot: usize,
    pub target: Target,
    /// Ticks left before the effects fire.
    pub ticks_remaining: u32,
    /// Ticks of channel left after that. Zero for an ordinary cast.
    pub channel_remaining: u32,
    /// Whether the effects have already fired once — a channel fires on entry and then per tick.
    pub fired: bool,
}

/// A displacement in progress.
#[derive(Clone, Copy, Debug)]
pub struct Dash {
    pub direction: Vec2,
    pub speed: Fx,
    pub remaining: Fx,
    pub stop_on_hero: bool,
}

/// A persistent area effect. Lives on an [`crate::entity::EntityKind::Zone`] entity.
#[derive(Clone, Copy, Debug)]
pub struct Zone {
    pub radius: Fx,
    pub expires_tick: u32,
    pub damage_per_tick: Fx,
    pub kind: DamageKind,
    /// Who gets the kill credit once gold exists. Held as an id, so it correctly resolves to
    /// nothing if the caster has since died — see `EntityId`'s generation.
    pub owner: Option<EntityId>,
    pub owner_team: crate::entity::Team,
}

/// One ability slot's live state.
#[derive(Clone, Copy, Debug, Default)]
pub struct AbilityState {
    pub cooldown: u32,
    pub toggled_on: bool,
    /// 0 means unlearned and uncastable. Raised by spending a skill point.
    pub rank: u8,
}

/// A hero's castables: four ability slots, then six item slots.
///
/// One flat array rather than two, because an item's active and a hero's Q are the same thing
/// to everything downstream — the cooldown tick, the silence check, the mana check and the cast
/// state machine are all indifferent to which half a slot came from. Splitting them would have
/// meant two of each of those, kept in step by hand.
///
/// The layout is fixed and public: `0..4` is the hero, `4..10` is the inventory.
#[derive(Clone, Copy, Debug)]
pub struct AbilitySlots {
    pub slots: [Option<AbilityId>; SLOT_COUNT],
    pub state: [AbilityState; SLOT_COUNT],
}

/// Where the hero's own four abilities end and the inventory begins.
pub const HERO_SLOTS: usize = 4;
pub const ITEM_SLOTS: usize = 6;
pub const SLOT_COUNT: usize = HERO_SLOTS + ITEM_SLOTS;

impl Default for AbilitySlots {
    fn default() -> AbilitySlots {
        AbilitySlots {
            slots: [None; SLOT_COUNT],
            state: [AbilityState::default(); SLOT_COUNT],
        }
    }
}

impl AbilitySlots {
    pub fn new(ids: [AbilityId; HERO_SLOTS]) -> AbilitySlots {
        let mut out = AbilitySlots::default();
        for (slot, id) in ids.iter().enumerate() {
            out.slots[slot] = Some(*id);
        }
        out
    }

    pub fn id(&self, slot: usize) -> Option<AbilityId> {
        self.slots.get(slot).copied().flatten()
    }

    /// Points earned but not yet spent.
    ///
    /// Derived rather than stored: a level grants a point, so unspent is levels minus ranks. Two
    /// fields that must agree is two fields that will eventually disagree — the same reasoning
    /// that keeps `level` derived from experience.
    pub fn unspent_points(&self, level: u32) -> u32 {
        let spent: u32 = self.state[..HERO_SLOTS].iter().map(|s| s.rank as u32).sum();
        level.saturating_sub(spent)
    }

    /// The first free inventory slot, if there is one.
    pub fn free_item_slot(&self) -> Option<usize> {
        (HERO_SLOTS..SLOT_COUNT).find(|slot| self.slots[*slot].is_none())
    }

    /// Whether this hero has the ability in any slot. How a passive is detected.
    pub fn has(&self, id: AbilityId) -> bool {
        self.slots.contains(&Some(id))
    }
}

/// Why a cast was refused.
///
/// Returned rather than silently swallowed: the client needs to say "on cooldown" or "silenced"
/// rather than eating the click, and the server needs to distinguish a mistimed order (normal)
/// from an impossible one (a bug, or a client out of sync).
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum CastRefusal {
    NoSuchAbility,
    /// Rank zero — no point has been spent on it yet.
    NotLearned,
    OnCooldown,
    NotEnoughMana,
    Silenced,
    Stunned,
    AlreadyCasting,
    BadTarget,
    OutOfRange,
}

// ── The catalogue ───────────────────────────────────────────────────────────────────────────

pub mod ids {
    use super::AbilityId;

    // Ironclad
    pub const SHIELD_CHARGE: AbilityId = AbilityId(0);
    pub const BULWARK: AbilityId = AbilityId(1);
    pub const TAUNT: AbilityId = AbilityId(2);
    pub const LAST_STAND: AbilityId = AbilityId(3);

    // Emberwitch
    pub const CINDER: AbilityId = AbilityId(4);
    pub const KINDLE: AbilityId = AbilityId(5);
    pub const FLASHSTEP: AbilityId = AbilityId(6);
    pub const PYRE: AbilityId = AbilityId(7);

    // Jukebox
    pub const DROP_THE_BEAT: AbilityId = AbilityId(8);
    pub const REQUIEM: AbilityId = AbilityId(9);
    pub const FEEDBACK: AbilityId = AbilityId(10);
    pub const ENCORE: AbilityId = AbilityId(11);

    // Ghostuser
    pub const IDLE: AbilityId = AbilityId(12);
    pub const READ_RECEIPT: AbilityId = AbilityId(13);
    pub const BACKSPACE: AbilityId = AbilityId(14);
    pub const BAN: AbilityId = AbilityId(15);

    // Overclock
    pub const SPOOL_UP: AbilityId = AbilityId(16);
    pub const VENT: AbilityId = AbilityId(17);
    pub const RAIL: AbilityId = AbilityId(18);
    pub const MELTDOWN: AbilityId = AbilityId(19);

    // Relay
    pub const DEPLOY_DRONE: AbilityId = AbilityId(20);
    pub const LINK: AbilityId = AbilityId(21);
    pub const BARRIER: AbilityId = AbilityId(22);
    pub const SWARM: AbilityId = AbilityId(23);

    // Items
    pub const FIREWALL: AbilityId = AbilityId(100);
}

const SEC: u32 = moba_proto::TICK_HZ;

/// Every ability the sim knows, indexed by [`AbilityId`].
///
/// A function rather than a `static` because the specs hold `Vec`s and building them once per
/// `Sim` is cheaper than fighting for a const-friendly representation of a list that is read a
/// few times per tick.
pub fn catalogue() -> Vec<AbilitySpec> {
    let mut out = Vec::new();

    // ── Ironclad ────────────────────────────────────────────────────────────────────────
    let mut shield_charge = AbilitySpec::blank(0, "Shield Charge", Targeting::Vector);
    shield_charge.cooldown = 14 * SEC;
    shield_charge.mana_cost = Fx::from_int(90);
    shield_charge.range = Fx::from_int(900);
    shield_charge.effects = vec![(
        Selection::Caster,
        Effect::Dash {
            speed: Fx::from_int(1100),
            max_distance: Fx::from_int(900),
            // The dash terminates on the first *hero* it reaches; creeps are ridden through, so
            // a charge cannot be body-blocked by a lane wave.
            stop_on_hero: true,
        },
    )];
    out.push(shield_charge);

    let mut bulwark = AbilitySpec::blank(1, "Bulwark", Targeting::SelfCast);
    bulwark.toggle = true;
    // Charged per second while on, not per cast. See `Sim::charge_toggles`.
    bulwark.mana_cost = Fx::from_int(12);
    bulwark.effects = vec![
        (
            Selection::Caster,
            Effect::Apply(Modifier::Armour(Fx::from_int(40))),
        ),
        (
            Selection::Caster,
            Effect::Apply(Modifier::MoveSpeedPct {
                pct: Fx::ratio(-25, 100),
                until_tick: 0,
            }),
        ),
        (
            Selection::Caster,
            Effect::Apply(Modifier::ProjectileBlock { until_tick: 0 }),
        ),
    ];
    out.push(bulwark);

    let mut taunt = AbilitySpec::blank(2, "Taunt", Targeting::Unit);
    taunt.cooldown = 16 * SEC;
    taunt.mana_cost = Fx::from_int(75);
    taunt.range = Fx::from_int(500);
    taunt.effects = vec![(
        Selection::TargetUnit,
        Effect::ForceAttackCaster {
            duration: SEC + SEC / 2,
        },
    )];
    out.push(taunt);

    let mut last_stand = AbilitySpec::blank(3, "Last Stand", Targeting::SelfCast);
    last_stand.cooldown = 70 * SEC;
    last_stand.mana_cost = Fx::from_int(150);
    last_stand.channel_time = 2 * SEC;
    last_stand.effects = vec![
        (
            Selection::EnemiesInCircle {
                radius: Fx::from_int(600),
            },
            // The whole identity of the ultimate: it pays out on how close Ironclad came to
            // dying while channelling it, which is why the channel is worth surviving.
            Effect::DamageByMissingHealth {
                per_missing: Fx::ratio(60, 100),
                kind: DamageKind::Magical,
            },
        ),
        (
            Selection::EnemiesInCircle {
                radius: Fx::from_int(600),
            },
            Effect::Knockback {
                distance: Fx::from_int(350),
            },
        ),
    ];
    out.push(last_stand);

    // ── Emberwitch ──────────────────────────────────────────────────────────────────────
    let mut cinder = AbilitySpec::blank(4, "Cinder", Targeting::Point);
    cinder.cooldown = 8 * SEC;
    cinder.mana_cost = Fx::from_int(70);
    cinder.range = Fx::from_int(800);
    cinder.cast_time = SEC / 5;
    cinder.effects = vec![
        (
            Selection::EnemiesInCircle {
                radius: Fx::from_int(250),
            },
            Effect::Damage {
                amount: Fx::from_int(80),
                kind: DamageKind::Magical,
            },
        ),
        (
            Selection::EnemiesInCircle {
                radius: Fx::from_int(250),
            },
            Effect::AddHeat {
                stacks: 1,
                duration: 6 * SEC,
            },
        ),
        (
            Selection::Caster,
            Effect::SpawnZone {
                radius: Fx::from_int(250),
                duration: 4 * SEC,
                // Per tick, so ~18/second at 30Hz.
                damage_per_tick: Fx::ratio(6, 10),
                kind: DamageKind::Magical,
            },
        ),
    ];
    out.push(cinder);

    // Kindle is a passive: it has no cast path at all and exists in the catalogue so the slot
    // has a name, a tooltip and an id on the wire. Its mechanics live in the attack phase.
    let mut kindle = AbilitySpec::blank(5, "Kindle", Targeting::SelfCast);
    kindle.cooldown = u32::MAX;
    out.push(kindle);

    let mut flashstep = AbilitySpec::blank(6, "Flashstep", Targeting::Point);
    flashstep.cooldown = 12 * SEC;
    flashstep.mana_cost = Fx::from_int(50);
    flashstep.range = Fx::from_int(450);
    flashstep.effects = vec![
        (
            Selection::Caster,
            Effect::Blink {
                max_range: Fx::from_int(450),
            },
        ),
        (
            Selection::Caster,
            Effect::Apply(Modifier::FreeCast { until_tick: 0 }),
        ),
    ];
    out.push(flashstep);

    let mut pyre = AbilitySpec::blank(7, "Pyre", Targeting::Skillshot);
    pyre.cooldown = 60 * SEC;
    pyre.mana_cost = Fx::from_int(180);
    pyre.cast_time = SEC / 3;
    pyre.range = Fx::from_int(1100);
    pyre.effects = vec![
        (
            Selection::EnemiesInLine {
                length: Fx::from_int(1100),
                width: Fx::from_int(180),
            },
            Effect::Damage {
                amount: Fx::from_int(200),
                kind: DamageKind::Magical,
            },
        ),
        (
            Selection::EnemiesInLine {
                length: Fx::from_int(1100),
                width: Fx::from_int(180),
            },
            Effect::ConsumeHeat {
                per_stack: Fx::from_int(90),
            },
        ),
    ];
    out.push(pyre);

    // ── Jukebox ─────────────────────────────────────────────────────────────────────────
    //
    // The app's music bot as a hero. Everything he does is a broadcast: he buffs whoever is
    // *standing near him*, not whoever he clicked, which is why three of his four use
    // `AlliesAroundCaster` rather than a unit target.
    let mut drop_the_beat = AbilitySpec::blank(8, "Drop the Beat", Targeting::SelfCast);
    drop_the_beat.cooldown = 16 * SEC;
    drop_the_beat.mana_cost = Fx::from_int(80);
    drop_the_beat.effects = vec![
        (
            Selection::AlliesAroundCaster {
                radius: Fx::from_int(900),
            },
            Effect::Apply(Modifier::MoveSpeedPct {
                pct: Fx::ratio(15, 100),
                until_tick: 6 * SEC,
            }),
        ),
        (
            Selection::AlliesAroundCaster {
                radius: Fx::from_int(900),
            },
            Effect::Apply(Modifier::AttackSpeedPct {
                pct: Fx::ratio(15, 100),
                until_tick: 6 * SEC,
            }),
        ),
    ];
    out.push(drop_the_beat);

    let mut requiem = AbilitySpec::blank(9, "Requiem", Targeting::Unit);
    requiem.cooldown = 12 * SEC;
    requiem.mana_cost = Fx::from_int(90);
    requiem.range = Fx::from_int(700);
    requiem.effects = vec![(
        Selection::TargetUnit,
        Effect::HealOverTime {
            per_tick: Fx::ratio(9, 2),
            duration: 4 * SEC,
        },
    )];
    out.push(requiem);

    let mut feedback = AbilitySpec::blank(10, "Feedback", Targeting::Point);
    feedback.cooldown = 18 * SEC;
    feedback.mana_cost = Fx::from_int(100);
    feedback.range = Fx::from_int(750);
    feedback.cast_time = SEC / 4;
    feedback.effects = vec![(
        Selection::EnemiesInCircle {
            radius: Fx::from_int(320),
        },
        Effect::Apply(Modifier::Silence {
            until_tick: (SEC * 15) / 10,
        }),
    )];
    out.push(feedback);

    let mut encore = AbilitySpec::blank(11, "Encore", Targeting::SelfCast);
    encore.cooldown = 80 * SEC;
    encore.mana_cost = Fx::from_int(200);
    encore.channel_time = 3 * SEC;
    // A channel fires its effects every tick it runs, so the heal is priced per tick and the
    // slow immunity is granted in short refreshed slices rather than once for the duration —
    // that way an interrupt ends both immediately, which is what makes the channel counterable.
    encore.effects = vec![
        (
            Selection::AlliesAroundCaster {
                radius: Fx::from_int(4000),
            },
            Effect::Heal {
                amount: Fx::ratio(5, 2),
            },
        ),
        (
            Selection::AlliesAroundCaster {
                radius: Fx::from_int(4000),
            },
            Effect::Apply(Modifier::SlowImmune { until_tick: 3 }),
        ),
    ];
    out.push(encore);

    // ── Ghostuser ───────────────────────────────────────────────────────────────────────
    let mut idle = AbilitySpec::blank(12, "Idle", Targeting::SelfCast);
    idle.cooldown = 20 * SEC;
    idle.mana_cost = Fx::from_int(60);
    // The 1.5s delay before stealth engages is handled as a cast time, so *any* action during
    // it interrupts — which is exactly the ability's rule, for free.
    idle.cast_time = (SEC * 15) / 10;
    idle.effects = vec![
        (
            Selection::Caster,
            Effect::Apply(Modifier::Stealth {
                until_tick: 12 * SEC,
            }),
        ),
        (
            Selection::Caster,
            Effect::Apply(Modifier::MoveSpeedPct {
                pct: Fx::ratio(20, 100),
                until_tick: 12 * SEC,
            }),
        ),
    ];
    out.push(idle);

    let mut read_receipt = AbilitySpec::blank(13, "Read Receipt", Targeting::Unit);
    read_receipt.cooldown = 14 * SEC;
    read_receipt.mana_cost = Fx::from_int(70);
    read_receipt.range = Fx::from_int(800);
    read_receipt.effects = vec![(
        Selection::TargetUnit,
        // `by` is filled in with the real caster when the effect fires; the catalogue cannot
        // know who will cast it.
        Effect::Apply(Modifier::Marked {
            by: AbilityId::PLACEHOLDER_ENTITY,
            amp: Fx::ratio(20, 100),
            until_tick: 8 * SEC,
        }),
    )];
    out.push(read_receipt);

    let mut backspace = AbilitySpec::blank(14, "Backspace", Targeting::SelfCast);
    backspace.cooldown = 22 * SEC;
    backspace.mana_cost = Fx::from_int(85);
    backspace.effects = vec![(
        Selection::Caster,
        Effect::Rewind {
            ticks_ago: 3 * SEC,
            heal_fraction: Fx::ratio(30, 100),
        },
    )];
    out.push(backspace);

    let mut ban = AbilitySpec::blank(15, "Ban", Targeting::Unit);
    ban.cooldown = 75 * SEC;
    ban.mana_cost = Fx::from_int(180);
    ban.range = Fx::from_int(600);
    ban.cast_time = (SEC * 12) / 10;
    ban.effects = vec![(
        Selection::TargetUnit,
        Effect::Banish {
            duration: (SEC * 25) / 10,
        },
    )];
    out.push(ban);

    // ── Overclock ───────────────────────────────────────────────────────────────────────
    //
    // Spool Up is a passive with no cast path, like Kindle: it lives in the attack phase and
    // exists here so the slot has a name and an id.
    let mut spool_up = AbilitySpec::blank(16, "Spool Up", Targeting::SelfCast);
    spool_up.cooldown = u32::MAX;
    out.push(spool_up);

    let mut vent = AbilitySpec::blank(17, "Vent", Targeting::SelfCast);
    vent.cooldown = 10 * SEC;
    vent.mana_cost = Fx::from_int(50);
    vent.effects = vec![
        (Selection::Caster, Effect::Dispel { slows: true }),
        (
            Selection::Caster,
            Effect::Apply(Modifier::Immune {
                until_tick: SEC / 2,
            }),
        ),
    ];
    out.push(vent);

    let mut rail = AbilitySpec::blank(18, "Rail", Targeting::Skillshot);
    rail.cooldown = 9 * SEC;
    rail.mana_cost = Fx::from_int(80);
    rail.range = Fx::from_int(1000);
    rail.effects = vec![(
        Selection::EnemiesInLine {
            length: Fx::from_int(1000),
            width: Fx::from_int(140),
        },
        Effect::DamageByStacks {
            per_stack: Fx::from_int(22),
            kind: DamageKind::Physical,
        },
    )];
    out.push(rail);

    let mut meltdown = AbilitySpec::blank(19, "Meltdown", Targeting::SelfCast);
    meltdown.toggle = true;
    // Priced in *health* per second rather than mana. Charged by the toggle phase, which reads
    // `health_upkeep` instead of `mana_cost` when it is set.
    meltdown.health_upkeep = Fx::from_int(28);
    meltdown.effects = vec![(
        Selection::Caster,
        Effect::Apply(Modifier::AttackSpeedPct {
            pct: Fx::ratio(90, 100),
            until_tick: 0,
        }),
    )];
    out.push(meltdown);

    // ── Relay ───────────────────────────────────────────────────────────────────────────
    let mut deploy_drone = AbilitySpec::blank(20, "Deploy Drone", Targeting::Point);
    deploy_drone.cooldown = 11 * SEC;
    deploy_drone.mana_cost = Fx::from_int(75);
    deploy_drone.range = Fx::from_int(700);
    deploy_drone.effects = vec![(
        Selection::Caster,
        Effect::Summon {
            count: 1,
            duration: 20 * SEC,
            tether_share: None,
        },
    )];
    out.push(deploy_drone);

    let mut link = AbilitySpec::blank(21, "Link", Targeting::Unit);
    link.cooldown = 15 * SEC;
    link.mana_cost = Fx::from_int(60);
    link.range = Fx::from_int(900);
    link.effects = vec![(
        Selection::TargetUnit,
        Effect::Apply(Modifier::Redirect {
            to: AbilityId::PLACEHOLDER_ENTITY,
            share: Fx::ratio(30, 100),
            until_tick: 10 * SEC,
        }),
    )];
    out.push(link);

    let mut barrier = AbilitySpec::blank(22, "Barrier", Targeting::Point);
    barrier.cooldown = 20 * SEC;
    barrier.mana_cost = Fx::from_int(90);
    barrier.range = Fx::from_int(650);
    barrier.effects = vec![(
        Selection::Caster,
        Effect::Barrier {
            radius: Fx::from_int(220),
            duration: 3 * SEC,
        },
    )];
    out.push(barrier);

    let mut swarm = AbilitySpec::blank(23, "Swarm", Targeting::Point);
    swarm.cooldown = 90 * SEC;
    swarm.mana_cost = Fx::from_int(190);
    swarm.range = Fx::from_int(700);
    swarm.effects = vec![(
        Selection::Caster,
        Effect::Summon {
            count: 4,
            duration: 20 * SEC,
            tether_share: Some(Fx::ratio(30, 100)),
        },
    )];
    out.push(swarm);

    // ── Items ───────────────────────────────────────────────────────────────────────────
    //
    // Deliberately the same type in the same table. Firewall's shield must behave exactly as a
    // hero-cast shield does, and two systems would be two shield implementations that drift.
    let mut firewall = AbilitySpec::blank(100, "Firewall", Targeting::SelfCast);
    firewall.cooldown = 30 * SEC;
    firewall.effects = vec![(
        Selection::Caster,
        Effect::Apply(Modifier::Shield {
            remaining: Fx::from_int(200),
            until_tick: 3 * SEC,
        }),
    )];
    out.push(firewall);

    out
}

/// The two heroes phase 1 implements.
///
/// A hero is a statline plus four ability ids — the whole of what distinguishes one from
/// another, which is the payoff for the data-driven ability engine. The remaining four from
/// MOBA.md's roster are four more entries here once their verbs exist.
pub mod heroes {
    use super::{ids, AbilityId};
    use crate::entity::Stats;
    use crate::fixed::Fx;

    pub struct Hero {
        pub name: &'static str,
        pub stats: Stats,
        pub mana: Fx,
        pub abilities: [AbilityId; 4],
    }

    pub fn ironclad() -> Hero {
        Hero {
            name: "Ironclad",
            stats: Stats::melee_hero(),
            mana: Fx::from_int(420),
            abilities: [
                ids::SHIELD_CHARGE,
                ids::BULWARK,
                ids::TAUNT,
                ids::LAST_STAND,
            ],
        }
    }

    pub fn emberwitch() -> Hero {
        Hero {
            name: "Emberwitch",
            stats: Stats::ranged_hero(),
            mana: Fx::from_int(640),
            abilities: [ids::CINDER, ids::KINDLE, ids::FLASHSTEP, ids::PYRE],
        }
    }

    pub fn jukebox() -> Hero {
        Hero {
            name: "Jukebox",
            stats: Stats::ranged_hero(),
            mana: Fx::from_int(720),
            abilities: [ids::DROP_THE_BEAT, ids::REQUIEM, ids::FEEDBACK, ids::ENCORE],
        }
    }

    pub fn ghostuser() -> Hero {
        Hero {
            name: "Ghostuser",
            stats: Stats::melee_hero(),
            mana: Fx::from_int(480),
            abilities: [ids::IDLE, ids::READ_RECEIPT, ids::BACKSPACE, ids::BAN],
        }
    }

    pub fn overclock() -> Hero {
        Hero {
            name: "Overclock",
            stats: Stats::ranged_hero(),
            mana: Fx::from_int(400),
            abilities: [ids::SPOOL_UP, ids::VENT, ids::RAIL, ids::MELTDOWN],
        }
    }

    pub fn relay() -> Hero {
        Hero {
            name: "Relay",
            stats: Stats::ranged_hero(),
            mana: Fx::from_int(600),
            abilities: [ids::DEPLOY_DRONE, ids::LINK, ids::BARRIER, ids::SWARM],
        }
    }

    /// The whole roster, in pick order.
    pub fn all() -> [fn() -> Hero; 6] {
        [ironclad, emberwitch, jukebox, ghostuser, overclock, relay]
    }
}

/// The catalogue as a lookup table, indexed by id.
///
/// Ids are sparse — items start at 100 — so this is a `Vec<Option<_>>` rather than a map. A
/// `HashMap` here would be an iteration-order hazard in the one crate that cannot afford one,
/// even though this particular table is only ever looked up by key.
pub struct Abilities {
    table: Vec<Option<AbilitySpec>>,
}

impl Abilities {
    pub fn new() -> Abilities {
        let specs = catalogue();
        let max = specs.iter().map(|s| s.id.0).max().unwrap_or(0) as usize;
        let mut table = vec![None; max + 1];
        for spec in specs {
            let index = spec.id.0 as usize;
            table[index] = Some(spec);
        }
        Abilities { table }
    }

    pub fn get(&self, id: AbilityId) -> Option<&AbilitySpec> {
        self.table.get(id.0 as usize)?.as_ref()
    }
}

impl Default for Abilities {
    fn default() -> Abilities {
        Abilities::new()
    }
}
