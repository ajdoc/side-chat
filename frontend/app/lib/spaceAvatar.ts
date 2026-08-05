/**
 * People, drawn as little pixel-art trainers — and now as *particular* little pixel-art trainers.
 *
 * Original artwork, not lifted from anywhere: it's the idiom that's borrowed (chunky outline, a
 * head half the height of the body, side view narrower than the front) rather than any
 * character.
 *
 * ## Layers, not variants
 *
 * The obvious way to make a customisable sprite is to draw every combination. With two bodies,
 * eight hairstyles, eight hair colours, six skins and eleven shirts that is a hundred thousand
 * sprites, so instead a sprite is *composed*: a bare-headed body, then a hairstyle painted over
 * it, each with its own palette. The body grid never mentions hair and the hair grid never
 * mentions skin, which is what lets either change without touching the other.
 *
 * The rasteriser caches by the combination that was actually asked for
 * ({@link file://./pixelSprite.ts pixelSprite}), so a room of fifty people costs fifty
 * rasterisations once and one `drawImage` each per frame thereafter.
 *
 * ## What the layers are
 *
 *   - **Body** — bare head, torso, legs. Two silhouettes; `sturdy` is a pixel broader at the
 *     shoulder, which at this size is the difference you actually notice.
 *   - **Hair** — painted over the head. Authored only as far down as it reaches and padded out,
 *     because eleven rows of dots are eleven rows of nothing to read.
 *
 * Three directions are stored. `left` is `right` mirrored at blit time, because drawing the same
 * pixels backwards is free and maintaining two copies of one sprite is not.
 */

import type { Facing } from './spaceMapEngine'
import type { SheetSpec } from './spriteSheet'
import { blit, sprite } from './pixelSprite'
import { drawSheetFrame, sheetReady, sheetRow } from './spriteSheet'

export const SPRITE_SIZE = 16

export type BodyKind = 'slim' | 'sturdy' | 'feminine'
export type HairKind = 'short' | 'bob' | 'long' | 'ponytail' | 'buzz' | 'curly' | 'spiky' | 'cap'
export type SkinKind = 'porcelain' | 'fair' | 'olive' | 'tan' | 'brown' | 'deep'
export type HairColour = 'black' | 'brown' | 'blonde' | 'auburn' | 'ash' | 'blue' | 'pink' | 'green'
export type OutfitKind = 'auto' | 'red' | 'orange' | 'yellow' | 'green' | 'teal' | 'blue' | 'indigo' | 'violet' | 'pink' | 'slate'

/** What somebody chose. Mirrors `space_avatar` on the user, and the server's Avatars catalogue. */
export interface AvatarLook {
  body: BodyKind
  hair: HairKind
  hair_color: HairColour
  skin: SkinKind
  outfit: OutfitKind
  /**
   * Worn *over* the rest rather than instead of it: the five fields above are kept while a
   * costume is on, so taking it off puts the same person back. See the costume section.
   */
  costume: CostumeKind
}

export const BODIES: BodyKind[] = ['slim', 'sturdy', 'feminine']
export const HAIRS: HairKind[] = ['short', 'bob', 'long', 'ponytail', 'buzz', 'curly', 'spiky', 'cap']
export const SKINS: SkinKind[] = ['porcelain', 'fair', 'olive', 'tan', 'brown', 'deep']
export const HAIR_COLOURS: HairColour[] = ['black', 'brown', 'blonde', 'auburn', 'ash', 'blue', 'pink', 'green']
export const OUTFITS: OutfitKind[] = ['auto', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'indigo', 'violet', 'pink', 'slate']

export const DEFAULT_LOOK: AvatarLook = {
  body: 'slim',
  hair: 'short',
  hair_color: 'brown',
  skin: 'fair',
  outfit: 'auto',
  costume: 'none',
}

/** Fill in anything missing or unrecognised, so a sprite is always drawable. */
export function normaliseLook(look: Partial<AvatarLook> | null | undefined): AvatarLook {
  const pick = <T extends string>(value: unknown, allowed: readonly T[], fallback: T): T =>
    allowed.includes(value as T) ? value as T : fallback

  return {
    body: pick(look?.body, BODIES, DEFAULT_LOOK.body),
    hair: pick(look?.hair, HAIRS, DEFAULT_LOOK.hair),
    hair_color: pick(look?.hair_color, HAIR_COLOURS, DEFAULT_LOOK.hair_color),
    skin: pick(look?.skin, SKINS, DEFAULT_LOOK.skin),
    outfit: pick(look?.outfit, OUTFITS, DEFAULT_LOOK.outfit),
    // Absent on every look saved before costumes existed, which is exactly `none`.
    costume: pick(look?.costume, COSTUMES, DEFAULT_LOOK.costume),
  }
}

// --- palettes ---

const SKIN: Record<SkinKind, [string, string]> = {
  porcelain: ['#ffe0c4', '#e8bd9a'],
  fair: ['#f7cea8', '#d9a578'],
  olive: ['#e0b183', '#bd8a5b'],
  tan: ['#c98f5f', '#a26c41'],
  brown: ['#96603a', '#734427'],
  deep: ['#6b4229', '#4c2c1a'],
}

const HAIR_PAINT: Record<HairColour, [string, string]> = {
  black: ['#37323c', '#221f28'],
  brown: ['#7a4f2d', '#5a3820'],
  blonde: ['#e0b451', '#b88c33'],
  auburn: ['#a44a2c', '#7c331b'],
  ash: ['#b9b3ab', '#8d8880'],
  blue: ['#4a72c0', '#33518f'],
  pink: ['#d3679c', '#a34d75'],
  green: ['#4f9a5c', '#367042'],
}

/** Shirt colours. `auto` is resolved from the user id before we get here. */
const OUTFIT_PAINT: Record<Exclude<OutfitKind, 'auto'>, [string, string]> = {
  red: ['#cf4a45', '#9c332f'],
  orange: ['#e0813a', '#ac5c22'],
  yellow: ['#e2b93f', '#ac8a24'],
  green: ['#57a04c', '#3d7735'],
  teal: ['#3fa3a0', '#297a77'],
  blue: ['#4277c4', '#2d5694'],
  indigo: ['#6060c0', '#43438f'],
  violet: ['#8a5cb8', '#67408c'],
  pink: ['#cd5f96', '#9e416f'],
  slate: ['#6b7280', '#4b5259'],
}

/**
 * Everybody with `auto` still gets their own shirt, derived from their user id.
 *
 * No column, no migration, no agreement between clients: everybody computes the same hue for the
 * same person. Golden-angle stepping puts consecutive ids far apart on the wheel, so the two
 * people most likely to be in a room together are the least likely to match.
 */
export function spriteHue(userId: number): number {
  return (userId * 137.508) % 360
}

type SpriteDir = 'down' | 'up' | 'right'

// --- the body ---

/*
 * Bare-headed, so a hairstyle can be painted over it. Slots:
 *
 *   `.` transparent  `o` outline  `S` skin      `E` eye
 *   `C` shirt        `K` shirt, shaded          `P` trousers  `B` boots
 *
 * Only the last two rows differ between walk frames, so the frames are stored as those rows
 * alone and spliced on — a whole second copy of each grid to move two feet would be a lot of
 * lines that have to stay in step by hand.
 */

const BODY: Record<SpriteDir, string[]> = {
  down: [
    '................',
    '.....oooooo.....',
    '....oSSSSSSo....',
    '....oSSSSSSo....',
    '...ooSSSSSSoo...',
    '...oSSSSSSSSo...',
    '...oSEESSEESo...',
    '...oSSSSSSSSo...',
    '....oSSSSSSo....',
    '.....oooooo.....',
    '...oKCCCCCCKo...',
    '..oKKCCCCCCKKo..',
    '..oSKCCCCCCKSo..',
    '...ooPPPPPPoo...',
  ],
  up: [
    '................',
    '.....oooooo.....',
    '....oSSSSSSo....',
    '....oSSSSSSo....',
    '...ooSSSSSSoo...',
    '...oSSSSSSSSo...',
    '...oSSSSSSSSo...',
    '...oSSSSSSSSo...',
    '....oSSSSSSo....',
    '.....oooooo.....',
    '...oKCCCCCCKo...',
    '..oKKCCCCCCKKo..',
    '..oSKCCCCCCKSo..',
    '...ooPPPPPPoo...',
  ],
  right: [
    '................',
    '.....oooooo.....',
    '....oSSSSSSo....',
    '....oSSSSSSSo...',
    '...ooSSSSSSo....',
    '...oSSSSSSSo....',
    '...oSSSEESSo....',
    '...oSSSSSSSo....',
    '....oSSSSSo.....',
    '.....oooooo.....',
    '....oCCCCCCo....',
    '...oKCCCCCCo....',
    '...oKKCCCCSo....',
    '....ooPPPPo.....',
  ],
}

/** The two rows of legs, per direction and frame. Row 14 and 15 of the grid. */
const LEGS: Record<SpriteDir, [string[], string[]]> = {
  down: [
    ['....oPPooPPo....', '....oBBooBBo....'],
    ['....oPPPPPPo....', '...oBBo..oBBo...'],
  ],
  up: [
    ['....oPPooPPo....', '....oBBooBBo....'],
    ['....oPPPPPPo....', '...oBBo..oBBo...'],
  ],
  right: [
    ['.....oPPPo......', '.....oBBBo......'],
    ['....oPPPPo......', '...oBBo.oBBo....'],
  ],
}

/** `sturdy` broadens the shoulders and chest by a pixel each side. */
const STURDY_TORSO: Record<SpriteDir, string[]> = {
  down: ['..oKKCCCCCCKKo..', '.oKKKCCCCCCKKKo.', '.oSKKCCCCCCKKSo.'],
  up: ['..oKKCCCCCCKKo..', '.oKKKCCCCCCKKKo.', '.oSKKCCCCCCKKSo.'],
  right: ['...oCCCCCCCo....', '..oKCCCCCCCo....', '..oKKCCCCCSo....'],
}

/**
 * `feminine` swaps the trouser waist (row 13) for the top of a skirt that flares out below.
 * The shirt colour carries down into it — the top and the skirt are one dress — which is what
 * reads as female at a glance where a narrower torso alone wouldn't.
 */
const FEMININE_SKIRT: Record<SpriteDir, string> = {
  down: '..oCCCCCCCCCCo..',
  up: '..oCCCCCCCCCCo..',
  right: '...oCCCCCCCCo...',
}

/** The dress hem and the legs peeking out of it, per direction and walk frame. */
const FEMININE_LEGS: Record<SpriteDir, [string[], string[]]> = {
  down: [
    ['.oCCCCCCCCCCCCo.', '...oBBooooBBo...'],
    ['.oCCCCCCCCCCCCo.', '...oBBo..oBBo...'],
  ],
  up: [
    ['.oCCCCCCCCCCCCo.', '...oBBooooBBo...'],
    ['.oCCCCCCCCCCCCo.', '...oBBo..oBBo...'],
  ],
  right: [
    ['..oCCCCCCCCCo...', '....oBBBBo......'],
    ['..oCCCCCCCCCo...', '...oBBooBBo.....'],
  ],
}

// --- hair ---

/*
 * Painted over the head. Authored down to wherever the style reaches and padded out with
 * transparency, so what you read is the shape rather than a field of dots.
 *
 * `H` hair, `h` hair in shadow, `o` outline — a style with a silhouette of its own (a ponytail,
 * a cap's peak) has to draw its own outline, or it reads as a smudge against the background.
 */

function hair(rows: string[]): string[] {
  return [...rows, ...Array.from({ length: SPRITE_SIZE - rows.length }, () => '................')]
}

const HAIR_ART: Record<HairKind, Record<SpriteDir, string[]>> = {
  short: {
    down: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHhhhhhhHo...',
    ]),
    up: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHhhhhhhHo...',
      '....ohhhhhho....',
    ]),
    right: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHHo...',
      '...ooHHHHHHo....',
      '...oHhhhSSSo....',
    ]),
  },
  bob: {
    down: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHhhhhhhHo...',
      '...oH......Ho...',
      '...oH......Ho...',
      '....oh....ho....',
      '.....oooooo.....',
    ]),
    up: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '....oHHHHHHo....',
      '.....ohhhho.....',
    ]),
    right: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHHo...',
      '...ooHHHHHHo....',
      '...oHhhhSSSo....',
      '...oHh.....o....',
      '...oHh.....o....',
      '....oh....o.....',
    ]),
  },
  long: {
    down: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHhhHHo....',
      '...ooHHHHHHoo...',
      '...oHhhhhhhHo...',
      '...oH......Ho...',
      '...oH......Ho...',
      '...oH......Ho...',
      '...oHHHHHHHHHo..',
      '...oHo....oHo...',
      '...oho....oho...',
      '....o......o....',
    ]),
    up: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '....ohhhhhho....',
      '.....oooooo.....',
    ]),
    right: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHHo...',
      '...ooHHHHHHo....',
      '...oHhhhSSSo....',
      '...oHh.....o....',
      '...oHh.....o....',
      '...oHh....o.....',
      '...oHho.........',
      '...ohho.........',
      '....oo..........',
    ]),
  },
  ponytail: {
    down: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHhhhhhhHo...',
      '..oHo......oHo..',
      '..oHo......oHo..',
      '...oo......oo...',
    ]),
    up: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '....oHHHHHHo....',
      '.....oHHHHo.....',
      '.....oHHHHo.....',
      '.....ohhhho.....',
      '......oooo......',
    ]),
    right: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHHHHHHHo...',
      '...ooHHHHHHo....',
      '..ooHhhhSSSo....',
      '.oHHo......o....',
      '.oHHo...........',
      '.oHho...........',
      '..oo............',
    ]),
  },
  buzz: {
    down: hair([
      '................',
      '.....oooooo.....',
      '....ohhhhhho....',
      '....ohHHHHho....',
      '...oohhhhhhoo...',
    ]),
    up: hair([
      '................',
      '.....oooooo.....',
      '....ohhhhhho....',
      '....ohHHHHho....',
      '...oohhhhhhoo...',
      '...ohhhhhhhho...',
      '...ohHHHHHHho...',
      '...ohhhhhhhho...',
    ]),
    right: hair([
      '................',
      '.....oooooo.....',
      '....ohhhhhho....',
      '....ohHHHHho....',
      '...oohhhhhho....',
    ]),
  },
  curly: {
    down: hair([
      '....oo....oo....',
      '...oHHooooHHo...',
      '..oHHHHHHHHHHo..',
      '..oHHhHHHHhHHo..',
      '..ooHHHHHHHHoo..',
      '...oHhhhhhhHo...',
      '...oo......oo...',
    ]),
    up: hair([
      '....oo....oo....',
      '...oHHooooHHo...',
      '..oHHHHHHHHHHo..',
      '..oHHhHHHHhHHo..',
      '..ooHHHHHHHHoo..',
      '...oHHHHHHHHo...',
      '...oHHhHHhHHo...',
      '...oHHHHHHHHo...',
      '....ohhhhhho....',
    ]),
    right: hair([
      '....oo....oo....',
      '...oHHooooHHo...',
      '..oHHHHHHHHHo...',
      '..oHHhHHHHHHo...',
      '..ooHHHHHHHo....',
      '...oHhhhSSSo....',
      '...oo......o....',
    ]),
  },
  spiky: {
    down: hair([
      '...o.oo.oo.o....',
      '..oHooHooHHo....',
      '..oHHHHHHHHHo...',
      '...oHHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHhhhhhhHo...',
    ]),
    up: hair([
      '...o.oo.oo.o....',
      '..oHooHooHHo....',
      '..oHHHHHHHHHo...',
      '...oHHHHHHHo....',
      '...ooHHHHHHoo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHhhhhhhHo...',
    ]),
    right: hair([
      '...o.oo.oo.o....',
      '..oHooHooHHo....',
      '..oHHHHHHHHo....',
      '...oHHHHHHHHo...',
      '...ooHHHHHHo....',
      '...oHhhhSSSo....',
    ]),
  },
  cap: {
    down: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHhhhhHo....',
      '...ooHHHHHHoo...',
      '..ohhhhhhhhhho..',
      '..oooooooooooo..',
    ]),
    up: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHhhhhHo....',
      '...ooHHHHHHoo...',
      '...ohhhhhhhho...',
      '...oHHHHHHHHo...',
      '...oHhhhhhhHo...',
      '....ohhhhhho....',
    ]),
    right: hair([
      '................',
      '.....oooooo.....',
      '....oHHHHHHo....',
      '....oHhhhhHHo...',
      '...ooHHHHHHo....',
      '...ohhhhhhhhoo..',
      '...ooooooooooo..',
    ]),
  },
}

// --- costumes ---

/*
 * A costume is the one thing that *isn't* a layer.
 *
 * Everything else here composes: a hairstyle over a body, a shirt colour into a palette, each
 * ignorant of the others. A costume can't work that way, because it changes the silhouette —
 * a hood is a different head, a suit of armour is a different set of shoulders — and a layer
 * painted over the wrong shape reads as a costume drawn on a person rather than a person in a
 * costume. So a costume replaces the body and its legs outright, and suppresses the hair when
 * it covers the head.
 *
 * What it *doesn't* replace is the rest of the look: your skin, hair and shirt stay stored
 * underneath, so taking a costume off puts you back rather than a stranger with default hair.
 *
 * Every design here is original — none copies a specific copyrighted character. Each borrows a
 * broad genre trope (a hooded robe and mask, powered armour, a masked mercenary suit, a giant
 * grinning figure, a color-blocked hero suit, a cactus) the same way the trainer sprite borrows
 * its chunky outline, without reproducing anyone's particular design.
 */

export type CostumeKind = 'none' | 'cantor' | 'sentinel' | 'merc' | 'cactus' | 'guard' | 'colossus' | 'plush' | 'bunny' | 'faceless' | 'mummy' | 'jackoghost' | 'pirate' | 'robot' | 'witch' | 'devil' | 'espurr' | 'espurr_vessel' | 'espurr_pickachu'
export const COSTUMES: CostumeKind[] = ['none', 'cantor', 'sentinel', 'merc', 'cactus', 'guard', 'colossus', 'plush', 'bunny', 'faceless', 'mummy', 'jackoghost', 'pirate', 'robot', 'witch', 'devil', 'espurr', 'espurr_vessel', 'espurr_pickachu']

interface Costume {
  label: string
  /** One line for the picker — what you'd be putting on. */
  blurb: string
  /** A hood or a helmet leaves no hair to draw, so the layer is skipped rather than hidden. */
  covered: boolean
  /** Its own palette slots, merged over the body's — see {@link palette}. */
  paint: Record<string, string>
  /** Rows 0–13, in place of {@link BODY}. */
  body: Record<SpriteDir, string[]>
  /** Rows 14–15 per walk frame, in place of {@link LEGS}. */
  legs: Record<SpriteDir, [string[], string[]]>
  /**
   * Artwork from a sheet instead of from the grids above — see {@link file://./spriteSheet.ts}.
   *
   * A costume is already a whole-silhouette replacement, so this is the one avatar field a sheet
   * can stand in for cleanly: there is no skin, hair or shirt showing through to reconcile with
   * artwork that knows nothing about them. Used when the file is on disk, and the grids are the
   * fallback when it isn't.
   */
  sheets?: { idle: SheetSpec, walk: SheetSpec }
}

/**
 * The Cantor: a dark hooded robe, a painted mask, a weight of silver at the throat.
 *
 * Slots: `R` robe, `r` its shadow and outline, `m` the mask, `X`/`A` the paint on it, `G` silver.
 * The mask does the whole job at this size — a hood alone is a monk, and a hood with two red
 * marks where the eyes should be is somebody you'd stop and look at.
 */
const CANTOR: Costume = {
  label: 'Hooded Cantor',
  blurb: 'A dark robe, a painted mask, and silver at the throat',
  covered: true,
  paint: {
    R: '#2b2833',
    r: '#191720',
    m: '#efe7da',
    X: '#c33a32',
    A: '#8c241f',
    G: '#c3c8d2',
  },
  body: {
    down: [
      '................',
      '.....rrrrrr.....',
      '....rRRRRRRr....',
      '...rRRmmmmRRr...',
      '...rRmmmmmmRr...',
      '...rRmXmmXmRr...',
      '...rRmXXXXmRr...',
      '...rRmmXXmmRr...',
      '....rRmmmmRr....',
      '.....rRRRRr.....',
      '...rRRGGGGRRr...',
      '..rRRRGGGGRRRr..',
      '..rRRRRRRRRRRr..',
      '...rRRRRRRRRr...',
    ],
    up: [
      '................',
      '.....rrrrrr.....',
      '....rRRRRRRr....',
      '...rRRRRRRRRr...',
      '...rRRRRRRRRr...',
      '...rRRRRRRRRr...',
      '...rRRRRRRRRr...',
      '...rRRRRRRRRr...',
      '....rRRRRRRr....',
      '.....rRRRRr.....',
      '...rRRRRRRRRr...',
      '..rRRRRRRRRRRr..',
      '..rRRRRRRRRRRr..',
      '...rRRRRRRRRr...',
    ],
    right: [
      '................',
      '.....rrrrrr.....',
      '....rRRRRRRr....',
      '...rRRmmmmRr....',
      '...rRmmmmmXr....',
      '...rRmmXXmmr....',
      '...rRmmmmmmr....',
      '...rRRmmmmRr....',
      '....rRRRRRr.....',
      '.....rRRRRr.....',
      '....rRRGGRRr....',
      '...rRRRGGRRRr...',
      '...rRRRRRRRRr...',
      '....rRRRRRRr....',
    ],
  },
  legs: {
    down: [
      ['...rRRRooRRRr...', '....oGGooGGo....'],
      ['...rRRRRRRRRr...', '...oGGo..oGGo...'],
    ],
    up: [
      ['...rRRRooRRRr...', '....oGGooGGo....'],
      ['...rRRRRRRRRr...', '...oGGo..oGGo...'],
    ],
    right: [
      ['....rRRRRRr.....', '.....oGGGo......'],
      ['...rRRRRRr......', '...oGGo.oGGo....'],
    ],
  },
}

/**
 * The Sentinel: white powered armour, a dark inner frame, gold horns and a lit visor.
 *
 * Slots: `W` plate, `w` its shading, `D` the frame beneath, `Y` gold, `V` the visor, `X` a red
 * chest marking. The horns are drawn *above* the head rather than on it, which is what buys the
 * silhouette at sixteen pixels — armour alone reads as a person in white.
 */
const SENTINEL: Costume = {
  label: 'Iron Sentinel',
  blurb: 'White plate over a dark frame, gold horns, a lit visor',
  covered: true,
  paint: {
    W: '#e6e8ee',
    w: '#a7adba',
    D: '#343947',
    Y: '#d9a83c',
    V: '#59d9d0',
    X: '#b73b3f',
  },
  body: {
    down: [
      '.....Y....Y.....',
      '....YY....YY....',
      '....oWWWWWWo....',
      '...oWWWWWWWWo...',
      '...oWDDDDDDWo...',
      '...oWDVVVVDWo...',
      '...oWWDDDDWWo...',
      '....oWWWWWWo....',
      '.....oDDDDo.....',
      '...oWWWDDWWWo...',
      '..oWWWDXXDWWWo..',
      '..oWWDDDDDDWWo..',
      '..oWWDDDDDDWWo..',
      '...oDDDDDDDDo...',
    ],
    up: [
      '.....Y....Y.....',
      '....YY....YY....',
      '....oWWWWWWo....',
      '...oWWWWWWWWo...',
      '...oWWDDDDWWo...',
      '...oWWDDDDWWo...',
      '...oWWWWWWWWo...',
      '....oWWWWWWo....',
      '.....oDDDDo.....',
      '...oWWWDDWWWo...',
      '..oWWWDDDDWWWo..',
      '..oWWDDDDDDWWo..',
      '..oWWDDDDDDWWo..',
      '...oDDDDDDDDo...',
    ],
    right: [
      '.......Y........',
      '......YY........',
      '....oWWWWWWo....',
      '...oWWWWWWWWo...',
      '...oWDDDDDVWo...',
      '...oWDDDDVVWo...',
      '...oWWDDDDWWo...',
      '....oWWWWWWo....',
      '.....oDDDDo.....',
      '....oWWWDDWo....',
      '...oWWWDXDWo....',
      '...oWWDDDDWo....',
      '...oWWDDDDWo....',
      '....oDDDDDo.....',
    ],
  },
  legs: {
    down: [
      ['....oWWooWWo....', '....oYYooYYo....'],
      ['....oWWWWWWo....', '...oYYo..oYYo...'],
    ],
    up: [
      ['....oWWooWWo....', '....oYYooYYo....'],
      ['....oWWWWWWo....', '...oYYo..oYYo...'],
    ],
    right: [
      ['.....oWWWo......', '.....oYYYo......'],
      ['....oWWWWo......', '...oYYo.oYYo....'],
    ],
  },
}

/**
 * The Mercenary: a black-and-red masked suit, black straps crossing the chest, black eye lenses.
 *
 * Slots: `M` suit red, `m` its shading, `k` outline/black, `K` straps, `W` eye lenses.
 */
const MERC: Costume = {
  label: 'Crimson Mercenary',
  blurb: 'A quip-cracking merc in a red-and-black suit, twin blades sheathed on his back',
  covered: true,
  paint: {
    k: '#151318',
    M: '#c9403c',
    m: '#7c211f',
    W: '#161616',
    K: '#1c1a1f',
  },
  body: {
    down: [
      '................',
      '.....kkkkkk.....',
      '....kMMMMMMk....',
      '...kMMmmmmMMk...',
      '...kMmmmmmmMk...',
      '...kMmWmmWmMk...',
      '...kMmWWWWmMk...',
      '...kMmmWWmmMk...',
      '....kMmmmmMk....',
      '.....kMMMMk.....',
      '...kMMKKKKMMk...',
      '..kMMMKKKKMMMk..',
      '..kMMMMMMMMMMk..',
      '...kMMMMMMMMk...',
    ],
    up: [
      '................',
      '.....kkkkkk.....',
      '....kMMMMMMk....',
      '...kMMMMMMMMk...',
      '...kMMMMMMMMk...',
      '...kMMMMMMMMk...',
      '...kMMMMMMMMk...',
      '...kMMMMMMMMk...',
      '....kMMMMMMk....',
      '.....kMMMMk.....',
      '...kMMMMMMMMk...',
      '..kMMMMMMMMMMk..',
      '..kMMMMMMMMMMk..',
      '...kMMMMMMMMk...',
    ],
    right: [
      '................',
      '.....kkkkkk.....',
      '....kMMMMMMk....',
      '...kMMmmmmMk....',
      '...kMmmmmmWk....',
      '...kMmmWWmmk....',
      '...kMmmmmmmk....',
      '...kMMmmmmMk....',
      '....kMMMMMk.....',
      '.....kMMMMk.....',
      '....kMMKKMMk....',
      '...kMMMKKMMMk...',
      '...kMMMMMMMMk...',
      '....kMMMMMMk....',
    ],
  },
  legs: {
    down: [
      ['...kMMMookMMMk...', '....oKKooKKo....'],
      ['...kMMMMMMMMk...', '...oKKo..oKKo...'],
    ],
    up: [
      ['...kMMMookMMMk...', '....oKKooKKo....'],
      ['...kMMMMMMMMk...', '...oKKo..oKKo...'],
    ],
    right: [
      ['....kMMMMMk.....', '.....oKKKo......'],
      ['...kMMMMMk......', '...oKKo.oKKo....'],
    ],
  },
}

/**
 * The Prickly Pear: a round green cactus suit with a little pink flower on top.
 *
 * Slots: `G` cactus green, `g` its shadow/outline, `m` highlight band, `F` flower, `Y` a darker
 * ring standing in for the ridged base.
 */
const CACTUS: Costume = {
  label: 'Prickly Pear',
  blurb: 'A round green cactus suit with a little pink flower on top',
  covered: true,
  paint: {
    g: '#2f5c34',
    G: '#4f9a52',
    m: '#3f7c45',
    F: '#e2739e',
    Y: '#3a6b3d',
  },
  body: {
    down: [
      '................',
      '.....gggggg.....',
      '....gGGGGGGg....',
      '...gGGmmmmGGg...',
      '...gGmmmmmmGg...',
      '...gGmFmmFmGg...',
      '...gGmFFFFmGg...',
      '...gGmmFFmmGg...',
      '....gGmmmmGg....',
      '.....gGGGGg.....',
      '...gGGYYYYGGg...',
      '..gGGGYYYYGGGg..',
      '..gGGGGGGGGGGg..',
      '...gGGGGGGGGg...',
    ],
    up: [
      '................',
      '.....gggggg.....',
      '....gGGGGGGg....',
      '...gGGGGGGGGg...',
      '...gGGGGGGGGg...',
      '...gGGGGGGGGg...',
      '...gGGGGGGGGg...',
      '...gGGGGGGGGg...',
      '....gGGGGGGg....',
      '.....gGGGGg.....',
      '...gGGGGGGGGg...',
      '..gGGGGGGGGGGg..',
      '..gGGGGGGGGGGg..',
      '...gGGGGGGGGg...',
    ],
    right: [
      '................',
      '.....gggggg.....',
      '....gGGGGGGg....',
      '...gGGmmmmGg....',
      '...gGmmmmmFg....',
      '...gGmmFFmmg....',
      '...gGmmmmmmg....',
      '...gGGmmmmGg....',
      '....gGGGGGg.....',
      '.....gGGGGg.....',
      '....gGGYYGGg....',
      '...gGGGYYGGGg...',
      '...gGGGGGGGGg...',
      '....gGGGGGGg....',
    ],
  },
  legs: {
    down: [
      ['...gGGGooGGGg...', '....oYYooYYo....'],
      ['...gGGGGGGGGg...', '...oYYo..oYYo...'],
    ],
    up: [
      ['...gGGGooGGGg...', '....oYYooYYo....'],
      ['...gGGGGGGGGg...', '...oYYo..oYYo...'],
    ],
    right: [
      ['....gGGGGGg.....', '.....oYYYo......'],
      ['...gGGGGGg......', '...oYYo.oYYo....'],
    ],
  },
}

/**
 * The Color Guard: a sculpted helmet visor over a bold color-blocked hero suit.
 *
 * Slots: `V` suit, `m` visor shading, `S` visor bright, `W` belt/trim, `k` outline.
 */
const GUARD: Costume = {
  label: 'Color Guard',
  blurb: 'A sculpted helmet visor and a bold color-blocked hero suit',
  covered: true,
  paint: {
    k: '#141316',
    V: '#d1332f',
    m: '#8a1f1c',
    S: '#eef1f5',
    W: '#f2f2ec',
  },
  body: {
    down: [
      '................',
      '.....kkkkkk.....',
      '....kVVVVVVk....',
      '...kVVSSSSVVk...',
      '...kVSSSSSSVk...',
      '...kVSmSSmSVk...',
      '...kVSmmmmSVk...',
      '...kVSSmmSSVk...',
      '....kVSSSSVk....',
      '.....kVVVVk.....',
      '...kVVWWWWVVk...',
      '..kVVVWWWWVVVk..',
      '..kVVVVVVVVVVk..',
      '...kVVVVVVVVk...',
    ],
    up: [
      '................',
      '.....kkkkkk.....',
      '....kVVVVVVk....',
      '...kVVVVVVVVk...',
      '...kVVVVVVVVk...',
      '...kVVVVVVVVk...',
      '...kVVVVVVVVk...',
      '...kVVVVVVVVk...',
      '....kVVVVVVk....',
      '.....kVVVVk.....',
      '...kVVVVVVVVk...',
      '..kVVVVVVVVVVk..',
      '..kVVVVVVVVVVk..',
      '...kVVVVVVVVk...',
    ],
    right: [
      '................',
      '.....kkkkkk.....',
      '....kVVVVVVk....',
      '...kVVSSSSVk....',
      '...kVmmmmmSk....',
      '...kVmmSSmmk....',
      '...kVmmmmmmk....',
      '...kVVmmmmVk....',
      '....kVVVVVk.....',
      '.....kVVVVk.....',
      '....kVVWWVVk....',
      '...kVVVWWVVVk...',
      '...kVVVVVVVVk...',
      '....kVVVVVVk....',
    ],
  },
  legs: {
    down: [
      ['...kVVVooVVVk...', '....oWWooWWo....'],
      ['...kVVVVVVVVk...', '...oWWo..oWWo...'],
    ],
    up: [
      ['...kVVVooVVVk...', '....oWWooWWo....'],
      ['...kVVVVVVVVk...', '...oWWo..oWWo...'],
    ],
    right: [
      ['....kVVVVVk.....', '.....oWWWo......'],
      ['...kVVVVVk......', '...oWWo.oWWo....'],
    ],
  },
}

/**
 * The Colossal Guardian: a towering skin-toned giant with two eyes, black hair, and a wide grin.
 *
 * Slots: `T` skin, `t` its shadow/outline, `W` teeth, `A` hair, `e` eyes.
 */
const COLOSSUS: Costume = {
  label: 'Colossal Guardian',
  blurb: 'A towering skin-toned giant with a wide, toothy grin',
  covered: true,
  paint: {
    t: '#8a6a52',
    T: '#d9b48f',
    W: '#f5efe2',
    A: '#2a2620',
    e: '#1c1a1f',
  },
  body: {
    down: [
      '................',
      '.....oAAAAo.....',
      '....oAAAAAAo....',
      '...tTTTTTTTTt...',
      '...tTTteteTTt...',
      '...tTtWWWWtTt...',
      '...tTtWWWWtTt...',
      '...tTTttttTTt...',
      '....tTTTTTTt....',
      '.....tTTTTt.....',
      '...tTTTTTTTTt...',
      '..tTTTTTTTTTTt..',
      '..tTTTTTTTTTTt..',
      '...tTTTTTTTTt...',
    ],
    up: [
      '................',
      '.....oAAAAo.....',
      '....oAAAAAAo....',
      '...tTTTTTTTTt...',
      '...tTTTTTTTTt...',
      '...tTTTTTTTTt...',
      '...tTTTTTTTTt...',
      '...tTTTTTTTTt...',
      '....tTTTTTTt....',
      '.....tTTTTt.....',
      '...tTTTTTTTTt...',
      '..tTTTTTTTTTTt..',
      '..tTTTTTTTTTTt..',
      '...tTTTTTTTTt...',
    ],
    right: [
      '................',
      '.....oAAAAo.....',
      '....oAAAAAAo....',
      '...tTTTTTTTTt...',
      '...tTTtettTt....',
      '...tTtWWWtTt....',
      '...tTtWWWtTt....',
      '...tTTttttTt....',
      '....tTTTTTt.....',
      '.....tTTTTt.....',
      '....tTTTTTTt....',
      '...tTTTTTTTTt...',
      '...tTTTTTTTTt...',
      '....tTTTTTTt....',
    ],
  },
  legs: {
    down: [
      ['...tTTTooTTTt...', '....oTTooTTo....'],
      ['...tTTTTTTTTt...', '...oTTo..oTTo...'],
    ],
    up: [
      ['...tTTTooTTTt...', '....oTTooTTo....'],
      ['...tTTTTTTTTt...', '...oTTo..oTTo...'],
    ],
    right: [
      ['....tTTTTTt.....', '.....oTTTo......'],
      ['...tTTTTTt......', '...oTTo.oTTo....'],
    ],
  },
}

/**
 * Plush Hood: a soft pink hood with fur trim and bunny ears, a face still visible underneath.
 */
const PLUSH: Costume = {
  label: 'Plush Hood',
  blurb: 'A soft pink hood with fur trim and bunny ears',
  covered: true,
  paint: { P: '#f3b6c9', W: '#fbeee6', Y: '#ffffff', b: '#e8869f', e: '#2a2020' },
  body: {
    down: ['.....Y....Y.....', '....YYo..oYY....', '....oPPPPPPo....', '...oPPWWWWPPo...', '...oPWeWWeWPo...', '...oPWbWWbWPo...', '...oPPWWWWPPo...', '....oPPPPPPo....', '.....oYYYYo.....', '...oPPPPPPPPo...', '..oPPPPPPPPPPo..', '..oPPPPPPPPPPo..', '..oPPPPPPPPPPo..', '...oPPPPPPPPo...'],
    up: ['.....Y....Y.....', '....YYo..oYY....', '....oPPPPPPo....', '...oPPPPPPPPo...', '...oPPPPPPPPo...', '...oPPPPPPPPo...', '...oPPPPPPPPo...', '....oPPPPPPo....', '.....oYYYYo.....', '...oPPPPPPPPo...', '..oPPPPPPPPPPo..', '..oPPPPPPPPPPo..', '..oPPPPPPPPPPo..', '...oPPPPPPPPo...'],
    right: ['.......Y........', '......YYo.......', '....oPPPPPPo....', '...oPPWWWWPo....', '...oPWeWbWPo....', '...oPWWWWWPo....', '...oPPWWWWPo....', '....oPPPPPo.....', '.....oYYYYo.....', '....oPPYYPPo....', '...oPPPYYPPPo...', '...oPPPPPPPPo...', '....oPPPPPPo....', '.....oPPPPo.....'],
  },
  legs: {
    down: [['...oPPPooPPPo...', '....oYYooYYo....'], ['...oPPPPPPPPo...', '...oYYo..oYYo...']],
    up: [['...oPPPooPPPo...', '....oYYooYYo....'], ['...oPPPPPPPPo...', '...oYYo..oYYo...']],
    right: [['....oPPPPPo.....', '.....oYYYo......'], ['...oPPPPPo......', '...oYYo.oYYo....']],
  },
}

/**
 * Bunny Buddy: fluffy ears, a soft modest onesie, and a little cotton tail visible from behind.
 */
const BUNNY: Costume = {
  label: 'Bunny Buddy',
  blurb: 'Fluffy ears, a soft onesie, and a little cotton tail',
  covered: true,
  paint: { F: '#faf6f2', E: '#e8a3c0', S: '#f3cfae', e: '#2a2020', C: '#e7dcc8', T: '#ffffff', B: '#5a4632' },
  body: {
    down: ['.....F....F.....', '....FFo..oFF....', '....oSSSSSSo....', '...oSSeSSeSSo...', '...oSSSSSSSSo...', '....oSSSSSSo....', '.....oFFFFo.....', '...oCCCCCCCCo...', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '...oCCCCCCCCo...'],
    up: ['.....F....F.....', '....FFo..oFF....', '....oSSSSSSo....', '...oSSSSSSSSo...', '...oSSSSSSSSo...', '....oSSSSSSo....', '.....oFFFFo.....', '...oCCCCCCCCo...', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '..oCCCCTTCCCCo..', '..oCCCCTTCCCCo..', '..oCCCCCCCCCCo..', '...oCCCCCCCCo...'],
    right: ['.......F........', '......FFo.......', '....oSSSSSSo....', '...oSSeSSSSo....', '...oSSSSSSFo....', '...oSSSFFSSo....', '...oSSSSSSSo....', '...oSSSSSSSo....', '....oFFFFFo.....', '.....oCCCCo.....', '....oCCCCCCo....', '...oCCCCCCCCo...', '...oCCCCCCCCo...', '....oCCCCCCo....'],
  },
  legs: {
    down: [['.oCCCCCCCCCCCCo.', '...oBBooooBBo...'], ['.oCCCCCCCCCCCCo.', '...oBBo..oBBo...']],
    up: [['.oCCCCCCCCCCCCo.', '...oBBooooBBo...'], ['.oCCCCCCCCCCCCo.', '...oBBo..oBBo...']],
    right: [['....oCCCCCo.....', '.....oBBBo......'], ['...oCCCCCo......', '...oBBo.oBBo....']],
  },
}

/**
 * Faceless Suit: a plain black suit and a blank pale face, nothing more.
 */
const FACELESS: Costume = {
  label: 'Faceless Suit',
  blurb: 'A plain black suit and a blank pale face, no other details',
  covered: true,
  paint: { W: '#e4d9c8' },
  body: {
    down: ['................', '.....oooooo.....', '....oWWWWWWo....', '....oWWWWWWo....', '...ooWWWWWWoo...', '...oWWWWWWWWo...', '...oWWWWWWWWo...', '...oWWWWWWWWo...', '....oWWWWWWo....', '.....oooooo.....', '...oooooooooo...', '..oooooooooooo..', '..oooooooooooo..', '...oooooooooo...'],
    up: ['................', '.....oooooo.....', '....oWWWWWWo....', '....oWWWWWWo....', '...ooWWWWWWoo...', '...oWWWWWWWWo...', '...oWWWWWWWWo...', '...oWWWWWWWWo...', '....oWWWWWWo....', '.....oooooo.....', '...oooooooooo...', '..oooooooooooo..', '..oooooooooooo..', '...oooooooooo...'],
    right: ['................', '.....oooooo.....', '....oWWWWWWo....', '....oWWWWWWWo...', '...ooWWWWWWo....', '...oWWWWWWWo....', '...oWWWWWWWo....', '...oWWWWWWWo....', '....oWWWWWo.....', '.....oooooo.....', '....oooooooo....', '...ooooooooo....', '...ooooooooo....', '....ooooooo.....'],
  },
  legs: {
    down: [['....oooooooo....', '....oooooooo....'], ['....oooooooo....', '...ooo..ooo...']],
    up: [['....oooooooo....', '....oooooooo....'], ['....oooooooo....', '...ooo..ooo...']],
    right: [['.....ooooo......', '.....ooooo......'], ['....oooooo......', '...ooo.ooo....']],
  },
}

/**
 * Wrapped Mummy: head-to-toe bandages with dark hollow eyes.
 */
const MUMMY: Costume = {
  label: 'Wrapped Mummy',
  blurb: 'Head-to-toe bandages with dark hollow eyes',
  covered: true,
  paint: { M: '#d9c49a', m: '#b89b6c', d: '#1c1a1f' },
  body: {
    down: ['................', '.....oooooo.....', '....oMMMMMMo....', '....oMMMMMMo....', '...ooMMMMMMoo...', '...oMMMMMMMMo...', '...oMddMMddMo...', '...oMMMMMMMMo...', '....oMMMMMMo....', '.....oooooo.....', '...omMMMMMMmo...', '..ommMMMMMMmmo..', '..oMmMMMMMMmMo..', '...ooMMMMMMoo...'],
    up: ['................', '.....oooooo.....', '....oMMMMMMo....', '....oMMMMMMo....', '...ooMMMMMMoo...', '...oMMMMMMMMo...', '...oMMMMMMMMo...', '...oMMMMMMMMo...', '....oMMMMMMo....', '.....oooooo.....', '...omMMMMMMmo...', '..ommMMMMMMmmo..', '..oMmMMMMMMmMo..', '...ooMMMMMMoo...'],
    right: ['................', '.....oooooo.....', '....oMMMMMMo....', '....oMMMMMMMo...', '...ooMMMMMMo....', '...oMMMMMMMo....', '...oMMMddMMo....', '...oMMMMMMMo....', '....oMMMMMo.....', '.....oooooo.....', '....oMMMMMMo....', '...ommMMMMMo....', '...ommMMMMMo....', '....ooMMMMo.....'],
  },
  legs: {
    down: [['....oMMooMMo....', '....ommoommo....'], ['....oMMMMMMo....', '...ommo..ommo...']],
    up: [['....oMMooMMo....', '....ommoommo....'], ['....oMMMMMMo....', '...ommo..ommo...']],
    right: [['.....oMMMo......', '.....ommmo......'], ['....oMMMMo......', '...ommo.ommo....']],
  },
}

/**
 * Jack-o Ghost: a grinning pumpkin head floating over a tattered grey robe.
 */
const JACKOGHOST: Costume = {
  label: 'Jack-o Ghost',
  blurb: 'A grinning pumpkin head floating over a tattered grey robe',
  covered: true,
  paint: { O: '#e8862f', d: '#3a2410', Y: '#aab0b8', b: '#5a4632' },
  body: {
    down: ['......bb........', '....oOOOOOOo....', '...oOOOOOOOOo...', '...oOdOOOOdOo...', '...oOOddddOOo...', '....oOOOOOOo....', '.....oYYYYo.....', '...oYYYYYYYYo...', '....oYYYYYYo....', '.....oYYYYo.....', '...oYYYYYYYYo...', '..oYYYYYYYYYYo..', '..oYYYYYYYYYYo..', '...oYYYYYYYYo...'],
    up: ['......bb........', '....oOOOOOOo....', '...oOOOOOOOOo...', '...oOOOOOOOOo...', '...oOOOOOOOOo...', '....oOOOOOOo....', '.....oYYYYo.....', '...oYYYYYYYYo...', '....oYYYYYYo....', '.....oYYYYo.....', '...oYYYYYYYYo...', '..oYYYYYYYYYYo..', '..oYYYYYYYYYYo..', '...oYYYYYYYYo...'],
    right: ['.......bb.......', '....oOOOOOOo....', '...oOOOOOOOOo...', '...oOdOOOOo....', '...oOOddOOo....', '....oOOOOOo.....', '.....oYYYo......', '...oYYYYYYYo....', '....oYYYYYo.....', '.....oYYYo......', '...oYYYYYYYo....', '..oYYYYYYYYYo...', '..oYYYYYYYYYo...', '...oYYYYYYYo....'],
  },
  legs: {
    down: [['..oYYYYYYYYYYo..', '.oYYYYYYYYYYYYo.'], ['..oYYYYYYYYYYo..', '.oYYYYYYYYYYYYo.']],
    up: [['..oYYYYYYYYYYo..', '.oYYYYYYYYYYYYo.'], ['..oYYYYYYYYYYo..', '.oYYYYYYYYYYYYo.']],
    right: [['..oYYYYYYYYYo...', '.oYYYYYYYYYYYo..'], ['..oYYYYYYYYYo...', '.oYYYYYYYYYYYo..']],
  },
}

/**
 * Sea Rover: a tricorn hat, a weathered coat, and gold buttons.
 */
const PIRATE: Costume = {
  label: 'Sea Rover',
  blurb: 'A tricorn hat, a weathered coat, and gold buttons',
  covered: true,
  paint: { H: '#25324a', T: '#d9a06a', e: '#2a1c14', N: '#f0e6d2', C: '#8a5a34', Y: '#d9a83c', B: '#3a281c' },
  body: {
    down: ['..HHHHHHHHHHHH..', '.HHHHHHHHHHHHHH.', '..HHoTTTTTToHH..', '...oTTeTTeTTo...', '...oTTTTTTTTo...', '....oTTTTTTo....', '.....oNNNNo.....', '...oCCCCCCCCo...', '..oCCCCCCCCCCo..', '..oCCCYCCCYCCo..', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '...oCCCCCCCCo...', '...oCCCCCCCCo...'],
    up: ['..HHHHHHHHHHHH..', '.HHHHHHHHHHHHHH.', '..HHoTTTTTToHH..', '...oTTTTTTTTo...', '...oTTTTTTTTo...', '....oTTTTTTo....', '.....oNNNNo.....', '...oCCCCCCCCo...', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '..oCCCCCCCCCCo..', '...oCCCCCCCCo...', '...oCCCCCCCCo...'],
    right: ['...HHHHHHHHH....', '..HHHHHHHHHHH...', '..HHoTTTTTo.....', '...oTTeTTTo.....', '...oTTTTTTTo....', '....oTTTTTo.....', '.....oNNNo......', '....oCCCCCCo....', '...oCCCCCCCCo...', '...oCCCYCCCo....', '...oCCCCCCCCo...', '...oCCCCCCCCo...', '....oCCCCCCo....', '....oCCCCCCo....'],
  },
  legs: {
    down: [['...oCCCooCCCo...', '....oBBooBBo....'], ['...oCCCCCCCCo...', '...oBBo..oBBo...']],
    up: [['...oCCCooCCCo...', '....oBBooBBo....'], ['...oCCCCCCCCo...', '...oBBo..oBBo...']],
    right: [['....oCCCCCo.....', '.....oBBBo......'], ['...oCCCCCo......', '...oBBo.oBBo....']],
  },
}

/**
 * Tin Automaton: a boxy chassis with a single glowing visor.
 */
const ROBOT: Costume = {
  label: 'Tin Automaton',
  blurb: 'A boxy chassis with a single glowing visor',
  covered: true,
  paint: { R: '#b7bdc6', r: '#8a9099', V: '#6fd6e0' },
  body: {
    down: ['....oooooooo....', '...oRRRRRRRRo...', '...oRVVVVVVRo...', '...oRRRRRRRRo...', '....oRRRRRRo....', '.....oRRRRo.....', '...oRRRRRRRRo...', '..oRRRRRRRRRRo..', '..oRRrRRRRrRRo..', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '...oRRRRRRRRo...', '...oRRRRRRRRo...'],
    up: ['....oooooooo....', '...oRRRRRRRRo...', '...oRRRRRRRRo...', '...oRRRRRRRRo...', '....oRRRRRRo....', '.....oRRRRo.....', '...oRRRRRRRRo...', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '...oRRRRRRRRo...', '...oRRRRRRRRo...'],
    right: ['...oooooooo.....', '..oRRRRRRRRo....', '..oRVVVVVVRo....', '..oRRRRRRRRo....', '...oRRRRRRo.....', '....oRRRRo......', '..oRRRRRRRRo....', '.oRRRRRRRRRRo...', '.oRRrRRRRrRRo...', '.oRRRRRRRRRRo...', '.oRRRRRRRRRRo...', '.oRRRRRRRRRRo...', '..oRRRRRRRRo....', '..oRRRRRRRRo....'],
  },
  legs: {
    down: [['...oRRRooRRRo...', '....orroorro....'], ['...oRRRRRRRRo...', '...orro..orro...']],
    up: [['...oRRRooRRRo...', '....orroorro....'], ['...oRRRRRRRRo...', '...orro..orro...']],
    right: [['...oRRRRRo......', '.....orrro......'], ['..oRRRRRo.......', '...orro.orro....']],
  },
}

/**
 * Hedge Witch: a pointed hat and a flowing purple dress.
 */
const WITCH: Costume = {
  label: 'Hedge Witch',
  blurb: 'A pointed hat and a flowing purple dress',
  covered: true,
  paint: { H: '#22202a', T: '#e8bd93', e: '#2a1c14', P: '#5a3f7a', B: '#1c1a1f' },
  body: {
    down: ['......HH........', '....oHHHHHHo....', '..oHHHHHHHHHHo..', '...oTTTTTTTTo...', '...oTTeTTeTTo...', '....oTTTTTTo....', '.....oPPPPo.....', '...oPPPPPPPPo...', '..oPPPPPPPPPPo..', '..oPPPPPPPPPPo..', '.oPPPPPPPPPPPPo.', '.oPPPPPPPPPPPPo.', 'oPPPPPPPPPPPPPPo', 'oPPPPPPPPPPPPPPo'],
    up: ['......HH........', '....oHHHHHHo....', '..oHHHHHHHHHHo..', '...oTTTTTTTTo...', '...oTTTTTTTTo...', '....oTTTTTTo....', '.....oPPPPo.....', '...oPPPPPPPPo...', '..oPPPPPPPPPPo..', '..oPPPPPPPPPPo..', '.oPPPPPPPPPPPPo.', '.oPPPPPPPPPPPPo.', 'oPPPPPPPPPPPPPPo', 'oPPPPPPPPPPPPPPo'],
    right: ['.....HH.........', '...oHHHHHHo.....', '.oHHHHHHHHHHo...', '..oTTTTTTTo.....', '..oTTeTTTo......', '...oTTTTTo......', '....oPPPo.......', '..oPPPPPPPo.....', '.oPPPPPPPPPo....', '.oPPPPPPPPPo....', 'oPPPPPPPPPPPo...', 'oPPPPPPPPPPPo...', 'oPPPPPPPPPPPPo..', 'oPPPPPPPPPPPPo..'],
  },
  legs: {
    down: [['oPPPPPPPPPPPPPPo', '.oBBo......oBBo.'], ['oPPPPPPPPPPPPPPo', '..oBBo....oBBo..']],
    up: [['oPPPPPPPPPPPPPPo', '.oBBo......oBBo.'], ['oPPPPPPPPPPPPPPo', '..oBBo....oBBo..']],
    right: [['oPPPPPPPPPPPo...', '.oBBo......o....'], ['oPPPPPPPPPPPo...', '.oBBo......o....']],
  },
}

/**
 * Little Devil: small horns, a red hide, and a pointed tail.
 */
const DEVIL: Costume = {
  label: 'Little Devil',
  blurb: 'Small horns, a red hide, and a pointed tail',
  covered: true,
  paint: { H: '#2a1c14', R: '#c0392f', e: '#1c1a1f', t: '#8a231d' },
  body: {
    down: ['.....H....H.....', '....HHo..oHH....', '....oRRRRRRo....', '...oRReRReRRo...', '...oRRRRRRRRo...', '....oRRRRRRo....', '.....oRRRRo.....', '...oRRRRRRRRo...', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '...oRRRRRRRRo...', '...oRRRRRRRRo...'],
    up: ['.....H....H.....', '....HHo..oHH....', '....oRRRRRRo....', '...oRRRRRRRRo...', '...oRRRRRRRRo...', '....oRRRRRRo....', '.....oRRRRo.....', '...oRRRRRRRRo...', '..oRRRRRRRRRRo..', '..oRRRRRRRRRRo..', '..oRRRRttRRRRo..', '..oRRRRttRRRRo..', '...oRRRRRRRRo...', '...oRRRRRRRRo...'],
    right: ['......HHo.......', '....oRRRRRRo....', '...oRReRRRRo....', '...oRRRRRRRo....', '....oRRRRRo.....', '.....oRRRo......', '...oRRRRRRRo....', '..oRRRRRRRRRo...', '..oRRRRRRRRRo...', '..oRRRRRRRRRo...', '..oRRRRRRRRRo...', '..oRRRRRRRRRo...', '...oRRRRRRRo....', '...oRRRRRRRo....'],
  },
  legs: {
    down: [['...oRRRooRRRo...', '....oRRooRRo....'], ['...oRRRRRRRRo...', '...oRRo..oRRo...']],
    up: [['...oRRRooRRRo...', '....oRRooRRo....'], ['...oRRRRRRRRo...', '...oRRo..oRRo...']],
    right: [['....oRRRRRo.....', '.....oRRRo......'], ['...oRRRRRo......', '...oRRo.oRRo....']],
  },
}

/**
 * Espurr Suit: a soft grey onesie with big cream-lined ears and two flat lilac eyes.
 *
 * Slots: `G` grey, `C` the cream at the ears and chest, `I` the eye, `P` its one highlight,
 * `d` the dark paws. The eyes are deliberately enormous and deliberately blank — a small grey
 * animal with a normal-sized face is a mouse, and it's the stare that makes this one funny.
 */
const ESPURR_SUIT: Costume = {
  label: 'Espurr Suit',
  blurb: 'A soft grey onesie with big ears and an unblinking stare',
  covered: true,
  paint: { G: '#b9b6c4', C: '#e8dfc6', I: '#a982c9', P: '#f3e6ff', d: '#6a6676' },
  // The same sheets the pet uses: wearing the suit and walking one on a lead are the same
  // creature, so there is no second set of artwork to keep in step.
  sheets: {
    idle: { name: 'espurr/Idle', columns: 4, scale: 1.7 },
    walk: { name: 'espurr/Walk', columns: 4, scale: 1.7 },
  },
  body: {
    down: ['.....G....G.....', '....GGo..oGG....', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGIIGGIIGo...', '...oGIPGGPIGo...', '...oGGGGGGGGo...', '....oGGGGGGo....', '.....oCCCCo.....', '...oGGGGGGGGo...', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '...oGGGGGGGGo...'],
    up: ['.....G....G.....', '....GGo..oGG....', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '....oGGGGGGo....', '.....oCCCCo.....', '...oGGGGGGGGo...', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '...oGGGGGGGGo...'],
    right: ['.......G........', '......GGo.......', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGIIGGGGo....', '...oGIPGGGGo....', '...oGGGGGGGo....', '....oGGGGGo.....', '.....oCCCCo.....', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '....oGGGGGGo....'],
  },
  legs: {
    down: [['...oGGGooGGGo...', '....odd..ddo....'], ['...oGGGGGGGGo...', '...odd....ddo...']],
    up: [['...oGGGooGGGo...', '....odd..ddo....'], ['...oGGGGGGGGo...', '...odd....ddo...']],
    right: [['....oGGGGGo.....', '.....oddo.......'], ['...oGGGGGo......', '...odd..ddo.....']],
  },
}

/**
 * Espurr Vessel: the suit above with a hooded robe over it and a painted mask on the face.
 *
 * Slots: `G` grey fur, `A` the cream ear-lining, `K` the robe, `g` its gold trim, `M` the mask
 * and `k` the sigil on it. The mask is the read at this size, so the eyes are gone entirely
 * rather than peering out of it — a face and a mask both drawn in sixteen pixels is neither.
 */
const ESPURR_VESSEL: Costume = {
  label: 'Espurr Vessel',
  blurb: 'A hooded robe, gold trim, and a painted bone mask',
  covered: true,
  paint: { G: '#8f8ba0', A: '#ded6bd', K: '#221d28', g: '#b98f4c', M: '#efe7d2', k: '#1a1620', d: '#4a4654' },
  sheets: {
    idle: { name: 'espurr-vessel/Idle', columns: 4, scale: 1.7 },
    walk: { name: 'espurr-vessel/Walk', columns: 4, scale: 1.7 },
  },
  body: {
    down: ['.....A....A.....', '....AGo..oGA....', '....oGGGGGGo....', '...oGMMMMMMGo...', '...oMkMkkMkMo...', '...oMkMMMMkMo...', '...oMMkMMkMMo...', '....oMMkkMMo....', '.....oKKKKo.....', '...oKKKgKKKKo...', '..oKKKgggKKKKo..', '..oKKKKgKKKKKo..', '..oKgKKKKKKgKo..', '...oKKKKKKKKo...'],
    up: ['.....A....A.....', '....AGo..oGA....', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oKKKKKKKKo...', '...oKKKKKKKKo...', '...oKKKKKKKKo...', '....oKKKKKKo....', '.....oKKKKo.....', '...oKKKgKKKKo...', '..oKKKgggKKKKo..', '..oKKKKgKKKKKo..', '..oKgKKKKKKgKo..', '...oKKKKKKKKo...'],
    right: ['.......A........', '......AGo.......', '....oGGGGGGo....', '...oGMMMMMGo....', '...oMkkMMMGo....', '...oMkMMMMGo....', '...oMMkMMMo.....', '....oKKKKKo.....', '.....oKKKKo.....', '....oKKKgKKo....', '...oKKKgggKKo...', '...oKKKKgKKKo...', '...oKgKKKKgKo...', '....oKKKKKKo....'],
  },
  legs: {
    down: [['...oKKKooKKKo...', '....odd..ddo....'], ['...oKKKKKKKKo...', '...odd....ddo...']],
    up: [['...oKKKooKKKo...', '....odd..ddo....'], ['...oKKKKKKKKo...', '...odd....ddo...']],
    right: [['....oKKKKKo.....', '.....oddo.......'], ['...oKKKKKo......', '...odd..ddo.....']],
  },
}

/**
 * Espurr Pikachu: the grey onesie above in yellow, with blacked-out ear-tips and a red cheek
 * either side of the stare.
 *
 * Slots: `G` the yellow, `C` the cheek, `t` the ear-tips, `I`/`P` the eye and its highlight,
 * `d` the dark paws. Same silhouette as the Espurr Suit on purpose — it is the same costume
 * shop, and the colour is the joke.
 */
const ESPURR_PICKACHU: Costume = {
  label: 'Espurr Pikachu',
  blurb: 'The same onesie in yellow, with red cheeks',
  covered: true,
  paint: { G: '#f2c53d', C: '#e0503c', t: '#3a3330', I: '#2a2320', P: '#fff6da', d: '#8a6a1e' },
  sheets: {
    idle: { name: 'espurr-pickachu/Idle', columns: 4, scale: 1.7 },
    walk: { name: 'espurr-pickachu/Walk', columns: 4, scale: 1.7 },
  },
  body: {
    down: ['.....t....t.....', '....tto..ott....', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGIIGGIIGo...', '...oGIPGGPIGo...', '...oCGGGGGGCo...', '....oGGGGGGo....', '.....oGGGGo.....', '...oGGGGGGGGo...', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '...oGGGGGGGGo...'],
    up: ['.....t....t.....', '....tto..ott....', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '....oGGGGGGo....', '.....oGGGGo.....', '...oGGGGGGGGo...', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '..oGGGGGGGGGGo..', '...oGGGGGGGGo...'],
    right: ['.......t........', '......tto.......', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGIIGGGGo....', '...oGIPGGGGo....', '...oCGGGGGGo....', '....oGGGGGo.....', '.....oGGGGo.....', '....oGGGGGGo....', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '...oGGGGGGGGo...', '....oGGGGGGo....'],
  },
  legs: {
    down: [['...oGGGooGGGo...', '....odd..ddo....'], ['...oGGGGGGGGo...', '...odd....ddo...']],
    up: [['...oGGGooGGGo...', '....odd..ddo....'], ['...oGGGGGGGGo...', '...odd....ddo...']],
    right: [['....oGGGGGo.....', '.....oddo.......'], ['...oGGGGGo......', '...odd..ddo.....']],
  },
}

const COSTUME_ART: Record<Exclude<CostumeKind, 'none'>, Costume> = {
  cantor: CANTOR,
  sentinel: SENTINEL,
  merc: MERC,
  cactus: CACTUS,
  guard: GUARD,
  colossus: COLOSSUS,
  plush: PLUSH,
  bunny: BUNNY,
  faceless: FACELESS,
  mummy: MUMMY,
  jackoghost: JACKOGHOST,
  pirate: PIRATE,
  robot: ROBOT,
  witch: WITCH,
  devil: DEVIL,
  espurr: ESPURR_SUIT,
  espurr_vessel: ESPURR_VESSEL,
  espurr_pickachu: ESPURR_PICKACHU,
}

/** Names and one-liners for the picker, including the one for wearing nothing. */
export const COSTUME_META: Record<CostumeKind, { label: string, blurb: string }> = {
  none: { label: 'Just you', blurb: 'Your own face, hair and shirt' },
  cantor: { label: CANTOR.label, blurb: CANTOR.blurb },
  sentinel: { label: SENTINEL.label, blurb: SENTINEL.blurb },
  merc: { label: MERC.label, blurb: MERC.blurb },
  cactus: { label: CACTUS.label, blurb: CACTUS.blurb },
  guard: { label: GUARD.label, blurb: GUARD.blurb },
  colossus: { label: COLOSSUS.label, blurb: COLOSSUS.blurb },
  plush: { label: PLUSH.label, blurb: PLUSH.blurb },
  bunny: { label: BUNNY.label, blurb: BUNNY.blurb },
  faceless: { label: FACELESS.label, blurb: FACELESS.blurb },
  mummy: { label: MUMMY.label, blurb: MUMMY.blurb },
  jackoghost: { label: JACKOGHOST.label, blurb: JACKOGHOST.blurb },
  pirate: { label: PIRATE.label, blurb: PIRATE.blurb },
  robot: { label: ROBOT.label, blurb: ROBOT.blurb },
  witch: { label: WITCH.label, blurb: WITCH.blurb },
  devil: { label: DEVIL.label, blurb: DEVIL.blurb },
  espurr: { label: ESPURR_SUIT.label, blurb: ESPURR_SUIT.blurb },
  espurr_vessel: { label: ESPURR_VESSEL.label, blurb: ESPURR_VESSEL.blurb },
  espurr_pickachu: { label: ESPURR_PICKACHU.label, blurb: ESPURR_PICKACHU.blurb },
}

/** The costume being worn, or null for none — the one place the `none` string is unpacked. */
function costumeOf(look: AvatarLook): Costume | null {
  return look.costume === 'none' ? null : COSTUME_ART[look.costume] ?? null
}

// --- assembling one ---

function bodyRows(look: AvatarLook, dir: SpriteDir, frame: 0 | 1): string[] {
  const costume = costumeOf(look)

  // A costume is the whole silhouette, so the body choice doesn't apply: there's no slim or
  // sturdy version of a suit of armour, and pretending otherwise would need a second suit.
  if (costume) return [...costume.body[dir], ...costume.legs[dir][frame]]

  const base = [...BODY[dir]]

  if (look.body === 'sturdy') base.splice(11, 3, ...STURDY_TORSO[dir])

  if (look.body === 'feminine') {
    // Trade the trouser waist for the top of the skirt, then hang the hem and legs below.
    base.splice(13, 1, FEMININE_SKIRT[dir])

    return [...base, ...FEMININE_LEGS[dir][frame]]
  }

  return [...base, ...LEGS[dir][frame]]
}

function palette(look: AvatarLook, hue: number, self: boolean) {
  const [skin, skinShade] = SKIN[look.skin]
  const [hairLight, hairDark] = HAIR_PAINT[look.hair_color]
  const [shirt, shirtDark] = look.outfit === 'auto'
    // The old id-derived colour, kept exactly: nobody's sprite changes the day this ships.
    ? [`hsl(${hue} 62% ${self ? 56 : 48}%)`, `hsl(${hue} 45% ${self ? 38 : 32}%)`]
    : OUTFIT_PAINT[look.outfit]

  const base = {
    o: '#1c1a26',
    S: skin,
    E: '#1c1a26',
    C: shirt,
    K: shirtDark,
    H: hairLight,
    h: hairDark,
    P: '#3a3e60',
    B: '#282634',
    // The shaded skin, for the side view's neck.
    N: skinShade,
  }

  // A costume's own slots go over the top rather than beside: it brings letters the body never
  // uses, and may deliberately repaint one it does (the outline of white armour is not the
  // outline of a person).
  const costume = costumeOf(look)

  return costume ? { ...base, ...costume.paint } : base
}

/**
 * Draw one person, standing on their tile.
 *
 * Anchored by the *feet* rather than the centre — a character stands on the ground, so the tile
 * they occupy is the one under their boots. Drawing them centred makes everybody look like
 * they're floating half a tile north of where they actually are.
 */
export function drawTrainer(
  ctx: CanvasRenderingContext2D,
  who: { facing: Facing },
  px: number,
  py: number,
  size: number,
  opts: { look: AvatarLook, hue: number, self: boolean, walking: boolean, phase: number, sitting?: boolean },
): void {
  const dir: SpriteDir = who.facing === 'up' ? 'up' : who.facing === 'down' ? 'down' : 'right'
  const frame: 0 | 1 = opts.walking && opts.phase % 2 === 1 ? 1 : 0
  const look = opts.look
  const paint = palette(look, opts.hue, opts.self)

  /*
   * A costume with real artwork behind it draws from the sheet and skips everything below.
   *
   * Nothing is layered over it — no hair, no shirt colour, no `self` tint. Those exist to
   * compose *our* grids, and painting them onto a drawing somebody else made would be the
   * "costume drawn on a person" failure this whole branch avoids. The sheet is the sprite.
   */
  const dressed = costumeOf(look)

  if (dressed?.sheets) {
    const spec = opts.walking ? dressed.sheets.walk : dressed.sheets.idle

    if (sheetReady(spec)) {
      // The sheet's own cycle, at the pace the grid sprites flip: `phase` already advances on a
      // shared ~6Hz clock, so costumes and hand-drawn bodies stay in step with each other.
      const at = opts.walking ? opts.phase : 0
      const y = py + size * 0.35 + (opts.sitting ? size * 0.2 : 0)

      if (!opts.sitting) {
        drawSheetFrame(ctx, spec, at, sheetRow(who.facing), px, y, size)

        return
      }

      // Sitting: the same sink-and-clip the grid sprites get, for the same reason — there is no
      // seated frame on the sheet either.
      const h = size * spec.scale

      ctx.save()
      ctx.beginPath()
      ctx.rect(px - h, y - h, h * 2, h * 0.82)
      ctx.clip()
      drawSheetFrame(ctx, spec, 0, sheetRow(who.facing), px, y, size)
      ctx.restore()

      return
    }
  }

  const key = [
    'trainer',
    dir,
    frame,
    look.body,
    look.hair,
    look.hair_color,
    look.skin,
    look.outfit,
    look.costume,
    look.outfit === 'auto' ? Math.round(opts.hue) : '',
    opts.self ? 1 : 0,
  ].join('|')

  const costume = costumeOf(look)

  const canvas = sprite(key, SPRITE_SIZE, SPRITE_SIZE, [
    { rows: bodyRows(look, dir, frame), palette: paint },
    // Under a hood or a helmet there is no hair to draw — painting it anyway would put a fringe
    // over the mask.
    ...(costume?.covered ? [] : [{ rows: HAIR_ART[look.hair][dir], palette: paint }]),
  ])

  // A shade over one tile, so a sprite reads as a person in a room rather than as a tile.
  const drawn = size * 1.5

  if (!opts.sitting) return blit(ctx, canvas, px, py + size * 0.35, drawn, drawn, who.facing === 'left')

  /*
   * Sitting, from a sprite sheet that has no sitting frame.
   *
   * There is no honest way to draw a seated 16×16 trainer without a seated 16×16 trainer, so
   * this doesn't try to invent one. It does the two things that actually read as "in the chair"
   * at this size: the figure drops a few pixels, so their head sits below where a standing
   * person's would be and level with the back of the couch; and the bottom fifth is clipped
   * away, so no legs dangle through the seat. Behind the clip the sprite is unchanged, which is
   * why every costume and hairstyle sits down correctly without artwork of its own.
   */
  const sunk = size * 0.2
  const legs = drawn * 0.18

  ctx.save()
  ctx.beginPath()
  ctx.rect(px - drawn / 2, py - drawn + size * 0.35 + sunk, drawn, drawn - legs)
  ctx.clip()
  blit(ctx, canvas, px, py + size * 0.35 + sunk, drawn, drawn, who.facing === 'left')
  ctx.restore()
}

/**
 * The same sprite, standing still and facing the viewer — for the picker, where what matters is
 * the face rather than the room.
 */
export function drawPortrait(
  ctx: CanvasRenderingContext2D,
  look: AvatarLook,
  px: number,
  py: number,
  size: number,
  hue = 210,
): void {
  drawTrainer(ctx, { facing: 'down' }, px, py, size, { look, hue, self: true, walking: false, phase: 0 })
}
