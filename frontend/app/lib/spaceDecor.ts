/**
 * The furniture in a room: what each piece is, how big it is, and what it looks like.
 *
 * The catalogue is the browser's half of {@link file://../../../backend/app/Support/SideSpace/Decorations.php}
 * — same kinds, same footprints, same solidity — for the same reason the collision grid is
 * mirrored: the renderer needs a piece's size sixty times a second and the walk loop needs its
 * solidity, and neither can ask the server. The two extra fields here are the ones only a
 * renderer cares about: the sprite, and whether the piece stands up.
 *
 * ## Standing up
 *
 * A sprite is authored on a 16-pixel grid per tile of footprint, and drawn one of two ways.
 * `lift` pieces — anything with a front and a top — are drawn about a third taller than their
 * footprint and anchored at the bottom, exactly as a person is anchored at their feet: a
 * bookshelf occupies one tile of floor and rises out of it. Flat pieces (a rug, a doormat) are
 * drawn to their footprint exactly, because a rug that rose out of the floor would be a trip
 * hazard rather than a rug.
 *
 * ## Interaction
 *
 * `interact` says which **Side Desk app** pressing E opens, and is here *only* to draw the
 * prompt. The server decides what actually opens, from its own copy of the map — see the
 * interact endpoint. Keeping a copy here means the prompt can say "Put something on" the instant
 * you step up to the speaker rather than a round trip later.
 *
 * Both families of app are reachable: a widget app (the speaker, the arcade cabinet) opens the
 * channel's widget, and a surface app (the whiteboard, the lectern) opens the panel itself. The
 * room is a set of doors onto the Side Desk, not a second copy of it.
 *
 * ## Which way it's turned
 *
 * A piece stores a `facing`, and a quarter turn swaps its footprint — a 2×1 desk turned to face
 * right takes up 1×2 tiles. Every "what does this cover" question goes through {@link decorSize}
 * so the collision grid, the editor's hit test and the renderer can't disagree about it. How the
 * *art* follows the turn is a separate question with a less obvious answer — see {@link pose}.
 */

import type { SideDeskAppId } from '~/types'
import type { SheetSpec, StillSpec } from './spriteSheet'
import { blit, sprite, tileNoise } from './pixelSprite'
import { drawSheetFrame, drawStill, sheetReady, sheetRow } from './spriteSheet'

export type DecorMount = 'floor' | 'wall'

/** Which way a piece is turned. `down` is the front view every sprite is authored in. */
export type DecorFacing = 'down' | 'left' | 'up' | 'right'

export const DECOR_FACINGS: DecorFacing[] = ['down', 'left', 'up', 'right']

/** Quarter turns clockwise from the authored front view. Mirrors the server's table. */
const TURNS: Record<DecorFacing, 0 | 1 | 2 | 3> = { down: 0, left: 1, up: 2, right: 3 }

export interface DecorKind {
  label: string
  /** Footprint, in tiles. */
  w: number
  h: number
  /** Does it block movement? Wall-mounted pieces never do. */
  solid: boolean
  mount: DecorMount
  /** Drawn taller than its footprint and anchored at the bottom — see above. */
  lift: boolean
  /**
   * The piece seen from its side, authored in the box a quarter turn puts it in — so a 2×1 desk's
   * side view is a 1×2 grid, 16 wide and 32 tall. Drawn as-is for a piece facing *right* and
   * mirrored for one facing left, which is right for anything whose two sides match, and that is
   * every piece of furniture here.
   */
  side: string[] | null
  /** The piece seen from behind, in the same box as its front view. */
  back: string[] | null
  /**
   * A door: something that gets out of the way rather than something you walk round.
   *
   * The one kind whose solidity is a property of the *moment*. `solid` stays true — that is what
   * the server stores and what every question about the room at rest wants — and the walk loop
   * consults {@link SpaceObject.open}, which the room recomputes each frame from where everybody
   * is standing. See lib/spaceDoors.ts.
   */
  door: boolean
  /** The three views again, for a door standing open. Only doors have these. */
  open: { art: string[], side: string[] | null, back: string[] | null } | null
  /** The Side Desk app pressing E opens, if any. */
  interact: SideDeskAppId | null
  /** What the prompt says you'd be doing. */
  verb: string | null
  /**
   * Can you sit on it?
   *
   * Purely a matter of drawing and prompting, which is why it lives here and has no counterpart
   * on the server: sitting never reaches the API. Where you're sitting rides along with your
   * position over the same whispers that carry your walking, and a client that lied about it
   * would be a client claiming to be sitting on a plant pot — visible, harmless, and its own
   * punishment. Everything that *is* worth enforcing about furniture (its size, its solidity,
   * what pressing E opens) stays with the server, exactly as before.
   */
  seat: boolean
  /**
   * Is the whole piece **one** seat, rather than a row of them?
   *
   * The distinction a footprint can't make on its own. A two-tile bench is two places to sit and
   * a three-tile couch is three, which is why {@link seatOn} normally puts you on the tile you
   * walked up to — walk to the far end of a couch and you sit at the far end of it, and two
   * people on one couch sit side by side.
   *
   * A throne is two tiles across and is emphatically not two seats. Sitting on the left half of
   * a throne is not a thing anybody has ever done, so a piece that says so is centred instead:
   * you end up in the middle of it however you arrived, which is the only place there is.
   */
  oneSeat: boolean
  /**
   * Real artwork from a sprite sheet, drawn instead of the character grid when it's on disk.
   *
   * The same escape hatch the pets have ({@link file://./spacePets.ts}) and for the same reason:
   * a sixteen-pixel grid is a good way to draw a bookshelf and a poor way to draw a creature.
   * The grid stays as the fallback, so a kind with a sheet still looks finished on a deploy
   * where the PNG never made it — and `sheetReady` is checked per frame, which costs nothing
   * and means the artwork simply appears when it arrives.
   *
   * `facing` picks the row, so a piece drawn from a sheet still turns like everything else.
   */
  sheet: SheetSpec | null
  /**
   * A single hand-drawn picture, for a piece that has one view and doesn't move.
   *
   * The other half of the same escape hatch as {@link sheet}, and the one most furniture wants:
   * a throne is not an animation and has no eight directions, so asking it to be a sheet would
   * mean a PNG that is seven-eighths empty. Drawn at `scale` tiles tall, anchored at the bottom
   * centre of the footprint — so a piece taller than its floor space rises out of it exactly as
   * a `lift`ed grid sprite does, and the same thing is true of a person standing in front of it.
   *
   * The character grid stays as the fallback, so the room is finished either way.
   */
  still: StillSpec | null
}

/** A decoration as it's stored: a kind, a place, and which way it's turned. */
export interface SpaceObject {
  id: string
  kind: string
  x: number
  y: number
  /** Absent on everything placed before pieces could be turned, and read as `down`. */
  facing?: DecorFacing
  /**
   * Doors only, and *not stored*: whether this one is standing open right now.
   *
   * Written onto the object each frame by {@link file://./spaceDoors.ts}, before anything asks
   * whether a tile is walkable. It rides on the object rather than being threaded through
   * `decorBlocks` / `isWalkable` / `step` as a parameter because those are called from a dozen
   * places — the walk loop, the pet loop, spawning, the editor, the map validator's mirror — and
   * all but one of them want the same answer they always gave. A field the editor never sets is
   * a door the editor treats as shut, which is exactly right for editing.
   */
  open?: boolean
}

// --- the palette every piece of furniture shares ---

const P: Record<string, string> = {
  o: '#2b2a24', // outline
  w: '#a9784c', // wood
  W: '#c69565', // wood, lit
  n: '#8d6039', // wood, shadowed
  m: '#8d8677', // metal
  M: '#c3bdb0', // metal, lit
  d: '#3a3f4b', // casing, screens when off
  s: '#5aa8d8', // screen
  S: '#bfe8ff', // screen, highlight
  g: '#3f7d2c', // leaf
  G: '#5aa03a', // leaf, lit
  r: '#c0453f', // red
  y: '#e8c25a', // yellow
  b: '#3f6fb5', // blue
  c: '#7c5f9e', // fabric
  C: '#9b7cc0', // fabric, lit
  p: '#f2ecdd', // paper
  k: '#1b1a16', // black
  f: '#ef8f3c', // flame
  F: '#ffd166', // flame, hot
  t: '#d7bc8a', // cushion / tan
  e: '#6f6a60', // stone, shadowed (gym set)
  E: '#a8a29a', // stone, lit
  h: '#4a4640', // stone, deep shadow
  i: '#3c4049', // black iron (the throne)
  I: '#6d7482', // black iron, lit — an edge catching the light
  l: '#b9b6c4', // espurr fur
  L: '#d3d1dc', // espurr fur, lit
  u: '#8f8b9e', // espurr fur, shadowed
  v: '#a982c9', // espurr eye
  V: '#f3e6ff', // espurr eye, glint
  a: '#e6dfc9', // cream — ear linings
}

/*
 * The sprites.
 *
 * Every row is 16 characters per tile of width, and every grid 16 rows per tile of height.
 * `.` is transparent; every other character is a slot in the palette above.
 */

const SPEAKER = [
  '................',
  '....oooooooo....',
  '....okkkkkko....',
  '....ok....ko....',
  '....okmmmmko....',
  '....okmMMmko....',
  '....okmMMmko....',
  '....okmmmmko....',
  '....ok....ko....',
  '....okkkkkko....',
  '....okmmmmko....',
  '....okmMMmko....',
  '....okmMMmko....',
  '....okmmmmko....',
  '....okkkkkko....',
  '....oooooooo....',
]

const TV = [
  '................................',
  '....oooooooooooooooooooooooo....',
  '....odddddddddddddddddddddddo...',
  '....odsssssssssssssssssssssdo...',
  '....odsSSssssssssssssssssssdo...',
  '....odsSssssssssssssssssssSdo...',
  '....odssssssssssssssssssssSdo...',
  '....odsssssssssssssssssssssdo...',
  '....odsssssssssssssssssssssdo...',
  '....odddddddddddddddddddddddo...',
  '....oooooooooooooooooooooooo....',
  '..............oooo..............',
  '..............oddo..............',
  '..............oddo..............',
  '..........oooooooooooo..........',
  '..........okkkkkkkkkko..........',
]

const COMPUTER = [
  '................',
  '...oooooooooo...',
  '...oddddddddo...',
  '...odssssssdo...',
  '...odsSSsssdo...',
  '...odsssssSdo...',
  '...odddddddo....',
  '.....oooooo.....',
  '......oddo......',
  '......oddo......',
  '....oooooooo....',
  '....okkkkkko....',
  '................',
  '..oooooooooooo..',
  '..oMMMMMMMMMMo..',
  '..oooooooooooo..',
]

const ARCADE = [
  '................',
  '..oooooooooooo..',
  '..orrrrrrrrrro..',
  '..orossssssoro..',
  '..oroskkkksoro..',
  '..oroskySkkoro..',
  '..oroskkkksoro..',
  '..orossssssoro..',
  '..orrrrrrrrrro..',
  '..orkbrkkbrkro..',
  '..orrrrrrrrrro..',
  '..odddddddddo...',
  '..oddddddddddo..',
  '..oddddddddddo..',
  '..oooooooooooo..',
  '..okkkkkkkkkko..',
]

const RACER = [
  '................',
  '..oooooooooooo..',
  '..obbbbbbbbbbo..',
  '..obossssssobo..',
  '..obosSSssbobo..',
  '..obossssssobo..',
  '..obbbbbbbbbbo..',
  '..obbomMmobbbo..',
  '..obbomMmobbbo..',
  '..obbbbbbbbbbo..',
  '...odddddddo....',
  '..oddddddddddo..',
  '..oddddddddddo..',
  '..oddddddddddo..',
  '..oooooooooooo..',
  '..okkkkkkkkkko..',
]

const EASEL = [
  '................',
  '..oooooooooooo..',
  '..oppppppppppo..',
  '..oppprrppppgo..',
  '..oppprrppgggo..',
  '..oppppppgggpo..',
  '..oppbbppppppo..',
  '..oppbbppppppo..',
  '..oooooooooooo..',
  '.....owwwwo.....',
  '....ow.ww.wo....',
  '...ow..ww..wo...',
  '..ow...ww...wo..',
  '..o....ww....o..',
  '.......ww.......',
  '......oooo......',
]

const DESK = [
  '................................',
  '................................',
  '................................',
  '....oooooooooooooooooooooooo....',
  '....oWWWWWWWWWWWWWWWWWWWWWWo....',
  '....owwwwwwwwwwwwwwwwwwwwwwo....',
  '....oooooooooooooooooooooooo....',
  '....on....................no....',
  '....on..oooooooo..........no....',
  '....on..owwwwwwo..........no....',
  '....on..oooooooo..........no....',
  '....on....................no....',
  '....on..oooooooo..........no....',
  '....on..owwwwwwo..........no....',
  '....on..oooooooo..........no....',
  '....oooo..............oooooo....',
]

const COUCH = [
  '................................',
  '................................',
  '...oooooooooooooooooooooooooo...',
  '...occcccccccccccccccccccccco...',
  '...ocCCCCCCCCCCCCCCCCCCCCCCco...',
  '...ocCCCCCCCCCCCCCCCCCCCCCCco...',
  '...occcccccccccccccccccccccco...',
  '.oooccoooooooooooooooooooccooo..',
  '.occcccCCCCCCCCoCCCCCCCCcccco...',
  '.occcccCCCCCCCCoCCCCCCCCcccco...',
  '.occcccccccccccocccccccccccco...',
  '.oooooooooooooooooooooooooooo...',
  '..occcccccccccccccccccccccco....',
  '..oooooooooooooooooooooooooo....',
  '...oo..................oo.......',
  '...oo..................oo.......',
]

const BENCH = [
  '................................',
  '................................',
  '................................',
  '....oooooooooooooooooooooooo....',
  '....oWWWWWWWWWWWWWWWWWWWWWWo....',
  '....oooooooooooooooooooooooo....',
  '....o......................o....',
  '....oooooooooooooooooooooooo....',
  '....oWWWWWWWWWWWWWWWWWWWWWWo....',
  '....owwwwwwwwwwwwwwwwwwwwwwo....',
  '....oooooooooooooooooooooooo....',
  '.....on..................no.....',
  '.....on..................no.....',
  '.....on..................no.....',
  '.....on..................no.....',
  '.....oo..................oo.....',
]

const CHAIR = [
  '................',
  '....oooooooo....',
  '....odddddddo...',
  '....oddddddddo..',
  '....oddddddddo..',
  '....odddddddo...',
  '....oooooooo....',
  '..oooooooooooo..',
  '..odddddddddddo.',
  '..oddddddddddo..',
  '..oooooooooooo..',
  '.......om.......',
  '.......om.......',
  '....ooooooooo...',
  '...omMmmmmmMmo..',
  '...ooo.....ooo..',
]

const STOOL = [
  '................',
  '................',
  '................',
  '................',
  '....oooooooo....',
  '...owwwwwwwwo...',
  '...oWWWWWWWWo...',
  '...oooooooooo...',
  '....on....no....',
  '....on....no....',
  '...on......no...',
  '...on......no...',
  '..on........no..',
  '..on........no..',
  '..oo........oo..',
  '................',
]

const BOOKSHELF = [
  '..oooooooooooo..',
  '..owwwwwwwwwwo..',
  '..oorrbbyygrro..',
  '..oorrbbyygrro..',
  '..owwwwwwwwwwo..',
  '..oobbrryybbro..',
  '..oobbrryybbro..',
  '..owwwwwwwwwwo..',
  '..ooyyggrrbbyo..',
  '..ooyyggrrbbyo..',
  '..owwwwwwwwwwo..',
  '..oorrbbggyyro..',
  '..oorrbbggyyro..',
  '..owwwwwwwwwwo..',
  '..oooooooooooo..',
  '..oo........oo..',
]

const CABINET = [
  '................',
  '................',
  '..oooooooooooo..',
  '..oWWWWWWWWWWo..',
  '..owwwwwwwwwwo..',
  '..owwoooooowwo..',
  '..owwoMMMMowwo..',
  '..owwoooooowwo..',
  '..owwwwwwwwwwo..',
  '..owwoooooowwo..',
  '..owwoMMMMowwo..',
  '..owwoooooowwo..',
  '..owwwwwwwwwwo..',
  '..oooooooooooo..',
  '..oo........oo..',
  '................',
]

const FRIDGE = [
  '...oooooooooo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMmMo...',
  '...oMMMMMMmMo...',
  '...oooooooooo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMmMo...',
  '...oMMMMMMmMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oooooooooo...',
  '...oo......oo...',
]

const WATERCOOLER = [
  '................',
  '.....oooooo.....',
  '....osssssso....',
  '....osSSssso....',
  '....osssssso....',
  '....osssssso....',
  '.....oooooo.....',
  '....oMMMMMMo....',
  '....oMMMMMMo....',
  '....oMoooMMo....',
  '....oMoMoMMo....',
  '....oMMMMMMo....',
  '....oMMMMMMo....',
  '....oMMMMMMo....',
  '....oooooooo....',
  '....oo....oo....',
]

const LAMP = [
  '....oooooooo....',
  '...oFFFFFFFFo...',
  '...oFFFFFFFFo...',
  '..oyyyyyyyyyyo..',
  '..oooooooooooo..',
  '.......om.......',
  '.......om.......',
  '.......om.......',
  '.......om.......',
  '.......om.......',
  '.......om.......',
  '.......om.......',
  '.......om.......',
  '.....oooooo.....',
  '.....oMMMMo.....',
  '.....oooooo.....',
]

const PLANT = [
  '................',
  '.....g...g......',
  '....gGg.gGg.....',
  '...gGGGgGGGg....',
  '..gGGGGGGGGGg...',
  '...gGGGGGGGg....',
  '....gGGGGGg.....',
  '...gGGgGgGGg....',
  '..gGg..g..gGg...',
  '.......g........',
  '....oooooooo....',
  '....orrrrrro....',
  '....orrrrrro....',
  '....oorrrroo....',
  '.....oooooo.....',
  '................',
]

const CRATE = [
  '................',
  '................',
  '..oooooooooooo..',
  '..oWwwwwwwwwWo..',
  '..owWwwwwwwWwo..',
  '..owwWwwwwWwwo..',
  '..owwwWwwWwwwo..',
  '..owwwwWWwwwwo..',
  '..owwwwWWwwwwo..',
  '..owwwWwwWwwwo..',
  '..owwWwwwwWwwo..',
  '..owWwwwwwwWwo..',
  '..oWwwwwwwwwWo..',
  '..oooooooooooo..',
  '................',
  '................',
]

const BARREL = [
  '................',
  '................',
  '...oooooooooo...',
  '...oWWWWWWWWo...',
  '..oowwwwwwwwoo..',
  '..onnnnnnnnnno..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..onnnnnnnnnno..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..onnnnnnnnnno..',
  '...owwwwwwwwo...',
  '...oooooooooo...',
  '................',
  '................',
]

/** Two frames — the only piece of furniture that moves. */
const CAMPFIRE = [
  [
    '................',
    '................',
    '.......F........',
    '......FFF.......',
    '.....FfFfF......',
    '.....ffFff......',
    '....ffffff......',
    '....ffffff......',
    '.....ffff.......',
    '..o..fff...o....',
    '..now.f...won...',
    '..onwwwwwwwno...',
    '...onwwwwwno....',
    '...ookkkkoo.....',
    '....oooooo......',
    '................',
  ],
  [
    '................',
    '................',
    '........F.......',
    '.......FF.......',
    '......FfFF......',
    '......ffFf......',
    '.....fffff......',
    '.....ffffff.....',
    '.....ffff.......',
    '..o...ff...o....',
    '..now.f...won...',
    '..onwwwwwwwno...',
    '...onwwwwwno....',
    '...ookkkkoo.....',
    '....oooooo......',
    '................',
  ],
]

// --- the gym set ---

const PILLAR = [
  '................',
  '....oooooooo....',
  '....oEEEEEEo....',
  '...oEEEEEEEEo...',
  '...oEeeeeeEEo...',
  '...oEeeeeeEEo...',
  '...oEeeeeeEEo...',
  '...oEeeeeeEEo...',
  '...oEeeeeeEEo...',
  '...oEeeeeeEEo...',
  '...oEeeeeeEEo...',
  '...oEeeeeeEEo...',
  '...oEEEEEEEEo...',
  '..oEEEEEEEEEEo..',
  '..oEEEEEEEEEEo..',
  '..oooooooooooo..',
]

const STATUE = [
  '................',
  '......oooo......',
  '.....oEEEEo.....',
  '.....oEEEEo.....',
  '......oEEo......',
  '....ooEEEEoo....',
  '...oEEEEEEEEo...',
  '...oEEeeeeEEo...',
  '...oEEeeeeEEo...',
  '....oEeeeeEo....',
  '.....oeeeeo.....',
  '....oEEEEEEo....',
  '...oEEEEEEEEo...',
  '..oEEEEEEEEEEo..',
  '..oeeeeeeeeeeo..',
  '..oooooooooooo..',
]

const TORCH = [
  '................',
  '.......f........',
  '......fFf.......',
  '......fFf.......',
  '.....fFFFf......',
  '......fFf.......',
  '......oEo.......',
  '.....oEEEo......',
  '......oeo.......',
  '......oeo.......',
  '......oeo.......',
  '......oeo.......',
  '......oeo.......',
  '.....oEEEo......',
  '.....ooooo......',
  '................',
]

const BOULDER = [
  '................',
  '................',
  '....oooooo......',
  '..ooEEEEEEoo....',
  '.oEEEEEEEEEEo...',
  '.oEEEEeeEEEEo...',
  'oEEEeeeeeEEEEo..',
  'oEEeeeeeeeEEEo..',
  'oEeeeeeeeeeeEo..',
  '.oeeeeeeeeeeo...',
  '.oEeeeeeeeeEo...',
  '..ooEeeeeEoo....',
  '....oooooo......',
  '................',
  '................',
  '................',
]

/**
 * The Iron Throne: a seat welded out of surrendered swords.
 *
 * **Two tiles by two**, unlike every other seat, and that size is the feature rather than a
 * flourish. A one-tile throne is a chair: sitting on it puts a sprite over the whole sprite, and
 * the thing you climbed onto disappears underneath you at the moment it matters. At 2×2 you sit
 * on the front row while the back row and the crown of blades — eight of them, rising a third of
 * the footprint again above it on the `lift` — stay standing behind your head.
 *
 * No `side` view: a square standing piece falls through to {@link pose}, which mirrors rather
 * than rotating, so a throne turned sideways is still a throne facing you rather than a throne
 * lying down. The `back` is worth having, because a throne is the one piece of furniture in a
 * room that people deliberately walk round.
 */
const THRONE = [
  '..o...o...o...o...o...o...o...o.',
  '..I...I...I...I...I...I...I...I.',
  '.oIo.oIo.oIo.oIo.oIo.oIo.oIo.oIo',
  '.oio.oio.oio.oio.oio.oio.oio.oio',
  '.oio.oio.oio.oio.oio.oio.oio.oio',
  '.........oio.oio.oio.oio........',
  '.........oio.oio.oio.oio........',
  '.........oio.oio.oio.oio........',
  '.oooooooooooooooooooooooooooooo.',
  'ooiiiiiiiiiiiiiiiiiiiiiiiiiiiioo',
  '.oiiiIiiiiiiiiiiiiiiiiiiiiIiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiiIiiiiiiiiiiiiiiIiiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiiIiiiiiiiiiiiiiiIiiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiIiiiiiiiiiiiiiiiiiiiiIiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oooooooooooooooooooooooooooooo.',
  'ooiiiiiiiiiiiiiiiiiiiiiiiiiiiioo',
  'oiIIIIIIIIIIIIIIIIIIIIIIIIIIIIio',
  'oiiiiiiiiiiiiiiiiiiiiiiiiiiiiiio',
  '.oooooooooooooooooooooooooooooo.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.ooo........................ooo.',
  '................................',
]

/** The throne from behind: a wall of welded steel with the blades still showing over the top. */
const THRONE_BACK = [
  '..o...o...o...o...o...o...o...o.',
  '..I...I...I...I...I...I...I...I.',
  '.oIo.oIo.oIo.oIo.oIo.oIo.oIo.oIo',
  '.oio.oio.oio.oio.oio.oio.oio.oio',
  '.oio.oio.oio.oio.oio.oio.oio.oio',
  '.........oio.oio.oio.oio........',
  '.........oio.oio.oio.oio........',
  '.........oio.oio.oio.oio........',
  '.oooooooooooooooooooooooooooooo.',
  'ooiiiiiiiiiiiiiiiiiiiiiiiiiiiioo',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiIiiiiiiiiiiiiiiiiIiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiiiiiiiiIIiiiiiiiiiiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oiiiiiIiiiiiiiiiiiiiiiiIiiiiio.',
  '.oiiiiiiiiiiiiiiiiiiiiiiiiiiiio.',
  '.oooooooooooooooooooooooooooooo.',
  'ooiiiiiiiiiiiiiiiiiiiiiiiiiiiioo',
  'oiiiiiiiiiiiiiiiiiiiiiiiiiiiiiio',
  'oiiiiiiiiiiiiiiiiiiiiiiiiiiiiiio',
  '.oooooooooooooooooooooooooooooo.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.oii........................iio.',
  '.ooo........................ooo.',
  '................................',
]

/**
 * An Espurr plush: the pet, sewn shut and sat on the floor.
 *
 * Deliberately the pet's own front view rather than a new drawing of the same creature — a plush
 * of something is supposed to read as *that thing*, and the two sitting side by side (one
 * trotting after you, one on a shelf) is the joke. Only the seams differ.
 */
const ESPURR_PLUSH = [
  '................',
  '..o..........o..',
  '..oa........ao..',
  '..oaao....oaao..',
  '..oaaao..oaaao..',
  '..ollllllllllo..',
  '.ollllllllllllo.',
  '.olvvvvvvvvvvlo.',
  '.olvVvllllvVvlo.',
  '.olvvvllllvvvlo.',
  '.ollllluulllllo.',
  '.olLLLLLLLLLLlo.',
  '..ollllllllllo..',
  '...ouo....ouo...',
  '...ouo....ouo...',
  '...ooo....ooo...',
]

/**
 * The Vessel plush: the same toy in its hooded robe and painted mask.
 *
 * Drawn from the palette already here rather than adding slots for it — the robe is the black
 * every outline is drawn in, the trim is the gold the bookshelf's spines use, and the mask is
 * the paper white. Which is the point of a shared palette: a third of these sprites are the
 * same eight colours in a different arrangement.
 */
const ESPURR_PLUSH_VESSEL = [
  '................',
  '..o..........o..',
  '..ok........ko..',
  '..okko....okko..',
  '..okkko..okkko..',
  '..okkkkkkkkkko..',
  '.okkkkkkkkkkkko.',
  '.okkppppppppkko.',
  '.okkpkppppkpkko.',
  '.okkppppppppkko.',
  '.okkyyyyyyyykko.',
  '.okkkkkkkkkkkko.',
  '..okkkkkkkkkko..',
  '...oko....oko...',
  '...oko....oko...',
  '...ooo....ooo...',
]

/** The Pikachu plush: the same silhouette in yellow, with dark ear-tips and a red cheek. */
const ESPURR_PLUSH_PICKACHU = [
  '................',
  '..o..........o..',
  '..ok........ko..',
  '..okko....okko..',
  '..oyyyo..oyyyo..',
  '..oyyyyyyyyyyo..',
  '.oyyyyyyyyyyyyo.',
  '.oykkkkkkkkkkyo.',
  '.oykkkkkkkkkkyo.',
  '.oryyyyyyyyyyro.',
  '.oyyyyyyyyyyyyo.',
  '.oyttttttttttyo.',
  '..oyyyyyyyyyyo..',
  '...oko....oko...',
  '...oko....oko...',
  '...ooo....ooo...',
]

/**
 * The Wing plush: the toy in its winged armour, and the widest of the four.
 *
 * Same trick as the Vessel — no new palette slots. The armour is the lit metal the speaker and
 * the fridge are made of, the torso is the blue, the vents are the red, the fin is the yellow,
 * and the visor is the lit leaf green, which is the only green in the whole set and therefore
 * reads as a light rather than as foliage.
 */
const ESPURR_PLUSH_GUNDAM = [
  '................',
  '..o..........o..',
  '..op........po..',
  '..oppo....oppo..',
  '..opppo..opppo..',
  '..oMMMyyyyMMMo..',
  '.oMMMMMMMMMMMMo.',
  '.oMMpGGMMGGpMMo.',
  '.oMMMMMMMMMMMMo.',
  '.orbbbbbbbbbbro.',
  '.obbbbbbbbbbbbo.',
  '.oMbbbbbbbbbbMo.',
  '..oMMMMMMMMMMo..',
  '...oMo....oMo...',
  '...oMo....oMo...',
  '...ooo....ooo...',
]

/**
 * The Cubone Vessel plush: a skull the size of its own head, and a bone sewn to one hand.
 *
 * The only one of the four that isn't the same silhouette in a different colour, so it gets the
 * horns and a wider head. Palette as it stands: the paper white is the bone, the black is the
 * robe, the red is the sigil.
 */
const CUBONE_VESSEL_PLUSH = [
  '................',
  '.....o....o.....',
  '....opo..opo....',
  '....oppooppo....',
  '...opppppppppo..',
  '..opprppppprppo.',
  '..oppppprrppppo.',
  '..opkprppprkpo..',
  '..opppprrppppo..',
  '..pokkkkkkkkop..',
  '.ppokkkkkkkkopp.',
  '.ppokkkrrkkkopp.',
  '..pokkkkkkkkop..',
  '...okko..okko...',
  '...okko..okko...',
  '...ooo....ooo...',
]

/**
 * The Tanjiro plush: the toy in its chequered haori.
 *
 * The check is two greens off the existing palette — the leaf and its lit shade — laid two
 * pixels at a time, which is the smallest a check can be and still read as one. Above the collar
 * it is the plain Espurr plush, because that is what is wearing the coat.
 */
const ESPURR_PLUSH_TANJIRO = [
  '................',
  '..o..........o..',
  '..oa........ao..',
  '..oaao....oaao..',
  '..oaaao..oaaao..',
  '..ollllllllllo..',
  '.olllrllllllllo.',
  '.olvvvvvvvvvvlo.',
  '.olvVvllllvVvlo.',
  '.olvvvllllvvvlo.',
  '.ogGgGguugGgGgo.',
  '.oGgGgGgGgGgGgo.',
  '..ogGgGgGgGgGo..',
  '...ouo....ouo...',
  '...ouo....ouo...',
  '...ooo....ooo...',
]

// --- things that hang on a wall ---

const PAINTING = [
  '................',
  '................',
  '..oooooooooooo..',
  '..oyyyyyyyyyyo..',
  '..oybbbbbbbbyo..',
  '..oybbbbbbbbyo..',
  '..oybbGGbbbbyo..',
  '..oybGGGGbbbyo..',
  '..oybGGGGGGbyo..',
  '..oyggggggggyo..',
  '..oyyyyyyyyyyo..',
  '..oooooooooooo..',
  '................',
  '................',
  '................',
  '................',
]

const POSTER = [
  '................',
  '...oooooooooo...',
  '...oppppppppo...',
  '...oprrrrrrpo...',
  '...oppppppppo...',
  '...opbbppbbpo...',
  '...opppppppo....',
  '...oppyyyyppo...',
  '...oppppppppo...',
  '...oppkkkkppo...',
  '...oppppppppo...',
  '...oooooooooo...',
  '................',
  '................',
  '................',
  '................',
]

const WINDOW = [
  '................',
  '.oooooooooooooo.',
  '.owwwwwwwwwwwwo.',
  '.owossssossswo..',
  '.owosSSsossswo..',
  '.owossssossswo..',
  '.owwwwwwwwwwwwo.',
  '.owossssossswo..',
  '.owossssosSSwo..',
  '.owossssossswo..',
  '.owwwwwwwwwwwwo.',
  '.oooooooooooooo.',
  '................',
  '................',
  '................',
  '................',
]

const CLOCK = [
  '................',
  '................',
  '.....oooooo.....',
  '....oppppppo....',
  '...oppkppkppo...',
  '...opppkpppppo..',
  '...oppkkkppppo..',
  '...opppkppppo...',
  '...opppkkpppo...',
  '....oppppppo....',
  '.....oooooo.....',
  '................',
  '................',
  '................',
  '................',
  '................',
]

const SHELF = [
  '................',
  '................',
  '...ooo..........',
  '...oro..gg......',
  '...oro.oggo.....',
  '...oro.oggo..o..',
  '...obo.oggo.oyo.',
  '...obo.oggo.oyo.',
  '.oooooooooooooo.',
  '.owwwwwwwwwwwwo.',
  '.oooooooooooooo.',
  '................',
  '................',
  '................',
  '................',
  '................',
]

/*
 * The Side Desk's own apps, standing in the room.
 *
 * Drawn as the furniture you'd expect to find the thing on — a board on castors, a lectern with
 * the notes open on it, a planner on the wall, a cabinet of files — because the point is that
 * you recognise what pressing E will open before you press it.
 */

const WHITEBOARD = [
  '................................',
  '..oooooooooooooooooooooooooooo..',
  '..oMMMMMMMMMMMMMMMMMMMMMMMMMMo..',
  '..oMppppppppppppppppppppppppMo..',
  '..oMpprrppppppppbbppppppppppMo..',
  '..oMprpppppppppbppppppgggpppMo..',
  '..oMprpppppppppbpppppgpppppcMo..',
  '..oMpppppppppppppppppgggpppcMo..',
  '..oMppprrrrppppppppppppppppcMo..',
  '..oMpppppppppppppppppppppppcMo..',
  '..oMppppppppppppppppppppppppMo..',
  '..oMMMMMMMMMMMMMMMMMMMMMMMMMMo..',
  '..oooooooooooooooooooooooooooo..',
  '....MM....................MM....',
  '...MM......................MM...',
  '..oo........................oo..',
]

const LECTERN = [
  '................',
  '................',
  '....oooooooo....',
  '...opppppppppo..',
  '..oppwwppwwppo..',
  '..opwppwwppwpo..',
  '..opwppwwppwpo..',
  '..oppwwppwwppo..',
  '..ooooowwooooo..',
  '......owwo......',
  '......owwo......',
  '......owwo......',
  '.....owwwwo.....',
  '....owwwwwwo....',
  '...oWWWWWWWWo...',
  '...oooooooooo...',
]

const PLANNER = [
  '................',
  '.oooooooooooooo.',
  '.orrrrrrrrrrrro.',
  '.oppppppppppppo.',
  '.opkpkpkpkpkppo.',
  '.oppppppppppppo.',
  '.opkpkpkpkpkppo.',
  '.oppppppppppppo.',
  '.opkprpkpkpkppo.',
  '.oppppppppppppo.',
  '.opkpkpkpbpkppo.',
  '.oppppppppppppo.',
  '.oooooooooooooo.',
  '................',
  '................',
  '................',
]

const FILECABINET = [
  '................',
  '................',
  '..oooooooooooo..',
  '..oMMMMMMMMMMo..',
  '..omoooooooomo..',
  '..omoppppppomo..',
  '..omooMMMMoomo..',
  '..omoooooooomo..',
  '..omoppppppomo..',
  '..omooMMMMoomo..',
  '..omoooooooomo..',
  '..omoppppppomo..',
  '..omooMMMMoomo..',
  '..omoooooooomo..',
  '..oooooooooooo..',
  '..oo........oo..',
]

const NOTICEBOARD = [
  '................',
  '.oooooooooooooo.',
  '.onnnnnnnnnnnno.',
  '.onppnnnnppnnno.',
  '.onppnnnnppnnno.',
  '.onnnnnnnnnnnno.',
  '.onnnppnnnnppno.',
  '.onnnppnnnnppno.',
  '.onnnnnnnnnnnno.',
  '.onppnnnppnnnno.',
  '.onppnnnppnnnno.',
  '.onnnnnnnnnnnno.',
  '.oooooooooooooo.',
  '................',
  '................',
  '................',
]

/*
 * Side and back views.
 *
 * The pieces whose facing you can actually read, drawn from the two other angles a room
 * shows them at. A side view is authored in the *turned* footprint — a 2×1 couch's side is a
 * 1×2 grid — which is what lets it be drawn without rotating anything; see view().
 *
 * Everything not listed here still falls back to the old approximation, and mostly should:
 * a barrel, a plant and a rug have no front to turn away from.
 */

/** A couch turned side-on: the backrest runs down one long edge and the seat opens off it.
 * Authored 1×2, because that is the footprint a quarter turn gives a 2×1 couch. */
const COUCH_SIDE = [
  '................',
  '................',
  '.ooooooo........',
  '.occcccoooooooo.',
  '.occcccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.ocCCccoCCCCCCo.',
  '.occcccoCCCCCCo.',
  '.occcccoooooooo.',
  '.ooooooo....oo..',
  '..oo........oo..',
  '..oo........oo..',
]

/** The back of a couch — which is almost all backrest, with the cushion seam showing over the top. */
const COUCH_BACK = [
  '................................',
  '................................',
  '.oooooooooooooooooooooooooooooo.',
  '.occcccccccccccooccccccccccccco.',
  '.ocCCCCCCCCCCCCooCCCCCCCCCCCCco.',
  '.ocCCCCCCCCCCCCooCCCCCCCCCCCCco.',
  '.ocCCCCCCCCCCCCooCCCCCCCCCCCCco.',
  '.ocCCCCCCCCCCCCooCCCCCCCCCCCCco.',
  '.ocCCCCCCCCCCCCooCCCCCCCCCCCCco.',
  '.ocCCCCCCCCCCCCooCCCCCCCCCCCCco.',
  '.occcccccccccccooccccccccccccco.',
  '.occcccccccccccooccccccccccccco.',
  '.oooooooooooooooooooooooooooooo.',
  '...oo......................oo...',
  '...oo......................oo...',
  '...oo......................oo...',
]

/** A bench end-on: two rails and the seat between them. */
const BENCH_SIDE = [
  '................',
  '................',
  '..ooooooo.......',
  '..owwwwwooooooo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..ownnnwoWWWWWo.',
  '..owwwwwooooooo.',
  '..ooooooonno....',
  '........onno....',
  '........onno....',
]

/** A bench from behind: the two back rails, with daylight between them. */
const BENCH_BACK = [
  '................................',
  '................................',
  '................................',
  '....oooooooooooooooooooooooo....',
  '....oWWWWWWWWWWWWWWWWWWWWWWo....',
  '....oWWWWWWWWWWWWWWWWWWWWWWo....',
  '....oooooooooooooooooooooooo....',
  '................................',
  '....oooooooooooooooooooooooo....',
  '....owwwwwwwwwwwwwwwwwwwwwwo....',
  '....owwwwwwwwwwwwwwwwwwwwwwo....',
  '....oooooooooooooooooooooooo....',
  '.....nn..................nn.....',
  '.....nn..................nn.....',
  '.....nn..................nn.....',
  '.....nn..................nn.....',
]

/** A desk end-on: the top, and the drawer stack under one end of it. */
const DESK_SIDE = [
  '................',
  '................',
  '................',
  '..oooooooooooo..',
  '..oWWWWWWWWWWo..',
  '..oWWWWWWWWWWo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..oooooooooooo..',
  '...oooooooooo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...ownnnnnnwo...',
  '...ownnnnnnwo...',
  '...ownnnnnnwo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...ownnnnnnwo...',
  '...ownnnnnnwo...',
  '...ownnnnnnwo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...owwwwwwwwo...',
  '...oooooooooo...',
  '...nn......nn...',
  '...nn......nn...',
  '...nn......nn...',
  '...nn......nn...',
]

/** A desk from behind: a top and a modesty panel, no drawers. */
const DESK_BACK = [
  '................................',
  '................................',
  '................................',
  '....oooooooooooooooooooooooo....',
  '....oWWWWWWWWWWWWWWWWWWWWWWo....',
  '....oWWWWWWWWWWWWWWWWWWWWWWo....',
  '....oooooooooooooooooooooooo....',
  '.....oooooooooooooooooooooo.....',
  '.....owwwwwwwwwwwwwwwwwwwwo.....',
  '.....owwwwwwwwwwwwwwwwwwwwo.....',
  '.....owwwwwwwwwwwwwwwwwwwwo.....',
  '.....owwwwwwwwwwwwwwwwwwwwo.....',
  '.....oooooooooooooooooooooo.....',
  '.....nn..................nn.....',
  '.....nn..................nn.....',
  '.....nn..................nn.....',
]

/** A screen edge-on — a dark slab on a stand, which is all a television is from the side. */
const TV_SIDE = [
  '................',
  '................',
  '................',
  '................',
  '.....oooooo.....',
  '.....oddddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....okkddo.....',
  '.....oddddo.....',
  '.....oooooo.....',
  '.......oo.......',
  '.......oo.......',
  '.......oo.......',
  '....oooooooo....',
  '....okkkkkko....',
  '....oooooooo....',
  '................',
]

/** The back of the casing: vents where the picture was. */
const TV_BACK = [
  '................................',
  '....oooooooooooooooooooooooo....',
  '....oddddddddddddddddddddddo....',
  '....odddkkkkkkkkkkkkkkkkdddo....',
  '....oddddddddddddddddddddddo....',
  '....odddkkkkkkkkkkkkkkkkdddo....',
  '....oddddddddddddddddddddddo....',
  '....odddkkkkkkkkkkkkkkkkdddo....',
  '....oddddddddddddddddddddddo....',
  '....oddddddddddddddddddddddo....',
  '....oooooooooooooooooooooooo....',
  '..............oooo..............',
  '..............oooo..............',
  '..............oooo..............',
  '..........oooooooooooo..........',
  '..........oooooooooooo..........',
]

/** A monitor edge-on, on its desk. */
const COMPUTER_SIDE = [
  '................',
  '......oooo......',
  '......okko......',
  '......okko......',
  '......okko......',
  '......okko......',
  '......okko......',
  '......oooo......',
  '.......oo.......',
  '.......oo.......',
  '.....oooooo.....',
  '.....oooooo.....',
  '................',
  '..oooooooooooo..',
  '..oMMMMMMMMMMo..',
  '..oooooooooooo..',
]

/** The back of the panel and its stand. */
const COMPUTER_BACK = [
  '................',
  '...oooooooooo...',
  '...oddddddddo...',
  '...odkkkkkkdo...',
  '...odkkkkkkdo...',
  '...oddddddddo...',
  '...oooooooooo...',
  '.......oo.......',
  '.......oo.......',
  '.......oo.......',
  '.....oooooo.....',
  '.....oooooo.....',
  '................',
  '..oooooooooooo..',
  '..oMMMMMMMMMMo..',
  '..oooooooooooo..',
]

/** A chair from the side: the back, and the seat sticking out in front of it. */
const CHAIR_SIDE = [
  '................',
  '...oooo.........',
  '...oddo.........',
  '...oddo.........',
  '...oddo.........',
  '...oddo.........',
  '...oddooooooo...',
  '...oddodddddo...',
  '...oddodddddo...',
  '...oddooooooo...',
  '...oooo.........',
  '.......mm.......',
  '.......mm.......',
  '....oooooooo....',
  '....oMMMMMMo....',
  '....oooooooo....',
]

/** A chair from behind — the back of the backrest, which is most of a chair. */
const CHAIR_BACK = [
  '................',
  '...oooooooooo...',
  '...oddddddddo...',
  '...odmmmmmmdo...',
  '...odmmmmmmdo...',
  '...odmmmmmmdo...',
  '...oddddddddo...',
  '...oooooooooo...',
  '..oooooooooooo..',
  '..oddddddddddo..',
  '..oooooooooooo..',
  '.......mm.......',
  '.......mm.......',
  '...oooooooooo...',
  '...oMMMMMMMMo...',
  '...oooooooooo...',
]

/** A bookcase from the side: a plank on edge with the shelf ends showing. */
const BOOKSHELF_SIDE = [
  '.....oooooo.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....onnnno.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....onnnno.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....onnnno.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....onnnno.....',
  '.....owwwwo.....',
  '.....oooooo.....',
  '.....oo..oo.....',
]

/** Its back panel — plain, and the reason a bookcase turned around is a wall. */
const BOOKSHELF_BACK = [
  '..oooooooooooo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..onnnnnnnnnno..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..oooooooooooo..',
  '..oo........oo..',
]

/** A fridge from the side: narrow, with the door seam at its front edge. */
const FRIDGE_SIDE = [
  '.....oooooo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oMMMmo.....',
  '.....oooooo.....',
  '.....oo..oo.....',
]

/** A fridge from behind, coils and all. */
const FRIDGE_BACK = [
  '...oooooooooo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMmmmmmmMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMmmmmmmMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMmmmmmmMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oMMMMMMMMo...',
  '...oooooooooo...',
  '...oo......oo...',
]

/** A cabinet from the side. */
const CABINET_SIDE = [
  '................',
  '................',
  '.....oooooo.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....onnnno.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....owwwwo.....',
  '.....oooooo.....',
  '.....oo..oo.....',
  '.....oo..oo.....',
]

/** Its plain back panel. */
const CABINET_BACK = [
  '................',
  '................',
  '..oooooooooooo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..onnnnnnnnnno..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..owwwwwwwwwwo..',
  '..oooooooooooo..',
  '..oo........oo..',
  '..oo........oo..',
]


/*
 * Doors.
 *
 * Two states each, and the state is not a facing: a door is drawn shut or open depending on
 * whether somebody who may pass is standing near it *this frame*. So the open artwork is a
 * parallel set rather than another entry in the facing table — see `open` on DecorKind, and
 * lib/spaceDoors.ts for who decides.
 */

/** A door, shut: a frame, a panelled leaf, and a brass handle. */
const DOOR = [
  '.oooooooooooooo.',
  '.oooooooooooooo.',
  '.oowwwwwwwwwwoo.',
  '.oownnnnnnnnwoo.',
  '.oownnnnnnnnwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwyyoo.',
  '.oowwwwwwwwyyoo.',
  '.oownnnnnnnnwoo.',
  '.oownnnnnnnnwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oooooooooooooo.',
]

/** The same door, swung back against its jamb — the dark is the room beyond. */
const DOOR_OPEN = [
  '.oooooooooooooo.',
  '.ooookkkkkkkkko.',
  '.oowokkkkkkkkko.',
  '.oowokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oonokkkkkkkkko.',
  '.oowokkkkkkkkko.',
  '.oowokkkkkkkkko.',
  '.oowokkkkkkkkko.',
  '.oooooooooooooo.',
]

/** From behind. No handle on this face; the hinges are the tell. */
const DOOR_BACK = [
  '.oooooooooooooo.',
  '.oooooooooooooo.',
  '.oowwwwwwwwwwoo.',
  '.ommnnnnnnnnwoo.',
  '.ommnnnnnnnnwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oownnnnnnnnwoo.',
  '.oownnnnnnnnwoo.',
  '.ommwwwwwwwwwoo.',
  '.ommwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oowwwwwwwwwwoo.',
  '.oooooooooooooo.',
]

/** Open, from behind — the leaf folds the other way. */
const DOOR_OPEN_BACK = [
  '.oooooooooooooo.',
  '.okkkkkkkkkoooo.',
  '.okkkkkkkkkowoo.',
  '.okkkkkkkkkowoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkonoo.',
  '.okkkkkkkkkowoo.',
  '.okkkkkkkkkowoo.',
  '.okkkkkkkkkowoo.',
  '.oooooooooooooo.',
]

/** Edge-on: the frame in section with the leaf inside it. */
const DOOR_SIDE = [
  '.....oooooo.....',
  '.....oooooo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oooooo.....',
]

/** Edge-on and open, the leaf swung clear up the jamb. */
const DOOR_OPEN_SIDE = [
  '.....oooooo.....',
  '.....oooooo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oooooo.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....oooooo.....',
]

/** Double doors, shut, meeting in the middle. */
const GATE = [
  '.oooooooooooooooooooooooooooooo.',
  '.oooooooooooooooooooooooooooooo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oownnnnnnnnnnwoownnnnnnnnnnwoo.',
  '.oownnnnnnnnnnwoownnnnnnnnnnwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwyyyywwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwyyyywwwwwwwwwwwoo.',
  '.oownnnnnnnnnnwoownnnnnnnnnnwoo.',
  '.oownnnnnnnnnnwoownnnnnnnnnnwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oooooooooooooooooooooooooooooo.',
]

/** Both leaves folded back to their jambs. */
const GATE_OPEN = [
  '.oooooooooooooooooooooooooooooo.',
  '.oooookkkkkkkkkkkkkkkkkkkkooooo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oooooooooooooooooooooooooooooo.',
]

/** From behind, hinges at both edges. */
const GATE_BACK = [
  '.oooooooooooooooooooooooooooooo.',
  '.oooooooooooooooooooooooooooooo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.ommnnnnnnnnnnwoownnnnnnnnnnmmo.',
  '.ommnnnnnnnnnnwoownnnnnnnnnnmmo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oownnnnnnnnnnwoownnnnnnnnnnwoo.',
  '.oownnnnnnnnnnwoownnnnnnnnnnwoo.',
  '.ommwwwwwwwwwwwoowwwwwwwwwwwmmo.',
  '.ommwwwwwwwwwwwoowwwwwwwwwwwmmo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oowwwwwwwwwwwwoowwwwwwwwwwwwoo.',
  '.oooooooooooooooooooooooooooooo.',
]

/** Open from behind — the leaves are folded away, so it reads the same either side. */
const GATE_OPEN_BACK = [
  '.oooooooooooooooooooooooooooooo.',
  '.oooookkkkkkkkkkkkkkkkkkkkooooo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oonnokkkkkkkkkkkkkkkkkkkkonnoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oowwokkkkkkkkkkkkkkkkkkkkowwoo.',
  '.oooooooooooooooooooooooooooooo.',
]

/** Turned: a 2x1 gate is 1x2, so this is 16 wide and 32 tall. */
const GATE_SIDE = [
  '.....oooooo.....',
  '.....oooooo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oooooo.....',
  '.....oooooo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oonnoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oooooo.....',
  '.....oooooo.....',
]

/** The same, standing open. */
const GATE_OPEN_SIDE = [
  '.....oooooo.....',
  '.....oooooo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oooooo.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....okkkko.....',
  '.....oooooo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oowwoo.....',
  '.....oooooo.....',
  '.....oooooo.....',
]

/**
 * Every kind, keyed exactly as the server's catalogue is.
 *
 * `art` is null for the two flat pieces, which are drawn as rectangles instead — a rug is a
 * pattern, not a picture, and generating it means one entry rather than a thirty-two row grid.
 * `side` and `back` are the extra angles, and are optional: a piece without them falls back to
 * the approximation in pose().
 */
export const DECOR: Record<string, DecorKind & { art: string[] | string[][] | null }> = {
  speaker: kind('Speaker', { interact: 'music', verb: 'Put something on', art: SPEAKER }),
  tv: kind('TV', { w: 2, interact: 'video', verb: 'Watch something', art: TV, side: TV_SIDE, back: TV_BACK }),
  computer: kind('Computer', { interact: 'kanban', verb: 'Check the board', art: COMPUTER, side: COMPUTER_SIDE, back: COMPUTER_BACK }),
  arcade: kind('Arcade cabinet', { interact: 'shooter', verb: 'Play', art: ARCADE }),
  racer: kind('Racing cabinet', { interact: 'racing', verb: 'Race', art: RACER }),
  easel: kind('Easel', { interact: 'skribbl', verb: 'Draw', art: EASEL }),
  noticeboard: kind('Notice board', { mount: 'wall', interact: 'poll', verb: 'Read the board', art: NOTICEBOARD }),

  // The surface apps: no widget behind them, so pressing E floats the app itself. The board in
  // the corner is the channel's Board tab, which is the whole point of putting one here.
  whiteboard: kind('Whiteboard', { w: 2, interact: 'board', verb: 'Draw on it', art: WHITEBOARD }),
  lectern: kind('Lectern', { interact: 'notes', verb: 'Read the notes', art: LECTERN }),
  planner: kind('Wall planner', { mount: 'wall', interact: 'calendar', verb: 'Check the schedule', art: PLANNER }),
  filecabinet: kind('Filing cabinet', { interact: 'docs', verb: 'Look through the files', art: FILECABINET }),

  // Doors. Solid in the catalogue and opened by the room — see `door` above.
  door: kind('Door', {
    door: true,
    art: DOOR,
    side: DOOR_SIDE,
    back: DOOR_BACK,
    open: { art: DOOR_OPEN, side: DOOR_OPEN_SIDE, back: DOOR_OPEN_BACK },
  }),
  gate: kind('Gate', {
    w: 2,
    door: true,
    art: GATE,
    side: GATE_SIDE,
    back: GATE_BACK,
    open: { art: GATE_OPEN, side: GATE_OPEN_SIDE, back: GATE_OPEN_BACK },
  }),

  desk: kind('Desk', { w: 2, art: DESK, side: DESK_SIDE, back: DESK_BACK }),
  // The seats. A two-tile couch is two seats, because its footprint is what you sit on — see
  // seatOn(). A chair and a stool are walk-on-able already; sitting on one is what stops you
  // standing *in* it looking like a person who has been impaled on their own furniture.
  couch: kind('Couch', { w: 2, seat: true, art: COUCH, side: COUCH_SIDE, back: COUCH_BACK }),
  bench: kind('Bench', { w: 2, seat: true, art: BENCH, side: BENCH_SIDE, back: BENCH_BACK }),
  chair: kind('Office chair', { solid: false, seat: true, art: CHAIR, side: CHAIR_SIDE, back: CHAIR_BACK }),
  stool: kind('Stool', { solid: false, seat: true, art: STOOL }),
  // Walk-on-able like the other single seats, for the same reason: a throne you had to stand
  // *beside* to sit on would put you next to the crown rather than on it. Two tiles square, so
  // that sitting on it doesn't hide it — see the note on the artwork.
  throne: kind('Iron throne', {
    w: 2,
    h: 2,
    solid: false,
    seat: true,
    oneSeat: true,
    /*
     * Drawn from the real artwork when it's there — a throne of a thousand swords is exactly
     * the sort of thing a 32×32 grid of letters can only gesture at.
     *
     * 2.8 tiles tall against a two-tile footprint. The height is picked so the *width* that
     * follows from the artwork's own 458×623 comes out at almost exactly two tiles — it fills
     * its floor space rather than overhanging it — and the extra 0.8 goes up, which puts the
     * crown of blades above the back of the seat and over the head of whoever is sitting there.
     */
    still: { src: 'decor/iron-throne.png', scale: 2.8 },
    art: THRONE,
    back: THRONE_BACK,
  }),
  bookshelf: kind('Bookshelf', { art: BOOKSHELF, side: BOOKSHELF_SIDE, back: BOOKSHELF_BACK }),
  cabinet: kind('Cabinet', { art: CABINET, side: CABINET_SIDE, back: CABINET_BACK }),
  fridge: kind('Fridge', { art: FRIDGE, side: FRIDGE_SIDE, back: FRIDGE_BACK }),
  watercooler: kind('Water cooler', { art: WATERCOOLER }),
  lamp: kind('Floor lamp', { art: LAMP }),
  plant: kind('Potted plant', { art: PLANT }),
  /*
   * Soft, and therefore not solid: a toy on the floor is something you step over, not a bollard.
   *
   * Drawn from the pet's own Idle sheet, so the plush on the shelf and the creature trotting
   * after you are the *same artwork* — which is the whole joke, and something no second drawing
   * of the same character could pull off. It keeps breathing where a toy wouldn't, and that is
   * the better answer at this size: a plush that never moves is indistinguishable from a bug.
   */
  plush: kind('Espurr plush', {
    solid: false,
    sheet: { name: 'espurr/Idle', columns: 4, scale: 1.5 },
    art: ESPURR_PLUSH,
  }),
  // The other two outfits, as toys. Three kinds rather than one kind with a variant, because
  // that's how every other piece of furniture in here works and a room's objects store nothing
  // but a kind and a place — a variant field would be a second way to say the same thing.
  plush_vessel: kind('Espurr Vessel plush', {
    solid: false,
    sheet: { name: 'espurr-vessel/Idle', columns: 4, scale: 1.5 },
    art: ESPURR_PLUSH_VESSEL,
  }),
  plush_pickachu: kind('Espurr Pikachu plush', {
    solid: false,
    sheet: { name: 'espurr-pickachu/Idle', columns: 4, scale: 1.5 },
    art: ESPURR_PLUSH_PICKACHU,
  }),
  // Taller than the other three at the same footprint: that sheet's frames carry a lot of empty
  // air above and below the artwork, so 1.5 would draw a model kit the size of a keyring.
  plush_gundam: kind('Espurr Wing plush', {
    solid: false,
    sheet: { name: 'espurr-winged-gundam/Idle', columns: 4, scale: 1.9 },
    art: ESPURR_PLUSH_GUNDAM,
  }),
  plush_cubone: kind('Cubone Vessel plush', {
    solid: false,
    sheet: { name: 'cubone-vessel/Idle', columns: 4, scale: 1.5 },
    art: CUBONE_VESSEL_PLUSH,
  }),
  plush_tanjiro: kind('Espurr Tanjiro plush', {
    solid: false,
    sheet: { name: 'espurr-tanjiro/Idle', columns: 4, scale: 1.5 },
    art: ESPURR_PLUSH_TANJIRO,
  }),
  crate: kind('Crate', { art: CRATE }),
  barrel: kind('Barrel', { art: BARREL }),
  campfire: kind('Campfire', { art: CAMPFIRE }),

  pillar: kind('Pillar', { art: PILLAR }),
  statue: kind('Statue', { art: STATUE }),
  torch: kind('Torch', { art: TORCH }),
  boulder: kind('Boulder', { art: BOULDER }),

  rug: kind('Rug', { w: 2, h: 2, solid: false, lift: false, art: null }),
  mat: kind('Door mat', { solid: false, lift: false, art: null }),

  painting: kind('Painting', { mount: 'wall', art: PAINTING }),
  poster: kind('Poster', { mount: 'wall', art: POSTER }),
  window: kind('Window', { mount: 'wall', art: WINDOW }),
  clock: kind('Clock', { mount: 'wall', art: CLOCK }),
  shelf: kind('Wall shelf', { mount: 'wall', art: SHELF }),

}

/** The defaults, spelled out once instead of twenty-six times. */
function kind(
  label: string,
  over: Partial<DecorKind> & { art: string[] | string[][] | null },
): DecorKind & { art: string[] | string[][] | null } {
  const mount = over.mount ?? 'floor'

  return {
    label,
    w: over.w ?? 1,
    h: over.h ?? 1,
    // Wall-mounted things are never solid: the wall they hang on already is, and a painting
    // that blocked the tile in front of it would fence off the room it decorates.
    solid: mount === 'wall' ? false : (over.solid ?? true),
    mount,
    // Wall pieces are flush with their wall; everything else stands up unless told otherwise.
    lift: over.lift ?? mount !== 'wall',
    side: over.side ?? null,
    back: over.back ?? null,
    door: over.door ?? false,
    open: over.open ?? null,
    interact: over.interact ?? null,
    verb: over.verb ?? null,
    seat: over.seat ?? false,
    oneSeat: over.oneSeat ?? false,
    sheet: over.sheet ?? null,
    still: over.still ?? null,
    art: over.art,
  }
}

export function decorKind(kindName: string): (DecorKind & { art: string[] | string[][] | null }) | null {
  return DECOR[kindName] ?? null
}

/**
 * How much floor a piece takes up *as placed* — its catalogue size, turned.
 *
 * The single place the quarter turn is applied, mirroring `Decorations::size()` on the server. A
 * piece turned an odd number of quarters trades its width for its height; a half turn changes
 * which way it looks and nothing about the space it needs.
 */
export function decorSize(object: SpaceObject, kind: DecorKind): { w: number, h: number } {
  return TURNS[object.facing ?? 'down'] % 2 === 1
    ? { w: kind.h, h: kind.w }
    : { w: kind.w, h: kind.h }
}

/** Every tile a piece covers — what the collision check and the editor's hit test both ask. */
export function decorCovers(object: SpaceObject, kind: DecorKind, x: number, y: number): boolean {
  const { w, h } = decorSize(object, kind)

  return x >= object.x && x < object.x + w && y >= object.y && y < object.y + h
}

/**
 * Which picture a facing calls for, and in what box.
 *
 * The first question, asked before {@link pose} gets a look in. A piece that *has* art for the
 * way it's turned simply uses it — no rotation, no mirroring of a front view, no fake at all —
 * and that is what makes a couch turned to face the wall look like the back of a couch instead
 * of a couch lying on its side.
 *
 * Note the box: a side view is authored in the footprint the turn produces, so a 2×1 desk's side
 * is a 1×2 grid. That's the whole reason it needs no rotating — it was drawn turned.
 *
 * Everything without the extra artwork falls through to {@link pose}, which is the older
 * approximation and still the right answer for the pieces it covers: a rug has no front, and a
 * potted plant looks the same from every side a room can see it from.
 */
function view(
  kind: DecorKind & { art: string[] | string[][] | null },
  facing: DecorFacing,
  frames: string[][],
  frame: number,
  isOpen = false,
): { rows: string[], w: number, h: number, mirror: boolean, rotate: number, key: string } {
  const turns = TURNS[facing]

  /*
   * A door standing open swaps in a whole parallel set of views — front, side and back — rather
   * than being the shut one shifted or hidden. It has to: what you see through an open doorway
   * is the *next room*, and a door drawn as "absent" reads as a hole in the wall rather than as
   * a way through. The three facings are chosen exactly as below, off the same turn.
   */
  if (isOpen && kind.open) {
    const shape = kind.open

    if (turns === 2 && shape.back) {
      return { rows: shape.back, w: kind.w, h: kind.h, mirror: false, rotate: 0, key: 'open-back' }
    }

    if (turns % 2 === 1 && shape.side) {
      return { rows: shape.side, w: kind.h, h: kind.w, mirror: turns === 1, rotate: 0, key: 'open-side' }
    }

    return { rows: shape.art, w: kind.w, h: kind.h, mirror: turns === 2, rotate: 0, key: 'open' }
  }

  if (turns === 0) {
    return { rows: frames[frame]!, w: kind.w, h: kind.h, mirror: false, rotate: 0, key: `f${frame}` }
  }

  if (turns === 2 && kind.back) {
    return { rows: kind.back, w: kind.w, h: kind.h, mirror: false, rotate: 0, key: 'back' }
  }

  if (turns % 2 === 1 && kind.side) {
    // Authored facing right; facing left is the same piece seen from its other side.
    return { rows: kind.side, w: kind.h, h: kind.w, mirror: turns === 1, rotate: 0, key: 'side' }
  }

  const { rotate, mirror } = pose(kind, facing)

  return { rows: frames[frame]!, w: kind.w, h: kind.h, mirror, rotate, key: `f${frame}|${rotate}${mirror ? 'm' : ''}` }
}

/**
 * How a turn is *drawn* when there is no artwork for it — the fallback behind {@link view}.
 *
 * Every sprite is authored as a front view, so a turn has to be faked from it, and the honest
 * fake differs by what the piece is:
 *
 *   - **Flat pieces** (a rug, a mat) lie on the floor and have no front. Every turn is a true
 *     rotation, because that is literally what turning a rug does.
 *   - **A standing piece with a long axis** (a desk, a couch, the whiteboard) turned a quarter is
 *     showing its side, and rotating the raster reads exactly that way — the piece now runs
 *     north-south, which is what somebody rotating a couch wanted to see.
 *   - **A half turn** shows a piece's *back*, and no sprite has one. So it mirrors rather than
 *     inverts: an upside-down couch is never what anybody meant, and a mirrored one at least
 *     reads as the same couch turned around.
 *   - **A square standing piece** (a chair, a lamp) has no long axis to swing, so a quarter turn
 *     mirrors too. Rotating it would lay a floor lamp on its side to no purpose.
 *
 * The *footprint* turns in every one of those cases — see {@link decorSize}. Only the picture
 * is negotiable, because only the picture is missing information.
 */
function pose(kind: DecorKind, facing: DecorFacing): { rotate: number, mirror: boolean } {
  const turns = TURNS[facing]

  if (!kind.lift) return { rotate: turns, mirror: false }
  if (turns === 2) return { rotate: 0, mirror: true }
  if (turns % 2 === 1) {
    return kind.w === kind.h
      ? { rotate: 0, mirror: turns === 1 }
      : { rotate: turns, mirror: false }
  }

  return { rotate: 0, mirror: false }
}

/**
 * What's where, worked out once per version of the room.
 *
 * The questions the walk loop and the renderer ask — "what covers this tile", "what is on this
 * row", "which of these are doors" — were all plain scans of the whole furniture list, which was
 * the right answer while a room held at most a hundred pieces. A big office holds ten times that,
 * and a scan per walkability check (several per step, per person, per pet) and a filter-and-sort
 * per frame both grow with it. So the list is turned into three lookups, and every hot question
 * becomes proportional to the answer instead of to the room.
 *
 * Note what is *not* in here: `open`. A door's state changes every frame ({@see syncDoors}) and
 * is read off the object itself at query time, so the index holds references and never goes stale
 * for the one property that actually moves. Everything it does store — position, kind, facing —
 * only changes when the map is saved.
 */
interface DecorIndex {
  /** Tile → the pieces covering it, in map order. Unknown kinds are left out; nothing can ask. */
  at: Map<number, SpaceObject[]>
  /** Origin row → the pieces starting on it, in map order. Everything, drawable or not. */
  rows: Map<number, SpaceObject[]>
  /** The doors, for the once-a-frame sweep that opens them. */
  doors: SpaceObject[]
}

/**
 * Rebuilt when the array identity changes, which is exactly when the room changes: the editor
 * replaces `objects` wholesale on every edit rather than mutating it, and a refetched map is a
 * new array by construction. Weak, so a room nobody is standing in is not a room being kept
 * alive by its own index.
 */
const INDEXES = new WeakMap<SpaceObject[], DecorIndex>()

/** Tiles are 0..{@link SideSpaceMap::MAX_SIZE} on both axes, so this packs without collision. */
const ROW_STRIDE = 8192

function indexOf(objects: SpaceObject[] | undefined): DecorIndex | null {
  if (!objects?.length) return null

  const cached = INDEXES.get(objects)
  if (cached) return cached

  const index: DecorIndex = { at: new Map(), rows: new Map(), doors: [] }

  for (const object of objects) {
    const row = index.rows.get(object.y)
    if (row) row.push(object)
    else index.rows.set(object.y, [object])

    const kind = DECOR[object.kind]
    if (!kind) continue
    if (kind.door) index.doors.push(object)

    const { w, h } = decorSize(object, kind)
    for (let dy = 0; dy < h; dy++) {
      for (let dx = 0; dx < w; dx++) {
        const key = (object.y + dy) * ROW_STRIDE + (object.x + dx)
        const here = index.at.get(key)
        if (here) here.push(object)
        else index.at.set(key, [object])
      }
    }
  }

  INDEXES.set(objects, index)

  return index
}

/** The pieces covering a tile, nearest thing to a free lookup. Empty for open floor. */
export function decorAt(objects: SpaceObject[] | undefined, x: number, y: number): SpaceObject[] {
  return indexOf(objects)?.at.get(y * ROW_STRIDE + x) ?? []
}

/** The pieces whose origin sits on row `y`, in map order — what the renderer draws, row by row. */
export function decorRow(objects: SpaceObject[] | undefined, y: number): SpaceObject[] {
  return indexOf(objects)?.rows.get(y) ?? []
}

/** Every door in the room. */
export function decorDoors(objects: SpaceObject[] | undefined): SpaceObject[] {
  return indexOf(objects)?.doors ?? []
}

/**
 * Is a tile blocked by furniture?
 *
 * Called from the walk loop for every step, and answered from {@link decorAt} — the handful of
 * pieces on that one tile, not the whole room.
 */
export function decorBlocks(objects: SpaceObject[] | undefined, x: number, y: number): boolean {
  for (const object of decorAt(objects, x, y)) {
    const kind = DECOR[object.kind]
    if (!kind?.solid) continue
    // An open door is a doorway. Anything that hasn't been told otherwise — the editor, the
    // spawn search, a map being validated — sees every door shut, which is the safe reading.
    if (kind.door && object.open) continue

    return true
  }

  return false
}

/**
 * The piece somebody standing at `x, y` and facing `facing` would be using.
 *
 * Deliberately generous: the tile in front of you *or* the one you're on, because a chair or a
 * rug is walked onto rather than up to, and because a two-tile TV is easier to find from either
 * end than from the exact square its origin happens to sit in.
 *
 * `wants` says what counts as usable — something that opens an app, by default, or somewhere to
 * sit. Both questions are asked of the same tiles in the same order, so a couch you can sit on
 * and a TV you can watch are found by the identical reach; only the test differs.
 */
export function decorInFront(
  objects: SpaceObject[] | undefined,
  at: { x: number, y: number, facing: string },
  wants: (kind: DecorKind) => boolean = kind => !!kind.interact,
): SpaceObject | null {
  const x = Math.round(at.x)
  const y = Math.round(at.y)
  const ahead = {
    up: [x, y - 1],
    down: [x, y + 1],
    left: [x - 1, y],
    right: [x + 1, y],
  }[at.facing] ?? [x, y]

  const candidates: Array<[number, number]> = [[ahead[0]!, ahead[1]!], [x, y]]

  for (const [cx, cy] of candidates) {
    for (const object of decorAt(objects, cx, cy)) {
      const kind = DECOR[object.kind]
      if (kind && wants(kind)) return object
    }
  }

  return null
}

/** The seat somebody at `at` could drop into, if there is one within reach. */
export function seatInFront(
  objects: SpaceObject[] | undefined,
  at: { x: number, y: number, facing: string },
): SpaceObject | null {
  return decorInFront(objects, at, kind => kind.seat)
}

/**
 * Where on a seat to sit, and which way you end up facing.
 *
 * A bench is two or three seats, not one, so the tile chosen is the one nearest whoever is
 * sitting down — walk to the far end of a couch and you sit at the far end of it. Facing comes
 * from the *piece*, not from the sitter: furniture is drawn with a front, and somebody sitting
 * on a couch faces the way the couch does, whatever direction they happened to approach from.
 *
 * A {@link DecorKind.oneSeat} piece breaks the first half of that rule and not the second. It's
 * one seat however many tiles it covers, so you're centred **across** it — on the half-tile, if
 * it's an even number of tiles wide, which positions allow for because they were always
 * fractional — and put on its **front** row along the depth, so you're sitting in the seat with
 * the back of it behind you rather than buried in the middle of the piece. Both axes are read
 * off the facing, so a throne turned to face left seats you on its left-hand column.
 */
export function seatOn(
  object: SpaceObject,
  kind: DecorKind,
  from: { x: number, y: number },
): { x: number, y: number, facing: DecorFacing } {
  const { w, h } = decorSize(object, kind)
  const facing = object.facing ?? 'down'

  if (kind.oneSeat) {
    // The middle of each axis, and then the front edge of whichever one is the depth. On an
    // odd-sized piece the two agree and this is simply the centre tile.
    const mid = { x: object.x + (w - 1) / 2, y: object.y + (h - 1) / 2 }
    const front = {
      down: { y: object.y + h - 1 },
      up: { y: object.y },
      right: { x: object.x + w - 1 },
      left: { x: object.x },
    }[facing]

    return { ...mid, ...front, facing }
  }

  const x = Math.min(object.x + w - 1, Math.max(object.x, Math.round(from.x)))
  const y = Math.min(object.y + h - 1, Math.max(object.y, Math.round(from.y)))

  return { x, y, facing }
}

// --- drawing ---

/**
 * Paint one piece of furniture.
 *
 * `px`/`py` is the *top-left corner of its footprint* in canvas pixels; `size` is one tile. A
 * lifted piece is drawn a third taller than its footprint and hung from the bottom edge, so the
 * extra height goes *up* — which is what makes a bookshelf look like it's against the wall
 * behind it rather than lying on the floor in front of it.
 */
export function drawDecor(
  ctx: CanvasRenderingContext2D,
  object: SpaceObject,
  px: number,
  py: number,
  size: number,
  t: number,
): void {
  const kind = DECOR[object.kind]
  if (!kind) return

  // Two rectangles, and the difference between them is the whole of rotation. The *placed*
  // footprint is what the piece occupies on the floor (turned, so possibly w and h swapped);
  // the *authored* one is the box the sprite was drawn in. Rotating about the shared centre
  // takes one to the other.
  const placed = decorSize(object, kind)
  const pw = placed.w * size
  const ph = placed.h * size

  /*
   * A hand-drawn still wins over everything: one picture, no frames, no facing.
   *
   * It ignores the turn deliberately. A throne drawn from the front and mirrored for its side
   * would be a throne facing you from every angle, which is what {@link pose} does for square
   * standing pieces anyway — and for a piece this symmetrical it's the right answer rather than
   * a compromise. If a still ever needs four views it becomes a sheet, which is what sheets are.
   */
  if (kind.still && drawStill(ctx, kind.still, px + pw / 2, py + ph, size)) return

  /*
   * Real artwork wins, when there is any.
   *
   * Anchored at the bottom centre of the footprint, exactly as a person and a pet are, so a
   * piece drawn from a sheet stands on its tiles rather than floating over them. The frame
   * clock is the shared one every other animation in the room runs on, at the pets' own rate —
   * a shelf of plushes should breathe together rather than each on its own phase.
   */
  if (kind.sheet && sheetReady(kind.sheet)) {
    drawSheetFrame(
      ctx,
      kind.sheet,
      Math.floor(t * 6),
      sheetRow(object.facing ?? 'down'),
      px + pw / 2,
      py + ph,
      size,
    )

    return
  }

  if (kind.art === null) return flat(ctx, object, px, py, size, pw, ph)

  // A two-frame piece animates on a half-second clock; everything else has one frame.
  const frames = Array.isArray(kind.art[0]) ? (kind.art as string[][]) : [kind.art as string[]]
  const frame = frames.length > 1 ? Math.floor(t * 2) % frames.length : 0

  const chosen = view(kind, object.facing ?? 'down', frames, frame, object.open === true)
  // The box the *chosen picture* was drawn in, which for a side view is the turned footprint
  // and for everything else is the catalogue one.
  const w = chosen.w * size
  const h = chosen.h * size

  const canvas = sprite(`decor|${object.kind}|${chosen.key}`, chosen.w * 16, chosen.h * 16, [
    { rows: chosen.rows, palette: P },
  ])

  const drawn = kind.lift ? h * 1.34 : h

  // A soft ellipse under anything that stands up, for the same reason a person gets one: a
  // sprite anchored at its base needs something to stand on or it reads as pasted over the
  // floor. Drawn in world space, under the *placed* footprint, so a turned piece's shadow turns
  // with it rather than pointing the way the art was authored.
  if (kind.lift) {
    ctx.beginPath()
    ctx.ellipse(px + pw / 2, py + ph - size * 0.12, pw * 0.36, size * 0.12, 0, 0, Math.PI * 2)
    ctx.fillStyle = 'rgb(0 0 0 / 0.15)'
    ctx.fill()
  }

  const { rotate, mirror } = chosen

  // No rotation to do: the picture is already in the footprint's shape, so it's blitted straight
  // into it. True of every front view, every back view and every side view — the rotated branch
  // below is only ever reached by the pieces with no art for the way they're turned.
  if (rotate === 0) return blit(ctx, canvas, px + pw / 2, py + ph, w, drawn, mirror)

  // Turn the world about the middle of the footprint, then draw the piece as though nothing had
  // happened: inside the rotated frame the sprite is upright in its authored box, whose bottom
  // edge is half its authored height below the centre.
  ctx.save()
  ctx.translate(px + pw / 2, py + ph / 2)
  ctx.rotate((rotate * Math.PI) / 2)
  blit(ctx, canvas, 0, h / 2, w, drawn, mirror)
  ctx.restore()
}

/**
 * Rugs and mats: woven rectangles rather than pictures.
 *
 * Takes its size from the *placed* footprint rather than the catalogue, which is all a rug needs
 * to rotate: a woven rectangle turned ninety degrees is a woven rectangle.
 */
function flat(
  ctx: CanvasRenderingContext2D,
  object: SpaceObject,
  px: number,
  py: number,
  size: number,
  w: number,
  h: number,
): void {
  const border = Math.max(2, size * 0.09)

  ctx.fillStyle = object.kind === 'mat' ? '#6f6357' : '#8c5b63'
  ctx.fillRect(px, py, w, h)

  ctx.fillStyle = object.kind === 'mat' ? '#8a7c6c' : '#b5808a'
  ctx.fillRect(px + border, py + border, w - border * 2, h - border * 2)

  ctx.fillStyle = object.kind === 'mat' ? '#6f6357' : '#d7b3a6'
  ctx.fillRect(px + border * 2.2, py + border * 2.2, w - border * 4.4, h - border * 4.4)

  // A few woven flecks, hashed from where the rug is so it doesn't shimmer.
  ctx.fillStyle = 'rgb(0 0 0 / 0.12)'
  for (let i = 0; i < 6; i++) {
    const a = tileNoise(object.x, object.y, i)
    const b = tileNoise(object.y, object.x, i)
    ctx.fillRect(px + border + a * (w - border * 2), py + border + b * (h - border * 2), size * 0.08, size * 0.08)
  }
}
