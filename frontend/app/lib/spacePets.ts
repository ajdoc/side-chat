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
import { blit, sprite } from './pixelSprite'

export type PetKind = 'leafling' | 'emberpup' | 'shellow' | 'sprigling' | 'cinderkit' | 'snapling'

export interface PetInfo {
  label: string
  element: 'grass' | 'fire' | 'water'
  /** Which trio it belongs to — the picker groups them. */
  region: 'first' | 'second'
  blurb: string
}

export const PETS: Record<PetKind, PetInfo> = {
  leafling: { label: 'Leafling', element: 'grass', region: 'first', blurb: 'Sprouts a bud when it is pleased with itself.' },
  emberpup: { label: 'Emberpup', element: 'fire', region: 'first', blurb: 'Its tail flame goes low when it is sulking.' },
  shellow: { label: 'Shellow', element: 'water', region: 'first', blurb: 'Retreats into its shell at loud noises.' },
  sprigling: { label: 'Sprigling', element: 'grass', region: 'second', blurb: 'The leaf on its head is bigger than its head.' },
  cinderkit: { label: 'Cinderkit', element: 'fire', region: 'second', blurb: 'Warm to hold. Sheds sparks when it runs.' },
  snapling: { label: 'Snapling', element: 'water', region: 'second', blurb: 'Snaps at nothing in particular, constantly.' },
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

const ART: Record<PetKind, Record<PetDir, string[]>> = {
  leafling: LEAFLING,
  emberpup: EMBERPUP,
  shellow: SHELLOW,
  sprigling: SPRIGLING,
  cinderkit: CINDERKIT,
  snapling: SNAPLING,
}

/** Body, lit, shaded, belly, accent, accent-lit — six colours is the whole look of a creature. */
const PALETTE: Record<PetKind, Record<string, string>> = {
  leafling: paint('#6f9a4e', '#8ab866', '#4f7238', '#dfe9c4', '#4f9a3c', '#7cc457'),
  emberpup: paint('#e0894a', '#f0ab6d', '#b0642f', '#ffe1b8', '#ef6a2c', '#ffc45c'),
  shellow: paint('#5fa3d6', '#86c1e8', '#3f7bab', '#dff0fb', '#c88f4e', '#e2b477'),
  sprigling: paint('#a8cf7a', '#c3e39b', '#7ba354', '#f0f7d8', '#59a83f', '#8ada63'),
  cinderkit: paint('#d9695a', '#ee8b7b', '#a5453a', '#ffd9c4', '#f2a03c', '#ffd166'),
  snapling: paint('#4f9ea0', '#74c0c1', '#357477', '#e3f4f2', '#e8c25a', '#f7dd94'),
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

  const dir: PetDir = facing === 'up' ? 'up' : facing === 'down' ? 'down' : 'right'
  const canvas = sprite(`pet|${kind}|${dir}`, 16, 16, [{ rows: art[dir], palette: PALETTE[kind] }])

  // Two thirds of a tile: small enough to read as a companion rather than a second person.
  const drawn = size * 1.05
  const bob = moving ? Math.round(Math.sin(t * 9) * 0.5 + 0.5) * (size * 0.06) : 0

  ctx.beginPath()
  ctx.ellipse(px, py + size * 0.28, size * 0.22, size * 0.08, 0, 0, Math.PI * 2)
  ctx.fillStyle = 'rgb(0 0 0 / 0.15)'
  ctx.fill()

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
