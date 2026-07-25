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
 * `interact` says which widget pressing E opens, and is here *only* to draw the prompt. The
 * server decides what actually opens, from its own copy of the map — see the interact endpoint.
 * Keeping a copy here means the prompt can say "Put something on" the instant you step up to
 * the speaker rather than a round trip later.
 */

import type { Widget } from '~/types'
import { blit, sprite, tileNoise } from './pixelSprite'

export type DecorMount = 'floor' | 'wall'

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
  /** The widget pressing E opens, if any. */
  interact: Widget['type'] | null
  /** What the prompt says you'd be doing. */
  verb: string | null
}

/** A decoration as it's stored: a kind and a place, and nothing else. */
export interface SpaceObject {
  id: string
  kind: string
  x: number
  y: number
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

/** Every tile a piece covers — what the collision check and the editor's eraser both ask. */
export function decorCovers(object: SpaceObject, kind: DecorKind, x: number, y: number): boolean {
  return x >= object.x && x < object.x + kind.w && y >= object.y && y < object.y + kind.h
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

  const w = kind.w * size
  const h = kind.h * size

  if (kind.art === null) return flat(ctx, object, kind, px, py, size)

  // A two-frame piece animates on a half-second clock; everything else has one frame.
  const frames = Array.isArray(kind.art[0]) ? (kind.art as string[][]) : [kind.art as string[]]
  const frame = frames.length > 1 ? Math.floor(t * 2) % frames.length : 0

  const canvas = sprite(`decor|${object.kind}|${frame}`, kind.w * 16, kind.h * 16, [
    { rows: frames[frame]!, palette: P },
  ])

  const drawn = kind.lift ? h * 1.34 : h

  // A soft ellipse under anything that stands up, for the same reason a person gets one: a
  // sprite anchored at its base needs something to stand on or it reads as pasted over the floor.
  if (kind.lift) {
    ctx.beginPath()
    ctx.ellipse(px + w / 2, py + h - size * 0.12, w * 0.36, size * 0.12, 0, 0, Math.PI * 2)
    ctx.fillStyle = 'rgb(0 0 0 / 0.15)'
    ctx.fill()
  }

  blit(ctx, canvas, px + w / 2, py + h, w, drawn)
}

/** Rugs and mats: woven rectangles rather than pictures. */
function flat(
  ctx: CanvasRenderingContext2D,
  object: SpaceObject,
  kind: DecorKind,
  px: number,
  py: number,
  size: number,
): void {
  const w = kind.w * size
  const h = kind.h * size
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
