//! The shop.
//!
//! Five items, and each one deliberately asks the engine a different question — that was the
//! selection criterion in MOBA.md, not flavour. Bootstrap is the control case (a pure stat
//! stick); the other four are the four hooks an item system needs, discovered up front instead
//! of one at a time:
//!
//! | Item | The hook |
//! | --- | --- |
//! | Ledger | subscribe to a sim event (a creep dying) |
//! | Firewall | own a castable active |
//! | Broadcast | project an aura onto other entities |
//! | Null Pointer | attach a debuff as a side effect of *someone else's* action |
//!
//! ## How an item's stats reach the hero
//!
//! Not through a lookup at read time. Buying attaches the item's bonuses as
//! [`crate::damage::Modifier`]s sourced to the item, so selling detaches exactly those and
//! `Entity::effective_stats` never needs to know the catalogue exists. It is the same mechanism
//! Bulwark's toggle uses, for the same reason.

use crate::ability::{ids, AbilityId};
use crate::damage::Modifier;
use crate::fixed::Fx;

#[derive(Clone, Copy, PartialEq, Eq, Debug, Hash)]
pub struct ItemId(pub u16);

pub mod items {
    use super::ItemId;
    pub const BOOTSTRAP: ItemId = ItemId(0);
    pub const LEDGER: ItemId = ItemId(1);
    pub const FIREWALL: ItemId = ItemId(2);
    pub const BROADCAST: ItemId = ItemId(3);
    pub const NULL_POINTER: ItemId = ItemId(4);
}

/// An effect this item projects onto everyone nearby.
#[derive(Clone, Copy, Debug)]
pub struct Aura {
    pub radius: Fx,
    pub modifier: Modifier,
    /// Whether it lands on allies or enemies. Both exist in the genre; only allies are used yet.
    pub friendly: bool,
}

#[derive(Clone, Debug)]
pub struct ItemSpec {
    pub id: ItemId,
    pub name: &'static str,
    pub cost: Fx,
    /// Attached on purchase, detached on sale.
    pub modifiers: Vec<Modifier>,
    /// A castable, occupying the item's inventory slot.
    pub active: Option<AbilityId>,
    pub aura: Option<Aura>,
    /// Extra gold whenever the holder last-hits a creep. Ledger.
    pub gold_per_creep: Fx,
    /// Attached to anyone the holder damages *with an ability*. Null Pointer.
    pub on_ability_damage: Option<Modifier>,
}

impl ItemSpec {
    fn blank(id: ItemId, name: &'static str, cost: i32) -> ItemSpec {
        ItemSpec {
            id,
            name,
            cost: Fx::from_int(cost),
            modifiers: Vec::new(),
            active: None,
            aura: None,
            gold_per_creep: Fx::ZERO,
            on_ability_damage: None,
        }
    }
}

const SEC: u32 = moba_proto::TICK_HZ;

pub fn catalogue() -> Vec<ItemSpec> {
    let mut bootstrap = ItemSpec::blank(items::BOOTSTRAP, "Bootstrap", 500);
    bootstrap.modifiers = vec![Modifier::MoveSpeedFlat(Fx::from_int(90))];

    let mut ledger = ItemSpec::blank(items::LEDGER, "Ledger", 1400);
    ledger.modifiers = vec![Modifier::AttackDamageFlat(Fx::from_int(35))];
    ledger.gold_per_creep = Fx::from_int(4);

    let mut firewall = ItemSpec::blank(items::FIREWALL, "Firewall", 1800);
    firewall.modifiers = vec![Modifier::Armour(Fx::from_int(12))];
    // The same `AbilityId` a hero would cast. One shield implementation, not two.
    firewall.active = Some(ids::FIREWALL);

    let mut broadcast = ItemSpec::blank(items::BROADCAST, "Broadcast", 2000);
    broadcast.modifiers = vec![Modifier::MaxHealthFlat(Fx::from_int(250))];
    broadcast.aura = Some(Aura {
        radius: Fx::from_int(700),
        // Priced per second in the spec, granted per tick here, so the aura does not pulse.
        modifier: Modifier::Regen {
            per_tick: Fx::ratio(6, SEC as i32),
            until_tick: 0,
        },
        friendly: true,
    });

    let mut null_pointer = ItemSpec::blank(items::NULL_POINTER, "Null Pointer", 2200);
    null_pointer.modifiers = vec![Modifier::MagicDamageFlat(Fx::from_int(40))];
    null_pointer.on_ability_damage = Some(Modifier::HealReduction {
        pct: Fx::ratio(40, 100),
        until_tick: 4 * SEC,
    });

    vec![bootstrap, ledger, firewall, broadcast, null_pointer]
}

pub struct Items {
    table: Vec<Option<ItemSpec>>,
}

impl Items {
    pub fn new() -> Items {
        let specs = catalogue();
        let max = specs.iter().map(|s| s.id.0).max().unwrap_or(0) as usize;
        let mut table = vec![None; max + 1];
        for spec in specs {
            let index = spec.id.0 as usize;
            table[index] = Some(spec);
        }
        Items { table }
    }

    pub fn get(&self, id: ItemId) -> Option<&ItemSpec> {
        self.table.get(id.0 as usize)?.as_ref()
    }

    pub fn all(&self) -> impl Iterator<Item = &ItemSpec> {
        self.table.iter().flatten()
    }
}

impl Default for Items {
    fn default() -> Items {
        Items::new()
    }
}

/// Why a purchase was refused.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum BuyRefusal {
    NoSuchItem,
    NotAHero,
    CannotAfford,
    InventoryFull,
    /// One of each. Two Broadcasts stacking their auras is a balance question nobody has
    /// answered, so the sim declines rather than guessing.
    AlreadyOwned,
}
