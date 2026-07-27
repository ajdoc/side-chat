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
import { blit, sprite, tileNoise } from './pixelSprite'

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
  /** The Side Desk app pressing E opens, if any. */
  interact: SideDeskAppId | null
  /** What the prompt says you'd be doing. */
  verb: string | null
}

/** A decoration as it's stored: a kind, a place, and which way it's turned. */
export interface SpaceObject {
  id: string
  kind: string
  x: number
  y: number
  /** Absent on everything placed before pieces could be turned, and read as `down`. */
  facing?: DecorFacing
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

/**
 * Every kind, keyed exactly as the server's catalogue is.
 *
 * `art` is null for the two flat pieces, which are drawn as rectangles instead — a rug is a
 * pattern, not a picture, and generating it means one entry rather than a thirty-two row grid.
 */
export const DECOR: Record<string, DecorKind & { art: string[] | string[][] | null }> = {
  speaker: kind('Speaker', { interact: 'music', verb: 'Put something on', art: SPEAKER }),
  tv: kind('TV', { w: 2, interact: 'video', verb: 'Watch something', art: TV }),
  computer: kind('Computer', { interact: 'kanban', verb: 'Check the board', art: COMPUTER }),
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

  desk: kind('Desk', { w: 2, art: DESK }),
  couch: kind('Couch', { w: 2, art: COUCH }),
  bench: kind('Bench', { w: 2, art: BENCH }),
  chair: kind('Office chair', { solid: false, art: CHAIR }),
  stool: kind('Stool', { solid: false, art: STOOL }),
  bookshelf: kind('Bookshelf', { art: BOOKSHELF }),
  cabinet: kind('Cabinet', { art: CABINET }),
  fridge: kind('Fridge', { art: FRIDGE }),
  watercooler: kind('Water cooler', { art: WATERCOOLER }),
  lamp: kind('Floor lamp', { art: LAMP }),
  plant: kind('Potted plant', { art: PLANT }),
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
    interact: over.interact ?? null,
    verb: over.verb ?? null,
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
 * How a turn is *drawn*, which is not simply "rotate the picture".
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
 * Is a tile blocked by furniture?
 *
 * Called from the walk loop for every step, so it's a plain loop over a list that is at most a
 * hundred long rather than anything cleverer — an index would need rebuilding whenever the room
 * was rebuilt, and the room is rebuilt more often than this is slow.
 */
export function decorBlocks(objects: SpaceObject[] | undefined, x: number, y: number): boolean {
  for (const object of objects ?? []) {
    const kind = DECOR[object.kind]
    if (kind?.solid && decorCovers(object, kind, x, y)) return true
  }

  return false
}

/**
 * The piece somebody standing at `x, y` and facing `facing` would be using.
 *
 * Deliberately generous: the tile in front of you *or* the one you're on, because a chair or a
 * rug is walked onto rather than up to, and because a two-tile TV is easier to find from either
 * end than from the exact square its origin happens to sit in.
 */
export function decorInFront(
  objects: SpaceObject[] | undefined,
  at: { x: number, y: number, facing: string },
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
    for (const object of objects ?? []) {
      const kind = DECOR[object.kind]
      if (kind?.interact && decorCovers(object, kind, cx, cy)) return object
    }
  }

  return null
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
  const w = kind.w * size
  const h = kind.h * size

  if (kind.art === null) return flat(ctx, object, px, py, size, pw, ph)

  // A two-frame piece animates on a half-second clock; everything else has one frame.
  const frames = Array.isArray(kind.art[0]) ? (kind.art as string[][]) : [kind.art as string[]]
  const frame = frames.length > 1 ? Math.floor(t * 2) % frames.length : 0

  const canvas = sprite(`decor|${object.kind}|${frame}`, kind.w * 16, kind.h * 16, [
    { rows: frames[frame]!, palette: P },
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

  const { rotate, mirror } = pose(kind, object.facing ?? 'down')

  if (rotate === 0) return blit(ctx, canvas, px + pw / 2, py + ph, pw, drawn, mirror)

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
