//! Turning the world into what one team is allowed to see.
//!
//! ## Fog of war is the anti-cheat
//!
//! Every other cheat in a MOBA is either impossible against an authoritative server (you cannot
//! move somewhere the sim did not put you) or a matter of degree (a scripted last-hit is better
//! timing, not different rules). Maphack is the exception: it needs no exploit at all, only a
//! client that draws what it was already sent.
//!
//! So the server never sends it. A snapshot is built *per team*, and an enemy outside your
//! team's vision is not in the bytes. There is nothing on the client to hack because the
//! information is not there — and it costs nothing extra, because the per-team snapshot has to
//! exist anyway.
//!
//! Vision here is a plain radius per entity. Terrain occlusion — high ground, brush, the parts
//! that make warding interesting — is phase 3, and lands as a change to [`can_see`] alone.

use crate::entity::{Entity, EntityId, EntityKind, Team};
use crate::fixed::Fx;
use crate::sim::{Event, Sim};
use moba_proto::{
    NetEntity, NetEvent, NetKind, NetMap, NetRefusal, NetSelf, NetTargeting, NetTeam, Snapshot,
};

/// How far each kind of thing can see. Wider than its attack range in every case — you should
/// see the tower that is about to shoot you before it does.
fn vision_radius(kind: EntityKind) -> Fx {
    match kind {
        EntityKind::Hero => Fx::from_int(1800),
        EntityKind::Creep => Fx::from_int(1100),
        EntityKind::Tower => Fx::from_int(1900),
        EntityKind::Base => Fx::from_int(1900),
        // Neither a burning patch of ground nor a bullet is a scout.
        EntityKind::Zone | EntityKind::Projectile => Fx::ZERO,
    }
}

fn net_team(team: Team) -> NetTeam {
    match team {
        Team::Blue => NetTeam::Blue,
        Team::Red => NetTeam::Red,
        Team::Neutral => NetTeam::Neutral,
    }
}

fn net_kind(kind: EntityKind) -> NetKind {
    match kind {
        EntityKind::Hero => NetKind::Hero,
        EntityKind::Creep => NetKind::Creep,
        EntityKind::Tower => NetKind::Tower,
        EntityKind::Base => NetKind::Base,
        EntityKind::Zone => NetKind::Zone,
        EntityKind::Projectile => NetKind::Projectile,
    }
}

impl Sim {
    /// The map, in the form the client draws from.
    ///
    /// Built once at the handshake. Everything here is public knowledge — terrain and lane
    /// routes are the same for both teams and visible on any minimap in the genre — so unlike a
    /// snapshot this needs no per-team filtering.
    pub fn net_map(&self) -> NetMap {
        NetMap {
            size: self.map.size,
            lanes: self
                .map
                .lanes
                .iter()
                .map(|lane| {
                    lane.waypoints
                        .iter()
                        .map(|p| (p.x.raw(), p.y.raw()))
                        .collect()
                })
                .collect(),
            cells_across: self.map.terrain.cells_across as u16,
            blocked: self.map.terrain.blocked_cells(),
        }
    }

    /// Every position from which `team` currently has vision.
    ///
    /// Collected once per snapshot rather than per candidate: with ~150 entities the naive form
    /// is 150×150 distance checks every snapshot, and this makes it 150×(the team's units),
    /// which at 5v5 is an order of magnitude fewer.
    fn vision_sources(&self, team: Team) -> Vec<(crate::fixed::Vec2, crate::fixed::Sq)> {
        self.entities
            .iter()
            .filter(|(_, e)| e.team == team && e.is_alive())
            .filter_map(|(_, e)| {
                let radius = vision_radius(e.kind);
                (radius > Fx::ZERO).then(|| (e.pos, radius.sq()))
            })
            .collect()
    }

    /// Whether `team` can see this entity.
    ///
    /// Your own units are always visible; a Neutral zone is visible to whoever is close enough,
    /// the same as anything else.
    fn can_see(
        &self,
        team: Team,
        entity: &Entity,
        sources: &[(crate::fixed::Vec2, crate::fixed::Sq)],
    ) -> bool {
        if entity.team == team {
            return true;
        }
        // **Stealth is enforced here, not in the renderer.** A stealthed hero is filtered out of
        // the enemy's snapshot exactly as one standing in the fog is, so there is nothing on the
        // client to reveal. Drawing them and trusting the client not to peek would make stealth
        // the one mechanic in the game a hacked client could simply switch off.
        //
        // Banished entities are hidden from *everyone*, their own team included: they are not on
        // the map, and a body drawn where they used to stand is a target players will click.
        if entity.is_stealthed(self.tick) || entity.is_banished(self.tick) {
            return false;
        }
        // Structures are always visible to both teams. That is the genre's rule — an enemy tower
        // is a landmark, and every MOBA shows them on the minimap from the first second — and it
        // also avoids the nonsense of a building fading in and out as a creep walks past it.
        if entity.kind.is_structure() {
            return true;
        }

        // Radius first, then line of sight. The radius check is two multiplies; the grid walk is
        // a loop, and most candidates fail the cheap test.
        sources.iter().any(|(pos, radius_sq)| {
            (entity.pos - *pos).len_sq() <= *radius_sq
                && self.map.terrain.line_is_clear(*pos, entity.pos)
        })
    }

    /// Build the snapshot one player receives.
    ///
    /// `own` is that player's hero, which is the only entity whose mana, gold and cooldowns are
    /// included — a teammate's exact cooldowns are not yours to know, and an enemy's certainly
    /// are not.
    pub fn snapshot(&self, team: Team, own: Option<EntityId>, events: &[Event]) -> Snapshot {
        let sources = self.vision_sources(team);

        let entities: Vec<NetEntity> = self
            .entities
            .iter()
            .filter(|(id, e)| {
                // Your own banished hero is still yours, but it is not on the map — except that
                // hiding it from its owner would blank their own camera target. Owner sees it,
                // nobody else does.
                if e.is_banished(self.tick) {
                    return Some(*id) == own;
                }
                // A corpse is not on the map. Drawing one is a target players will click, and a
                // health bar that says nothing is happening when a respawn timer is.
                if e.is_dead() {
                    return false;
                }
                self.can_see(team, e, &sources)
            })
            .map(|(id, e)| {
                let facing = match e.order {
                    crate::entity::Order::MoveTo(p) => (p - e.pos).normalized(),
                    _ => crate::fixed::Vec2::ZERO,
                };
                NetEntity {
                    id: id.to_net(),
                    kind: net_kind(e.kind),
                    team: net_team(e.team),
                    x: e.pos.x.raw(),
                    y: e.pos.y.raw(),
                    hp: e.hp.raw(),
                    max_hp: e.effective_stats(self.tick).max_hp.raw(),
                    level: if e.kind == EntityKind::Hero {
                        e.level()
                    } else {
                        0
                    },
                    facing_x: facing.x.raw(),
                    facing_y: facing.y.raw(),
                }
            })
            .collect();

        let own_view = own
            .and_then(|id| self.entities.get(id).map(|e| (id, e)))
            .map(|(id, e)| NetSelf {
                id: id.to_net(),
                mana: e.mana.raw(),
                max_mana: e.max_mana.raw(),
                gold: e.gold.raw(),
                cooldowns: e.abilities.state.iter().map(|s| s.cooldown).collect(),
                abilities: e
                    .abilities
                    .slots
                    .iter()
                    .map(|slot| slot.map_or(u16::MAX, |a| a.0))
                    .collect(),
                targeting: e
                    .abilities
                    .slots
                    .iter()
                    .map(|slot| match slot.and_then(|id| self.abilities.get(id)) {
                        Some(spec) => match spec.targeting {
                            crate::ability::Targeting::SelfCast => NetTargeting::SelfCast,
                            crate::ability::Targeting::Unit => NetTargeting::Unit,
                            crate::ability::Targeting::Point => NetTargeting::Point,
                            crate::ability::Targeting::Vector
                            | crate::ability::Targeting::Skillshot => NetTargeting::Vector,
                        },
                        None => NetTargeting::None,
                    })
                    .collect(),
                items: e.items.iter().map(|i| i.0).collect(),
                level: e.level(),
                xp_into_level: e.xp.saturating_sub(crate::level::xp_for_level(e.level())),
                xp_for_next: crate::level::xp_for_level(e.level() + 1)
                    .saturating_sub(crate::level::xp_for_level(e.level())),
                attack_range: e.effective_stats(self.tick).attack_range.raw(),
                respawn_in: e.respawn_at.map_or(0, |at| at.saturating_sub(self.tick)),
            });

        Snapshot {
            tick: self.tick,
            entities,
            own: own_view,
            events: self.net_events(team, own, events, &sources),
        }
    }

    /// Filter the tick's events the same way the entities were filtered.
    ///
    /// An event about something you cannot see is as much of a leak as the entity itself would
    /// be: "someone took 400 damage over there" is a ward. Gold events are narrower still — they
    /// go only to the player who earned them.
    fn net_events(
        &self,
        team: Team,
        own: Option<EntityId>,
        events: &[Event],
        sources: &[(crate::fixed::Vec2, crate::fixed::Sq)],
    ) -> Vec<NetEvent> {
        let visible = |id: EntityId| {
            self.entities
                .get(id)
                .map(|e| self.can_see(team, e, sources))
                // A dead entity has already left the arena by the time events are drained, so
                // its death is reported on the strength of the tick it died in, not its
                // (now absent) position. Erring toward showing a death is right: the client
                // needs it to stop drawing the corpse.
                .unwrap_or(true)
        };

        events
            .iter()
            .filter_map(|event| match *event {
                Event::Damaged {
                    source,
                    target,
                    amount,
                } if visible(target) => {
                    // The source is named only if the viewer can see it too. Otherwise "you were
                    // hit by <this id>" would be a free ward on whoever is hitting you from
                    // inside the fog.
                    let source = source
                        .filter(|id| {
                            self.entities
                                .get(*id)
                                .is_some_and(|e| self.can_see(team, e, sources))
                        })
                        .map(|id| id.to_net());
                    Some(NetEvent::Damaged {
                        source,
                        target: target.to_net(),
                        amount: amount.raw(),
                    })
                }
                Event::Died { entity, .. } if visible(entity) => Some(NetEvent::Died {
                    entity: entity.to_net(),
                }),
                Event::StructureDestroyed { entity, .. } => {
                    // Always visible. A tower falling is public knowledge in every game in the
                    // genre, and hiding it would be a worse experience, not a fairer one.
                    Some(NetEvent::StructureDestroyed {
                        entity: entity.to_net(),
                    })
                }
                Event::AbilityCast {
                    entity,
                    ability,
                    at,
                } if visible(entity) => Some(NetEvent::AbilityCast {
                    entity: entity.to_net(),
                    ability: ability.0,
                    x: at.x.raw(),
                    y: at.y.raw(),
                }),
                Event::CastInterrupted { entity, .. } if visible(entity) => {
                    Some(NetEvent::CastInterrupted {
                        entity: entity.to_net(),
                    })
                }
                Event::GoldGained { hero, amount, .. } if Some(hero) == own => {
                    Some(NetEvent::GoldGained {
                        amount: amount.raw(),
                    })
                }
                Event::Denied { by, .. } if Some(by) == own => Some(NetEvent::Denied),
                // Only ever to the person who tried. What an enemy attempted and failed to do
                // is not information they should be giving away.
                Event::CastRefused {
                    entity,
                    slot,
                    reason,
                } if Some(entity) == own => Some(NetEvent::CastRefused {
                    slot: slot as u8,
                    reason: match reason {
                        crate::ability::CastRefusal::OnCooldown => NetRefusal::OnCooldown,
                        crate::ability::CastRefusal::NotEnoughMana => NetRefusal::NotEnoughMana,
                        crate::ability::CastRefusal::Silenced => NetRefusal::Silenced,
                        crate::ability::CastRefusal::Stunned => NetRefusal::Stunned,
                        crate::ability::CastRefusal::OutOfRange => NetRefusal::OutOfRange,
                        crate::ability::CastRefusal::BadTarget => NetRefusal::BadTarget,
                        crate::ability::CastRefusal::NotLearned => NetRefusal::NotLearned,
                        crate::ability::CastRefusal::AlreadyCasting => NetRefusal::AlreadyCasting,
                        crate::ability::CastRefusal::NoSuchAbility => NetRefusal::Unknown,
                    },
                }),
                Event::MatchEnded { winner } => Some(NetEvent::MatchEnded {
                    winner: net_team(winner),
                }),
                _ => None,
            })
            .collect()
    }
}
