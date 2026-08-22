# MOBA

A 5v5, three-lane MOBA that lives in an [app channel](APP_CHANNELS.md). Rust for the
simulation, compiled once and run in two places — the authoritative server and, as wasm, the
browser client. PHP owns everything around a match and nothing inside one.

**This document is the spec, and it exists before the code on purpose.** The engine's job is
to be the *union* of what six heroes and five items need. Build it around the first two heroes
and hero five's rewind blink or hero six's deployable wall arrives as a rewrite of the most
load-bearing crate in the project. So the roster is designed in full here, the primitives are
extracted from it in [What the engine must support](#what-the-engine-must-support), and only
then does `ability.rs` get written.

Balance numbers below are placeholders. They are here to make each ability *concrete enough to
extract a primitive from*, not because anyone believes 1.2 seconds is the right stun duration.

## Why this isn't a `GameHandler`

Side Spaces already have a game framework — `GameService`, one `space_games` row per channel,
handlers that answer four questions. Among Us, a pet battle and the ARPG crawl all live there
and a MOBA cannot, for a reason `AmongUsGame.php` states in its own docblock: *"there is no
per-game tick to lean on."* That framework is action → state → broadcast, driven by a player
doing something. A MOBA advances thirty times a second whether or not anybody has moved.

So the MOBA takes the other door: an `app` channel, one `AppRegistry` row, and its own
transport straight to a Rust process. It borrows the app-channel furniture that *is* a fit —
`app_comments` and `app_activity` hang off a **match**, giving post-game threads for free — and
none of the furniture that isn't.

## The roster

Six heroes, covering the five positions a 5v5 needs plus one flex. Two from each of the three
visual families the app can support, deliberately mixed: a mixed roster stresses the ability
engine across a wider primitive space than six variations on a knight would, and that pressure
is the entire point of designing all six now.

| Hero | Family | Position | Range | The one-line idea |
| --- | --- | --- | --- | --- |
| **Ironclad** | Fantasy | Offlane / initiator | Melee | Walks in first and decides where the fight happens |
| **Emberwitch** | Fantasy | Mid / burst mage | Ranged | Stacks heat on a target, then detonates it |
| **Jukebox** | Side Chat | Support | Ranged | The music bot; buffs by playing, not by targeting |
| **Ghostuser** | Side Chat | Jungle / assassin | Melee | Rewards going quiet; punishes being watched |
| **Overclock** | Sci-fi | Carry / marksman | Ranged | Ramps to enormous DPS and cooks himself doing it |
| **Relay** | Sci-fi | Flex / summoner | Ranged | Fights through drones and reshapes the ground |

### Ironclad — offlane, tank/initiator

| | Ability | Targeting | What it does |
| --- | --- | --- | --- |
| Q | **Shield Charge** | Vector | Dashes along a line, stopping at the first enemy hero hit and stunning them 1.2s. Passes through creeps. |
| W | **Bulwark** | Toggle | +40 armour, −25% move speed, and blocks enemy projectiles that would hit him. Drains mana per second. |
| E | **Taunt** | Unit | Forces an enemy hero to attack Ironclad for 1.5s, overriding their orders. |
| R | **Last Stand** | Channel (2s) | Channels, then knocks back and damages everything nearby. Damage scales with the health he is *missing*. |

The engine questions he asks: a dash that terminates on a collision condition, a toggle with
per-tick upkeep, projectile interception, an order override that is not a stun, a channel that
an enemy stun can interrupt, and a stat that reads the caster's own missing health.

### Emberwitch — mid, burst mage

| | Ability | Targeting | What it does |
| --- | --- | --- | --- |
| Q | **Cinder** | Point (AoE) | Damages in a circle and leaves burning ground for 4s that damages over time. |
| W | **Kindle** | Passive | Her damage applies a stack of **Heat** (max 3). Each stack is +8% magic damage taken. |
| E | **Flashstep** | Self (blink) | Short blink. Her next spell within 3s costs no mana. |
| R | **Pyre** | Skillshot (line) | A piercing line. Consumes all Heat on each target hit for heavy bonus damage. |

Asks for: a persistent ground zone that is an entity in its own right, a stacking debuff with
its own duration, a conditional buff that a *later* action consumes, a piercing line that hits
an ordered set, and an effect that reads-and-clears a debuff.

### Jukebox — support

The music bot from the app, as a hero. Everything he does is a broadcast: he buffs by playing,
which means his abilities land on *whoever is standing near him*, not on whoever he clicked.

| | Ability | Targeting | What it does |
| --- | --- | --- | --- |
| Q | **Drop the Beat** | Self (aura) | For 6s, allies in radius gain +15% move and attack speed. The aura moves with him. |
| W | **Requiem** | Unit (ally) | Heals over 4s. **If the target dies while it is running, Jukebox is healed for the remainder instead.** |
| E | **Feedback** | Point (AoE) | Silences enemies in a circle for 1.5s. |
| R | **Encore** | Channel (3s) | Pulses a heal to every ally on the team each second and makes them immune to slows. Interruptible. |

Asks for: a mobile timed aura with continuous membership, a buff with a **death trigger** that
redirects its remaining effect, a silence (which must block ability casts but not attacks or
movement), and a status *immunity* rather than a status.

### Ghostuser — jungle, assassin

| | Ability | Targeting | What it does |
| --- | --- | --- | --- |
| Q | **Idle** | Self | After 1.5s of taking no action he becomes invisible and gains +20% move speed. Any action breaks it. |
| W | **Read Receipt** | Unit | Marks an enemy for 8s: he permanently sees them, and deals +20% damage to them. |
| E | **Backspace** | Self | Blinks to **where he stood 3 seconds ago** and heals for 30% of the damage he took in that window. |
| R | **Ban** | Unit (1.2s cast) | Removes the target from the map for 2.5s — untargetable, unable to act — then returns them. |

The most demanding hero in the roster, and that is why he is in phase 1's design rather than
bolted on later. **Backspace requires the sim to keep positional history** — a ring buffer per
hero, 90 entries at 30Hz — which is a state-shape decision that cannot be retrofitted quietly.
**Ban requires a suspended entity state** that is neither alive-and-present nor dead. And
**Idle** requires the sim to have a notion of "has taken no action", which means actions must
be observable as events rather than only as mutations.

### Overclock — carry, marksman

| | Ability | Targeting | What it does |
| --- | --- | --- | --- |
| Q | **Spool Up** | Passive | Consecutive attacks on the *same* target ramp attack speed, up to +100%. Switching targets resets it. |
| W | **Vent** | Self | Removes all slows on himself and grants 0.5s of damage immunity. |
| E | **Rail** | Skillshot (line) | A line whose damage scales with his current Spool Up stacks. |
| R | **Meltdown** | Toggle | Huge attack speed, but he takes escalating damage every second it is on. Vent or die. |

Asks for: an attack-chain passive that remembers its last target, a self-dispel restricted to
one status category, a true damage-immunity window, and a toggle whose upkeep is *health*.
He is also the roster's only pure scaling carry, which is what makes the five items matter.

### Relay — flex, summoner/zoner

| | Ability | Targeting | What it does |
| --- | --- | --- | --- |
| Q | **Deploy Drone** | Point (summon) | A drone that attacks nearby enemies on its own for 20s. |
| W | **Link** | Unit (ally or drone) | Tethers to them: 30% of damage they take is redirected to Relay. Breaks past 900 units. |
| E | **Barrier** | Point | A wall that blocks *movement* for 3s. Projectiles pass over it. |
| R | **Swarm** | Self | Summons four drones at once, all pre-Linked. |

Asks for: summoned units with autonomous behaviour (reuse the creep AI — a drone is a creep
that belongs to a hero), a tether with a range-break condition, **damage redirection**, and
**runtime mutation of the collision grid**, which the pathfinder must survive.

## The items

Five, and none of them is allowed to be a pure stat stick except the first. Five slots is a
small enough set that each one should be asking the engine a different question.

| Item | Grants | The question it asks |
| --- | --- | --- |
| **Bootstrap** | +90 move speed | None. The baseline everyone buys, and the control case. |
| **Ledger** | +35 attack damage; +4 gold per creep kill | An **on-event hook** — items must be able to subscribe to sim events, not only add stats. |
| **Firewall** | +12 armour; **active**: shield yourself for 200 damage, 3s | An item with an **active ability**, which means items and heroes must share one ability system. |
| **Broadcast** | +250 health; **aura**: allies within 700 units regenerate +6 hp/s | An **aura from an item**, proving auras are not a hero-only concept. |
| **Null Pointer** | +40 magic damage; **passive**: your abilities apply −40% healing received, 4s | An item that **applies a debuff on someone else's action**, hooking the damage pipeline. |

An item's stats reach its owner as `Modifier`s sourced to that item, not as a lookup at read
time — so selling detaches exactly those, and `Entity::effective_stats` never learns the
catalogue exists. It is the mechanism Bulwark's toggle already used.

A hero's castables are **one flat array**: slots `0..4` are the hero, `4..10` are the inventory.
Firewall's active is cast through the same command, cooldown, silence and mana path as Pyre,
because everything downstream is indifferent to which half a slot came from.

`Null Pointer` against `Jukebox` is the intended interaction, and it is the reason healing
reduction exists in a five-item game at all: the roster has a dedicated healer, so it needs an
answer.

## What the engine must support

The extraction. Everything below appears at least twice above, which is the bar for it being a
primitive rather than a special case.

**Targeting modes** — `SelfCast`, `Unit`, `Point`, `Vector` (a direction, for dashes),
`Skillshot` (a line with pierce rules), `Toggle`, `Passive`, `Channelled`.

**Effects** — damage (physical / magic / pure), heal, shield, stun, silence, slow, knockback,
dash, blink, stealth, reveal, summon, tether, ground zone, terrain mutation, banish, mark,
stacking debuff, damage-over-time, dispel, immunity window, status immunity, forced-attack
order.

Three findings from the roster that shape the crate, and they are the reason this document was
worth writing before any code:

1. **Damage cannot be `target.hp -= n`.** Armour, shields, Heat stacks, Relay's tether,
   Overclock's immunity window, Ghostuser's mark and Null Pointer's healing debuff all mutate a
   damage event in flight. Damage must be a **pipeline** — an event built by the source, passed
   through an ordered set of modifiers contributed by buffs, items and passives, and only then
   applied. Retrofitting this later touches every ability in the game.

2. **Positional history is part of sim state.** Backspace needs three seconds of it. That means
   it must be in the snapshot, in the serialization, and in whatever a headless replay test
   reconstructs — it is not a client-side nicety.

3. **The collision grid is mutable at runtime.** Relay's Barrier means pathing cannot bake flow
   fields once at match start. They need invalidation, and creeps mid-path need to survive the
   ground changing under them.

**Deliberately out of scope for now**, per the agreed cut: replays, anti-cheat beyond
server-side fog of war, ranked ladders, parties, chat, and hero levels beyond a flat curve.
Fog of war is *in*, and note that it is not only a game mechanic — because the server sends
each team a separately filtered snapshot, a client never receives an enemy it cannot see, so
the cheat that matters most in this genre has no surface to attack.

## Crate layout

```
game/moba/
├── moba-sim/      the whole game. pure, deterministic, zero I/O
├── moba-proto/    wire types. serde + postcard. shared by all three
├── moba-server/   tokio. owns N matches, one task each, 30Hz
└── moba-client/   wasm. render, input, interpolation
```

`moba-sim` depends on nothing but `moba-proto`. No `std::time`, no ambient RNG (a seeded
generator lives *in* the state), and no iteration over a `HashMap` in anything that can affect
the outcome. **Fixed-point `Q16.16`, not `f32`** — the four arithmetic operations are
deterministic across wasm and native but `sqrt`, `sin` and `atan2` are not, and a MOBA is
almost entirely distance checks and angles.

The purity is not aesthetic. It is what lets the same crate run on a server, in a browser, and
in a headless test that replays a command log and asserts an identical end state — and what
keeps the hosting decision cheap to reverse.

## Netcode

Command-delay and server-authoritative, as Dota and LoL are — **not** rollback.

- The client sends *orders* (`MoveTo`, `CastAbility { slot, target }`), never positions.
- The server simulates at 30Hz and sends each team a fog-filtered snapshot at 20Hz.
- The client renders ~100ms in the past, interpolating between the last two snapshots.
- Your own hero starts its animation immediately and shows a click marker, but is not
  predicted.

Rollback exists for games where a single frame of input matters. Here the atom of input is "go
there" or "cast that", and ~100ms of order latency is hidden entirely by the cast animation.
Skipping it removes the hardest part of the project at almost no cost to feel.

Transport sits behind the socket layer in `moba-server::server`: WebSocket now, WebTransport when
Safari catches up. `Room` itself has never heard of a socket, which is what keeps the hosting
question cheap and what lets the whole match lifecycle be tested without opening a port.

Messages are JSON while the client is being written — readable in a browser's network tab is
worth a lot right now — behind two functions, so `postcard` is a two-line swap once the shape
settles.

**The client and server must run the same sim build.** Version-stamp the protocol and have the
server reject a mismatched client, or a stale wasm bundle in one player's cache becomes an
evening of chasing a desync that was never in the code.

## Phases

1. **`moba-sim`, headless.** ✅ *Foundation done.* Fixed-point maths, the generational entity
   arena, the damage pipeline, the one-lane map and the tick loop, with a test that pushes a
   lane until a base falls — plus a control test asserting an *even* lane does **not** resolve,
   which is what would catch arena order leaking into targeting. **Ironclad and Emberwitch are
   both playable** — all eight abilities, plus Firewall as an item active running through the
   same `AbilitySpec` path. **Gold, last-hitting, denies and all five items are in**, each item
   proving one of the four hooks below. Phase 1 is complete.
   Run it with `make rust-test`; lint with `make rust-lint`.

   The ability engine is data-driven ([ability.rs](game/moba/moba-sim/src/ability.rs)): a
   targeting mode, some costs, and a list of (*who it hits*, *what it does*) pairs. Adding the
   remaining four heroes should be adding catalogue entries, not writing code — that claim is
   what `tests/abilities.rs` exists to keep honest.
2. **`moba-server` + WebSocket + a wasm client.** ✅ *Playable.* `moba-proto`
   carries the wire types, `moba-server` runs a real 30Hz tokio loop behind a WebSocket, and
   per-team fog-filtered snapshots go out at 20Hz. Nineteen tests cover it, four of them against
   a real socket. `moba-client` compiles to a 254KB wasm bundle with a canvas renderer, click
   orders and a ~100ms interpolation buffer — 23 tests, all of which run on the host because the
   parts with rules in them have no browser dependency.

   ```
   make moba-server          # a shell, TEAM_SIZE=1 by default
   make moba-play            # another shell — builds the client, serves the harness
   ```

   Then open **http://localhost:9301** in two tabs. Art is coloured discs on purpose: the
   sprite work described above is a much larger job and should not start until the thing
   underneath is known to feel right.

   **Team size is a parameter, 1 through 5** (`MOBA_TEAM_SIZE`). A 5v5 needs ten people, and a
   mode that cannot be played until it is finished never gets tested; waves and structure health
   scale with it so a 1v1 is a real game rather than a 5v5 with eight seats empty. 1v1 mid and
   2v2 are also formats people actually play.

   ```
   MOBA_TEAM_SIZE=1 cargo run -p moba-server     # two browser tabs is a match
   ```
3. **Widen.** 🔨 *Feedback and touch done; content next.*

   The first playable build showed almost nothing: the simulation was running correctly and the
   client drew coloured discs and no more, so you could not tell whether an ability had fired,
   what killed a creep, or that a tower was the thing killing you. That is a rendering gap, not
   a mechanics gap, and it was the wrong thing to paper over by adding content. Now in:
   damage numbers, hit lines back to whatever hit you, cast rings, death marks, a hurt flash,
   an ability bar with cooldown sweeps, and a win banner.

   **Touch works**, so the mobile build is a real target: a tap is the *command* gesture (a
   finger has one button, and the genre is built on the second one), the ability bar doubles as
   the on-screen controls, and taps are handled on `touchstart` to dodge the 300ms click delay.
   From a phone on the same network: `http://<host>:9301/?server=ws://<host>:930`.

   **Three lanes are in**, on a 6000-unit square with the bases on a diagonal — mid short, top
   and bottom hugging the edges. Jungle terrain between them is real collision: the map fills
   the interior and then *carves the lanes out of it*, which is what guarantees a lane is
   walkable end to end rather than hoping the rocks landed elsewhere.

   Two towers per lane per side, placed by interpolating along the lane so re-routing a lane
   moves its towers with it. They are **guarded**: an outer tower must fall before the one
   behind it, and a base opens once any one lane is fully broken. Without that rule the correct
   opening is to walk past every tower to the enemy base and the lanes are scenery.

   The map now goes **over the wire** at the handshake. It has to: the old client drew a
   hardcoded diagonal between two literal coordinates that happened to coincide with the only
   lane there was — correct by luck, and it would have drawn one stripe through the middle of
   three without complaining.

   **All six heroes are playable.** Jukebox, Ghostuser, Overclock and Relay were designed at the
   same time as the first two and implemented against a finished ability engine — and every one
   of their sixteen abilities went in as a new `Effect` arm plus a catalogue entry, with no
   change to the cast path, the tick order or the damage pipeline. Sixteen tests, all green on
   the first run. That is the spec-first bet paying out.

   Three of the four needed the primitives MOBA.md's findings called for, and they were there:
   Ghostuser's Backspace spends the position history, Relay's Barrier mutates the collision grid
   at runtime and lifts itself again, and his Link rides the damage pipeline's redirect stage.

   **Fog knows about walls.** Vision is a radius check *and* a line-of-sight walk across the
   terrain grid, so standing behind jungle is hiding rather than merely being far away — which
   is what makes a gank possible at all. The walk is Amanatides–Woo rather than sampling the
   line at intervals, because sampling skips a cell whenever the line clips a corner and leaks
   vision through walls at particular angles only: rare, angle-dependent, and indistinguishable
   from cheating when a player reports it. A test fires 64 rays into solid rock and requires all
   64 to be stopped.

   Enemy structures stay visible regardless, as they are in every game in the genre.

   Phase 3 is complete.

   **Attack feel.** Ranged autoattacks are travelling projectiles rather than instant hits,
   which is the whole of what makes a ranged hero read as ranged — and it adds a real mechanic
   with it, since a bolt whose target dies in flight is wasted. Last-hitting at range means
   leading the creep's health rather than reacting to it. Your attack range is drawn as a faint
   ring, so melee and ranged are visibly different things.

   **Denies need a wounded creep.** Below half health only. Without the rule a laner could deny
   a whole wave from the instant it spawned, which deletes the lane rather than expressing skill.

   **No art yet.** Everything renders as coloured discs. The sprite work described above is a
   separate and much larger job, and it should not start until the mechanics have been played.
4. **PHP.** ✅ Queue, match tickets, results, MMR, `MobaApp.vue`. The two halves meet exactly
   twice, both signed and both one-way: a ticket lets a player in, a result comes back out.
   Verified end to end — PHP forms a roster, mints a ticket the Rust server accepts and seats by
   slot, and the server reports a result PHP records and rates. `tests/report.rs` covers the
   second crossing and skips loudly when there is no API to talk to.

5. **Depth.** 🔨 Skill points are in: a level grants a point, a point raises one ability, and an
   ability at rank zero cannot be cast at all. Basics cap at every other level so a hero cannot
   max one before leaving lane; the ultimate follows the genre's 6/11/16. That replaced the flat
   "ultimates unlock at six" special case — every hero ability now answers the same question the
   same way, and item actives are exempt because owning the item is what grants them.

   Still to come: spectating, replays, and a proper post-game screen.

Hosting is deliberately undecided; see the crate layout for why it can afford to be.
