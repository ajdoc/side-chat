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
import { blit, sprite } from './pixelSprite'

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

// --- assembling one ---

function bodyRows(look: AvatarLook, dir: SpriteDir, frame: 0 | 1): string[] {
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

  return {
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
  opts: { look: AvatarLook, hue: number, self: boolean, walking: boolean, phase: number },
): void {
  const dir: SpriteDir = who.facing === 'up' ? 'up' : who.facing === 'down' ? 'down' : 'right'
  const frame: 0 | 1 = opts.walking && opts.phase % 2 === 1 ? 1 : 0
  const look = opts.look
  const paint = palette(look, opts.hue, opts.self)

  const key = [
    'trainer',
    dir,
    frame,
    look.body,
    look.hair,
    look.hair_color,
    look.skin,
    look.outfit,
    look.outfit === 'auto' ? Math.round(opts.hue) : '',
    opts.self ? 1 : 0,
  ].join('|')

  const canvas = sprite(key, SPRITE_SIZE, SPRITE_SIZE, [
    { rows: bodyRows(look, dir, frame), palette: paint },
    { rows: HAIR_ART[look.hair][dir], palette: paint },
  ])

  // A shade over one tile, so a sprite reads as a person in a room rather than as a tile.
  const drawn = size * 1.5

  blit(ctx, canvas, px, py + size * 0.35, drawn, drawn, who.facing === 'left')
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
