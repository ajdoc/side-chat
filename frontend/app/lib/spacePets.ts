/**
 * The little creature trotting along behind you.
 *
 * Six of them, in two trios of grass/fire/water — the shape of a starter line-up, because that
 * is the thing this is quoting. The designs themselves are original: a leaf-budded pup, an
 * ember-tailed lizard, a shell-backed paddler, and a lighter, rounder, sillier three after them.
 * As with the trainers, what's borrowed is the idiom (16×16, four colours and an outline, a
 * silhouette you can read at a glance) rather than any particular creature.
 *
 * ## Why they bob instead of walking
 *
 * A walk cycle needs a second frame per direction, which is another eighteen grids to draw and
 * keep in step. A companion this size reads perfectly well with a one-pixel vertical bob on a
 * shared clock — it's what a small thing hurrying after you looks like anyway — and it costs a
 * line of arithmetic rather than a page of art. The trainers get real frames because they're
 * what you watch; the pet is what you notice.
 */

import type { Facing } from './spaceMapEngine'
import type { SheetSpec } from './spriteSheet'
import { blit, sprite } from './pixelSprite'
import { drawSheetFrame, sheetReady, sheetRow } from './spriteSheet'

export type PetKind = 'leafling' | 'emberpup' | 'shellow' | 'sprigling' | 'cinderkit' | 'snapling' | 'espurr'

export interface PetInfo {
  label: string
  element: 'grass' | 'fire' | 'water' | 'psychic'
  /** Which group it belongs to — the picker shows them in this order. */
  region: 'first' | 'second' | 'guest'
  blurb: string
  /**
   * Artwork from a sheet instead of from the grids below.
   *
   * When present *and* on disk, this is what's drawn — real frames, four of them, in whichever
   * of the eight directions applies. The character grid stays as the fallback, and is used
   * whenever the file isn't there: a creature drawn two ways is worth it so that adding artwork
   * is dropping in a PNG rather than a deploy that can half-fail.
   *
   * Two animations, because a sheet has no way to say "standing still" — `walk` runs while the
   * creature is chasing you and `idle` while it isn't, which is the distinction the bob was
   * standing in for.
   */
  sheets?: { idle: SheetSpec, walk: SheetSpec }
}

export const PETS: Record<PetKind, PetInfo> = {
  leafling: { label: 'Leafling', element: 'grass', region: 'first', blurb: 'Sprouts a bud when it is pleased with itself.' },
  emberpup: { label: 'Emberpup', element: 'fire', region: 'first', blurb: 'Its tail flame goes low when it is sulking.' },
  shellow: { label: 'Shellow', element: 'water', region: 'first', blurb: 'Retreats into its shell at loud noises.' },
  sprigling: { label: 'Sprigling', element: 'grass', region: 'second', blurb: 'The leaf on its head is bigger than its head.' },
  cinderkit: { label: 'Cinderkit', element: 'fire', region: 'second', blurb: 'Warm to hold. Sheds sparks when it runs.' },
  snapling: { label: 'Snapling', element: 'water', region: 'second', blurb: 'Snaps at nothing in particular, constantly.' },
  // Not one of the trios, and not pretending to be: its own row in the picker.
  espurr: {
    label: 'Espurr',
    element: 'psychic',
    region: 'guest',
    blurb: 'Stares. Keeps staring. Has not stopped staring.',
    // Drop the sheets in at `public/sprites/espurr/` and these take over from ESPURR below.
    sheets: {
      idle: { name: 'espurr/Idle', columns: 4, scale: 1.35 },
      walk: { name: 'espurr/Walk', columns: 4, scale: 1.35 },
    },
  },
}

export const PET_KEYS = Object.keys(PETS) as PetKind[]

export function isPetKind(value: unknown): value is PetKind {
  return typeof value === 'string' && value in PETS
}

/*
 * The artwork. Slots:
 *
 *   `.` transparent  `o` outline   `B` body      `L` body, lit
 *   `D` body, shaded `W` belly     `A` accent (leaf, flame, shell)
 *   `a` accent, lit  `E` eye       `m` mouth / nose
 *
 * Three directions each; `left` is `right` mirrored at blit time.
 */

type PetDir = 'down' | 'up' | 'right'

const LEAFLING: Record<PetDir, string[]> = {
  down: [
    '................',
    '................',
    '.......oo.......',
    '......oAAo......',
    '.....oAAAAo.....',
    '.....ooBBoo.....',
    '....oBBBBBBo....',
    '...oBBBBBBBBo...',
    '...oBEBBBBEBo...',
    '...oBBBmmBBBo...',
    '...oBWWWWWWBo...',
    '...oBWWWWWWBo...',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  up: [
    '................',
    '................',
    '.......oo.......',
    '......oAAo......',
    '.....oAAAAo.....',
    '.....ooBBoo.....',
    '....oBBBBBBo....',
    '...oBBBLLBBBo...',
    '...oBBLLLLBBo...',
    '...oBBLLLLBBo...',
    '...oBBBLLBBBo...',
    '...oBBBBBBBBo...',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  right: [
    '................',
    '................',
    '......oo........',
    '.....oAAo.......',
    '....oAAAAo......',
    '....ooBBoo......',
    '...oBBBBBBoo....',
    '..oBBBBBBBBBo...',
    '..oBEBBBBBBBo...',
    '..omBBBBBBBBo...',
    '..oBWWWWWWBBo...',
    '..oBWWWWWWBDo...',
    '...oBBBBBBBo....',
    '...oDo..oDo.....',
    '...oDo..oDo.....',
    '...ooo..ooo.....',
  ],
}

const EMBERPUP: Record<PetDir, string[]> = {
  down: [
    '................',
    '................',
    '.....oo..oo.....',
    '....oBBooBBo....',
    '....oBBBBBBo....',
    '...oBBBBBBBBo...',
    '...oBEBBBBEBo...',
    '...oBBBmmBBBo...',
    '...oBBBBBBBBo...',
    '....oWWWWWWo....',
    '....oWWWWWWo....',
    '....oBBBBBBo....',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  up: [
    '..........oo....',
    '.........oaao...',
    '.....oo..oAAo...',
    '....oBBooAAo....',
    '....oBBBBAo.....',
    '...oBBBBBBBo....',
    '...oBBBBBBBBo...',
    '...oBBLLLLBBo...',
    '...oBBLLLLBBo...',
    '....oBBBBBBo....',
    '....oBBBBBBo....',
    '....oBBBBBBo....',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  right: [
    '.............oo.',
    '............oao.',
    '....oo.....oAAo.',
    '...oBBo...oAAo..',
    '...oBBBBBBAo....',
    '..oBBBBBBBBo....',
    '..oBEBBBBBBo....',
    '..omBBBBBBBBo...',
    '..oBWWWWWWBBo...',
    '...oWWWWWWBBo...',
    '...oBBBBBBBBo...',
    '...oBBBBBBBo....',
    '...oBBBBBBo.....',
    '...oDo..oDo.....',
    '...oDo..oDo.....',
    '...ooo..ooo.....',
  ],
}

const SHELLOW: Record<PetDir, string[]> = {
  down: [
    '................',
    '................',
    '................',
    '.....oooooo.....',
    '....oBBBBBBo....',
    '...oBEBBBBEBo...',
    '...oBBBmmBBBo...',
    '...oBWWWWWWBo...',
    '..ooBBBBBBBBoo..',
    '..oAAAaaaaAAAo..',
    '..oAAaAAAAaAAo..',
    '..oAAAaaaaAAAo..',
    '...oAAAAAAAAo...',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  up: [
    '................',
    '................',
    '................',
    '.....oooooo.....',
    '....oBBBBBBo....',
    '....oBBBBBBo....',
    '...oBBBBBBBBo...',
    '..ooAAAAAAAAoo..',
    '..oAAaaaaaaAAo..',
    '..oAaAAAAAAaAo..',
    '..oAaAAAAAAaAo..',
    '..oAAaaaaaaAAo..',
    '...oAAAAAAAAo...',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  right: [
    '................',
    '................',
    '................',
    '...oooo.........',
    '..oBBBBoo.......',
    '..oBEBBBBoo.....',
    '..omBBBBBBBoo...',
    '..oBWWBBBBBBBo..',
    '...ooAAAAAAAAo..',
    '...oAaaaaaaAAo..',
    '...oAaAAAAAaAo..',
    '...oAAaaaaAAAo..',
    '....oAAAAAAAo...',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
}

const SPRIGLING: Record<PetDir, string[]> = {
  down: [
    '................',
    '......oooo......',
    '....ooAAAAoo....',
    '...oAAAAAAAAo...',
    '....oaAAAAao....',
    '......oAAo......',
    '.....ooooo......',
    '....oBBBBBBo....',
    '...oBEBBBBEBo...',
    '...oBBBmmBBBo...',
    '...oBWWWWWWBo...',
    '....oBBBBBBo....',
    '.....oBBBBo.....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  up: [
    '................',
    '......oooo......',
    '....ooAAAAoo....',
    '...oAAaaaaAAo...',
    '....oAAAAAAo....',
    '......oAAo......',
    '.....ooooo......',
    '....oBBBBBBo....',
    '...oBBLLLLBBo...',
    '...oBBLLLLBBo...',
    '...oBBBBBBBBo...',
    '....oBBBBBBo....',
    '.....oBBBBo.....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  right: [
    '................',
    '.....oooo.......',
    '...ooAAAAoo.....',
    '..oAAAAAAAAo....',
    '...oaAAAAao.....',
    '.....oAAo.......',
    '....ooooo.......',
    '...oBBBBBBoo....',
    '..oBEBBBBBBBo...',
    '..omBBBBBBBBo...',
    '..oBWWWWWWBBo...',
    '...oBBBBBBBo....',
    '....oBBBBBo.....',
    '...oDo..oDo.....',
    '...oDo..oDo.....',
    '...ooo..ooo.....',
  ],
}

const CINDERKIT: Record<PetDir, string[]> = {
  down: [
    '................',
    '...oo......oo...',
    '..oAAo....oAAo..',
    '..oABo....oBAo..',
    '...oBBooooBBo...',
    '...oBBBBBBBBo...',
    '..oBEBBBBBBEBo..',
    '..oBBBaammBBBo..',
    '..oBBBBmmBBBBo..',
    '...oWWWWWWWWo...',
    '...oWWWWWWWWo...',
    '...oBBBBBBBBo...',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  up: [
    '................',
    '...oo......oo...',
    '..oAAo....oAAo..',
    '..oABo....oBAo..',
    '...oBBooooBBo...',
    '...oBBBBBBBBo...',
    '..oBBBBBBBBBBo..',
    '..oBBLLLLLLBBo..',
    '..oBBLLaaLLBBo..',
    '...oBBLLLLBBo...',
    '...oBBBBBBBBo...',
    '...oBBBBBBBBo...',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  right: [
    '................',
    '...oo........oo.',
    '..oAAo......oAo.',
    '..oABo.....oAAo.',
    '...oBBoooooAAo..',
    '...oBBBBBBBAo...',
    '..oBEBBBBBBBo...',
    '..oaBBBBBBBBo...',
    '..omBBBBBBBBo...',
    '..oBWWWWWWBBo...',
    '...oWWWWWWBBo...',
    '...oBBBBBBBBo...',
    '....oBBBBBBo....',
    '...oDo..oDo.....',
    '...oDo..oDo.....',
    '...ooo..ooo.....',
  ],
}

const SNAPLING: Record<PetDir, string[]> = {
  down: [
    '................',
    '................',
    '....oAoooooAo...',
    '....oAAAAAAAo...',
    '...oBBBBBBBBBo..',
    '...oBEBBBBEBBo..',
    '...oBBBBBBBBBo..',
    '...oWmmmmmmmWo..',
    '...oWWWWWWWWWo..',
    '...oBBBBBBBBBo..',
    '....oBBBBBBBo...',
    '....oBBBBBBBo...',
    '.....oBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  up: [
    '................',
    '................',
    '....oAoooooAo...',
    '....oAAAAAAAo...',
    '...oBBBBBBBBBo..',
    '...oBBBBBBBBBo..',
    '...oBBaaaaaBBo..',
    '...oBBBaaaBBBo..',
    '...oBBaaaaaBBo..',
    '...oBBBBBBBBBo..',
    '....oBBBBBBBo...',
    '....oBBBBBBBo...',
    '.....oBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  right: [
    '................',
    '................',
    '..oAoooAo.......',
    '..oAAAAAAo......',
    '.oBBBBBBBBoo....',
    '.oBEBBBBBBBBo...',
    '.oBBBBBBBBBBo...',
    'ommmmmoBBBBBo...',
    'oWWWWWoBBBBBo...',
    '.oBBBBBBBBBBo...',
    '..oBBBBBBBBo....',
    '..oBBBBBBBo.....',
    '...oBBBBBo......',
    '...oDo..oDo.....',
    '...oDo..oDo.....',
    '...ooo..ooo.....',
  ],
}

/**
 * Espurr: small, grey, and staring. The ears are the silhouette — big, cream-lined and held
 * out — and the eyes are the rest of it: two flat lilac discs that take up most of the face and
 * never blink. At this size that's the whole character, so nothing else needed detailing.
 */
const ESPURR: Record<PetDir, string[]> = {
  down: [
    '................',
    '..o..........o..',
    '..oA........Ao..',
    '..oAAo....oAAo..',
    '..oAAAo..oAAAo..',
    '..oBBBBBBBBBBo..',
    '.oBBBBBBBBBBBBo.',
    '.oBLLLLLLLLLLBo.',
    '.oBIIPBBBBPIIBo.',
    '.oBIIIBBBBIIIBo.',
    '.oBBBBBmmBBBBBo.',
    '.oBWWWWWWWWWWBo.',
    '..oBBBBBBBBBBo..',
    '...oDo....oDo...',
    '...oDo....oDo...',
    '...ooo....ooo...',
  ],
  up: [
    '................',
    '..o..........o..',
    '..oA........Ao..',
    '..oAAo....oAAo..',
    '..oAAAo..oAAAo..',
    '..oBBBBBBBBBBo..',
    '.oBBBBBBBBBBBBo.',
    '.oBLLLLLLLLLLBo.',
    '.oBLLLLLLLLLLBo.',
    '.oBBBBBBBBBBBBo.',
    '.oBBBBBBBBBBBBo.',
    '.oBBBBBBBBBBBBo.',
    '..oBBBBBBBBBBo..',
    '...oDo....oDo...',
    '...oDo....oDo...',
    '...ooo....ooo...',
  ],
  right: [
    '................',
    '....o......o....',
    '....oAo...oAo...',
    '....oAAo.oAAo...',
    '...oAAAoAAAo....',
    '...oBBBBBBBo....',
    '..oBBBBBBBBBo...',
    '..oBLLLLLLLBo...',
    '..oBIIPBBBBBo...',
    '..oBIIIBBBBBo...',
    '..omBBBBBBBBo...',
    '..oBWWWWWWWBo...',
    '...oBBBBBBBo....',
    '...oDo..oDo.....',
    '...oDo..oDo.....',
    '...ooo..ooo.....',
  ],
}

const ART: Record<PetKind, Record<PetDir, string[]>> = {
  leafling: LEAFLING,
  emberpup: EMBERPUP,
  shellow: SHELLOW,
  sprigling: SPRIGLING,
  cinderkit: CINDERKIT,
  snapling: SNAPLING,
  espurr: ESPURR,
}

/** Body, lit, shaded, belly, accent, accent-lit — six colours is the whole look of a creature. */
const PALETTE: Record<PetKind, Record<string, string>> = {
  leafling: paint('#6f9a4e', '#8ab866', '#4f7238', '#dfe9c4', '#4f9a3c', '#7cc457'),
  emberpup: paint('#e0894a', '#f0ab6d', '#b0642f', '#ffe1b8', '#ef6a2c', '#ffc45c'),
  shellow: paint('#5fa3d6', '#86c1e8', '#3f7bab', '#dff0fb', '#c88f4e', '#e2b477'),
  sprigling: paint('#a8cf7a', '#c3e39b', '#7ba354', '#f0f7d8', '#59a83f', '#8ada63'),
  cinderkit: paint('#d9695a', '#ee8b7b', '#a5453a', '#ffd9c4', '#f2a03c', '#ffd166'),
  snapling: paint('#4f9ea0', '#74c0c1', '#357477', '#e3f4f2', '#e8c25a', '#f7dd94'),
  // The odd one out in the palette too: its accent is the cream of its ear-linings rather
  // than an element, and it needs the two eye slots the elemental six have no use for.
  espurr: { ...paint('#b9b6c4', '#d3d1dc', '#8f8b9e', '#e8e6ee', '#e6dfc9', '#f5efdd'), I: '#a982c9', P: '#f3e6ff' },
}

function paint(body: string, lit: string, shade: string, belly: string, accent: string, accentLit: string) {
  return {
    o: '#1c1a26',
    B: body,
    L: lit,
    D: shade,
    W: belly,
    A: accent,
    a: accentLit,
    E: '#1c1a26',
    m: '#8a4a4a',
  }
}

/**
 * Draw somebody's pet, standing on `px, py` — which is the tile *it* is on, not its owner's.
 *
 * The bob is a shared clock rather than a per-pet one, so a room full of them hops in time,
 * which looks deliberate where a room of independently-phased ones looks broken.
 */
export function drawPet(
  ctx: CanvasRenderingContext2D,
  kind: PetKind,
  facing: Facing,
  px: number,
  py: number,
  size: number,
  moving: boolean,
  t: number,
): void {
  const art = ART[kind]
  if (!art) return

  // The shadow goes down first either way — it belongs to the tile, not to the artwork.
  ctx.beginPath()
  ctx.ellipse(px, py + size * 0.28, size * 0.22, size * 0.08, 0, 0, Math.PI * 2)
  ctx.fillStyle = 'rgb(0 0 0 / 0.15)'
  ctx.fill()

  /*
   * Real frames, when there are any.
   *
   * No bob here: a sheet's walk cycle already has the creature moving, and adding a hop on top
   * of animation that was drawn by hand would fight it. The bob exists precisely because the
   * grid sprites have only one frame.
   */
  const sheets = PETS[kind].sheets

  if (sheets) {
    const spec = moving ? sheets.walk : sheets.idle

    if (sheetReady(spec)) {
      // ~6 frames a second, off the same shared clock the bob used, so a room full of them
      // steps in time rather than each on its own phase.
      const frame = Math.floor(t * 6)
      // The sheet has a real left-facing row, so nothing is mirrored: an asymmetric creature
      // stays asymmetric when it turns round.
      drawSheetFrame(ctx, spec, frame, sheetRow(facing), px, py + size * 0.3, size)

      return
    }
  }

  const dir: PetDir = facing === 'up' ? 'up' : facing === 'down' ? 'down' : 'right'
  const canvas = sprite(`pet|${kind}|${dir}`, 16, 16, [{ rows: art[dir], palette: PALETTE[kind] }])

  // Two thirds of a tile: small enough to read as a companion rather than a second person.
  const drawn = size * 1.05
  const bob = moving ? Math.round(Math.sin(t * 9) * 0.5 + 0.5) * (size * 0.06) : 0

  blit(ctx, canvas, px, py + size * 0.3 - bob, drawn, drawn, facing === 'left')
}

/** The same creature, facing the viewer, for the picker. */
export function drawPetPortrait(
  ctx: CanvasRenderingContext2D,
  kind: PetKind,
  px: number,
  py: number,
  size: number,
): void {
  drawPet(ctx, kind, 'down', px, py, size, false, 0)
}
