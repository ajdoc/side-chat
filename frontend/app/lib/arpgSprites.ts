/**
 * What the dungeon's heroes and monsters actually look like.
 *
 * Authored as text and rasterised by {@link sprite}, exactly like the room's trainers, pets and
 * furniture — the same system, so the crawl doesn't invent a second way to draw a person. Each
 * grid is 16×16, one character per pixel, `.` transparent. See {@link file://./pixelSprite.ts}
 * for why the art lives in source and how the cache works.
 *
 * ## One silhouette, two layers
 *
 * A hero is a shared body plus a per-class overlay: the body says "person", the overlay says
 * *which* person — a pointed hat and a staff, a helm and a sword, a hood and a bow. That split is
 * what keeps eight classes from being eight full sprites to maintain, and it means a new class is
 * an overlay and a palette rather than a drawing.
 *
 * Second jobs deliberately reuse their first job's silhouette with a **richer palette**: a wizard
 * is a mage in better robes. Advancement should be legible across a dark room at a glance, and a
 * colour shift reads faster than a redesign — you can still tell instantly who the mage is.
 *
 * ## Facing
 *
 * Everything is drawn facing right and mirrored for left ({@link blit}'s `flip`). Four-way art
 * would be four times the authoring for a top-down view where the difference is mostly which
 * shoulder the weapon sits on, and the walk here is a drift rather than a march.
 */

import { blit, sprite, type SpriteLayer } from './pixelSprite'
import type { HeroJob, MonsterKind } from './arpgEngine'

const W = 16
const H = 16

/** The body every hero shares: head, tunic, legs, boots. Facing right. */
const BODY: string[] = [
  '................',
  '................',
  '.....ssss.......',
  '....ssssss......',
  '....sssess......',
  '....ssssss......',
  '.....ssss.......',
  '....bbbbbb......',
  '...bbbbbbbb.....',
  '...bbbbbbbb.....',
  '...abbbbbba.....',
  '....llllll......',
  '....ll..ll......',
  '....ll..ll......',
  '...ooo..ooo.....',
  '................',
]

/**
 * The per-class overlay: headgear and whatever they're holding.
 *
 * Keyed by the *first* job of each line — a wizard wears the mage's overlay in wizard colours.
 */
const OVERLAY: Record<string, string[]> = {
  swordsman: [
    '................',
    '.....hhhh.......',
    '....hhhhhh......',
    '................',
    '................',
    '................',
    '................',
    '..........w.....',
    '..........w.....',
    '.........gw.....',
    '.........gw.....',
    '..........w.....',
    '..........w.....',
    '................',
    '................',
    '................',
  ],
  crusader: [
    '................',
    '.....hhhh.......',
    '....hhhhhh......',
    '.....h..h.......',
    '................',
    '................',
    '.........ww.....',
    '........wwww....',
    '........wgww....',
    '........wwww....',
    '.........ww.....',
    '................',
    '................',
    '................',
    '................',
    '................',
  ],
  archer: [
    '................',
    '.....hhhh.......',
    '....hhhhhhh.....',
    '....hh...hh.....',
    '................',
    '................',
    '.........w......',
    '..........w.....',
    '..........w.....',
    '.........gw.....',
    '..........w.....',
    '..........w.....',
    '.........w......',
    '................',
    '................',
    '................',
  ],
  thief: [
    '................',
    '.....hhhh.......',
    '....hhhhhhh.....',
    '....hhh..hh.....',
    '................',
    '................',
    '................',
    '................',
    '.........ww.....',
    '..........w.....',
    '..........g.....',
    '................',
    '................',
    '................',
    '................',
    '................',
  ],
  mage: [
    '.......hh.......',
    '......hhhh......',
    '.....hhhhhh.....',
    '....hhhhhhhh....',
    '................',
    '................',
    '..........g.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '................',
    '................',
  ],
  priest: [
    '......hhhh......',
    '.....hhhhhh.....',
    '.....hhhhhh.....',
    '................',
    '................',
    '................',
    '..........g.....',
    '.........ggg....',
    '..........g.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '................',
    '................',
  ],
  necromancer: [
    '................',
    '.....hhhh.......',
    '....hhhhhhh.....',
    '....hh...hh.....',
    '....hh...hh.....',
    '................',
    '.........ggg....',
    '.........g.g....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '................',
    '................',
  ],
  druid: [
    '....h.....h.....',
    '....hh...hh.....',
    '.....hhhhh......',
    '................',
    '................',
    '................',
    '..........g.....',
    '.........gw.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '..........w.....',
    '................',
    '................',
  ],
}

/** Body colours per line — skin, tunic, legs, boots — with the second job's richer set. */
const PALETTE: Record<string, { first: Record<string, string>, second: Record<string, string> }> = {
  swordsman: {
    first: { s: '#e8b98a', e: '#1f2937', b: '#7f1d1d', a: '#e8b98a', l: '#44403c', o: '#292524', h: '#9ca3af', w: '#d1d5db', g: '#78350f' },
    second: { s: '#e8b98a', e: '#1f2937', b: '#b91c1c', a: '#e8b98a', l: '#57534e', o: '#1c1917', h: '#e5e7eb', w: '#fbbf24', g: '#92400e' },
  },
  crusader: {
    first: { s: '#e8b98a', e: '#1f2937', b: '#e5e7eb', a: '#e8b98a', l: '#9ca3af', o: '#4b5563', h: '#d1d5db', w: '#cbd5e1', g: '#facc15' },
    second: { s: '#e8b98a', e: '#1f2937', b: '#fef3c7', a: '#e8b98a', l: '#d1d5db', o: '#57534e', h: '#fde68a', w: '#fef9c3', g: '#f59e0b' },
  },
  archer: {
    first: { s: '#e8b98a', e: '#1f2937', b: '#166534', a: '#e8b98a', l: '#3f6212', o: '#422006', h: '#14532d', w: '#a16207', g: '#e5e7eb' },
    second: { s: '#e8b98a', e: '#1f2937', b: '#15803d', a: '#e8b98a', l: '#4d7c0f', o: '#57534e', h: '#166534', w: '#facc15', g: '#f8fafc' },
  },
  thief: {
    first: { s: '#e8b98a', e: '#f87171', b: '#312e81', a: '#e8b98a', l: '#1e1b4b', o: '#111827', h: '#1e1b4b', w: '#9ca3af', g: '#e5e7eb' },
    second: { s: '#e8b98a', e: '#ef4444', b: '#4c1d95', a: '#e8b98a', l: '#1e1b4b', o: '#0f172a', h: '#2e1065', w: '#c4b5fd', g: '#f8fafc' },
  },
  mage: {
    first: { s: '#e8b98a', e: '#1f2937', b: '#1d4ed8', a: '#e8b98a', l: '#1e3a8a', o: '#1e293b', h: '#1d4ed8', w: '#92400e', g: '#38bdf8' },
    second: { s: '#e8b98a', e: '#1f2937', b: '#4338ca', a: '#e8b98a', l: '#312e81', o: '#1e1b4b', h: '#6d28d9', w: '#a16207', g: '#22d3ee' },
  },
  priest: {
    first: { s: '#e8b98a', e: '#1f2937', b: '#f8fafc', a: '#e8b98a', l: '#e2e8f0', o: '#94a3b8', h: '#fef9c3', w: '#a16207', g: '#facc15' },
    second: { s: '#e8b98a', e: '#1f2937', b: '#fffbeb', a: '#e8b98a', l: '#fef3c7', o: '#d6d3d1', h: '#fde047', w: '#ca8a04', g: '#fde047' },
  },
  necromancer: {
    first: { s: '#d6d3d1', e: '#a3e635', b: '#1c1917', a: '#d6d3d1', l: '#0c0a09', o: '#0c0a09', h: '#292524', w: '#57534e', g: '#a3e635' },
    second: { s: '#e7e5e4', e: '#22c55e', b: '#18181b', a: '#e7e5e4', l: '#09090b', o: '#09090b', h: '#3f3f46', w: '#78716c', g: '#4ade80' },
  },
  druid: {
    first: { s: '#e8b98a', e: '#1f2937', b: '#3f6212', a: '#e8b98a', l: '#422006', o: '#292524', h: '#a16207', w: '#65a30d', g: '#bef264' },
    second: { s: '#e8b98a', e: '#1f2937', b: '#4d7c0f', a: '#e8b98a', l: '#57370c', o: '#1c1917', h: '#ca8a04', w: '#84cc16', g: '#d9f99d' },
  },
}

/**
 * Draw a hero at a canvas point, anchored at the feet.
 *
 * `line` is the first job of their line and `tier` how far along it they are — together those
 * pick the overlay and which of its two palettes to wear.
 */
export function drawHero(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  size: number,
  line: string,
  tier: number,
  facingLeft: boolean,
) {
  const key = PALETTE[line] ? line : 'swordsman'
  const palette = (PALETTE[key]!)[tier > 1 ? 'second' : 'first']

  const layers: SpriteLayer[] = [
    { rows: BODY, palette },
    { rows: OVERLAY[key] ?? OVERLAY.swordsman!, palette },
  ]

  blit(ctx, sprite(`arpg-hero-${key}-${tier > 1 ? 2 : 1}`, W, H, layers), x, y, size, size, facingLeft)
}

/** A fallen corpse — the same hero, on their face, drawn once per line rather than per frame. */
export function drawFallen(ctx: CanvasRenderingContext2D, x: number, y: number, size: number, line: string) {
  const key = PALETTE[line] ? line : 'swordsman'
  const palette = { ...(PALETTE[key]!).first, s: '#78716c', b: '#57534e', l: '#44403c', e: '#292524' }

  blit(ctx, sprite(`arpg-fallen-${key}`, W, H, [{ rows: FALLEN, palette }]), x, y, size, size, false)
}

const FALLEN: string[] = [
  '................',
  '................',
  '................',
  '................',
  '................',
  '................',
  '................',
  '................',
  '................',
  '................',
  '................',
  '...ss.bbbbbb....',
  '..ssss.bbbbbl...',
  '...ss.bbbbbbll..',
  '................',
  '................',
]

/** The bestiary, in the same 16×16 grid. Facing right, mirrored for left. */
const MONSTERS: Record<MonsterKind | 'wolf', string[]> = {
  // A small hunched imp with horns and a mean underbite.
  fallen: [
    '................',
    '................',
    '................',
    '....h......h....',
    '....hh....hh....',
    '.....bbbbbb.....',
    '....bbbbbbbb....',
    '....beb..beb....',
    '....bbbbbbbb....',
    '.....bwbwbw.....',
    '....bbbbbbbb....',
    '...bb.bbbb.bb...',
    '...b..bbbb..b...',
    '......bb.bb.....',
    '.....oo...oo....',
    '................',
  ],
  // Wings out, body small — the silhouette is almost entirely wing.
  bat: [
    '................',
    '................',
    '................',
    '..h..........h..',
    '.hhh...bb...hhh.',
    'hhhhh.bbbb.hhhhh',
    'hhhhhbbeebbhhhhh',
    '.hhh.bbbbbb.hhh.',
    '..h..bbwwbb..h..',
    '......bbbb......',
    '.......bb.......',
    '................',
    '................',
    '................',
    '................',
    '................',
  ],
  // Ribs, a grin, and a rusty blade.
  skeleton: [
    '................',
    '................',
    '.....bbbb.......',
    '....bbbbbb......',
    '....beb.beb.....',
    '....bbbbbb......',
    '.....bwwb.......',
    '......bb........',
    '....bbbbbb..w...',
    '...b.bbbb.b.w...',
    '...b.bbbb.bgw...',
    '....bbbbbb..w...',
    '.....bb.bb......',
    '.....bb.bb......',
    '....bbb.bbb.....',
    '................',
  ],
  // Lumpen, lopsided, one arm hanging lower than the other.
  zombie: [
    '................',
    '................',
    '.....bbbb.......',
    '....bbbbbbb.....',
    '....beb.bb......',
    '....bbbbbbb.....',
    '.....bwbb.......',
    '....bbbbbb......',
    '...bbbbbbbb.....',
    '..gbbbbbbbbg....',
    '..g.bbbbbb..g...',
    '..g.bbbbbb..g...',
    '....ll..ll......',
    '....ll..ll......',
    '...ooo..ooo.....',
    '................',
  ],
  // Twice anything else's mass: horns, shoulders, and a weapon it drags.
  lord: [
    '..h..........h..',
    '..hh........hh..',
    '...hh..bb..hh...',
    '....bbbbbbbb....',
    '...bbeebbeebb...',
    '...bbbbbbbbbb...',
    '....bbwwwwbb....',
    '..gbbbbbbbbbbg..',
    '.gggbbbbbbbbggg.',
    '.gg.bbbbbbbb.gg.',
    '.g..bbbbbbbb..g.',
    '....bbbbbbbb....',
    '....bbb..bbb....',
    '...bbb....bbb...',
    '..ooo......ooo..',
    '................',
  ],
  // Low, long, and moving — a summoned wolf.
  wolf: [
    '................',
    '................',
    '................',
    '................',
    '..........h..h..',
    '.........hhhhh..',
    '........bbbbbb..',
    '..bbbbbbbbebbb..',
    '.bbbbbbbbbbbww..',
    'gbbbbbbbbbbbb...',
    '.bb.bb..bb.bb...',
    '.bb.bb..bb.bb...',
    '.oo.oo..oo.oo...',
    '................',
    '................',
    '................',
  ],
}

/** Each monster's colours: body, eye, horn/fur, teeth, and whatever it's carrying. */
const MONSTER_PALETTE: Record<MonsterKind | 'wolf', Record<string, string>> = {
  fallen: { b: '#b45309', e: '#fef08a', h: '#78350f', w: '#fde68a', o: '#78350f', g: '#7c2d12' },
  bat: { b: '#7c3aed', e: '#fca5a5', h: '#5b21b6', w: '#f8fafc', o: '#4c1d95', g: '#4c1d95' },
  skeleton: { b: '#e5e7eb', e: '#0f172a', h: '#cbd5e1', w: '#94a3b8', o: '#cbd5e1', g: '#78350f', l: '#cbd5e1' },
  zombie: { b: '#4d7c0f', e: '#facc15', h: '#365314', w: '#d9f99d', o: '#292524', g: '#3f6212', l: '#44403c' },
  lord: { b: '#dc2626', e: '#fde047', h: '#7f1d1d', w: '#fef08a', o: '#450a0a', g: '#991b1b' },
  wolf: { b: '#57534e', e: '#f87171', h: '#44403c', w: '#f8fafc', o: '#292524', g: '#78716c' },
}

/** Draw a monster (or a summoned wolf), anchored at its feet. */
export function drawBeast(
  ctx: CanvasRenderingContext2D,
  kind: MonsterKind | 'wolf',
  x: number,
  y: number,
  size: number,
  facingLeft: boolean,
  hurt = false,
) {
  const rows = MONSTERS[kind] ?? MONSTERS.fallen
  const base = MONSTER_PALETTE[kind] ?? MONSTER_PALETTE.fallen

  // A hit washes the whole thing pale for a few frames — the flinch has to read at 16 pixels,
  // and a separate palette is one more cached sprite rather than a per-frame filter.
  const palette = hurt
    ? Object.fromEntries(Object.keys(base).map(slot => [slot, '#fecaca']))
    : base

  blit(ctx, sprite(`arpg-mob-${kind}-${hurt ? 'h' : 'n'}`, W, H, [{ rows, palette }]), x, y, size, size, facingLeft)
}

/** A raised skeleton is the bestiary's skeleton in a friendlier colour — same bones. */
export function drawMinion(
  ctx: CanvasRenderingContext2D,
  kind: string,
  x: number,
  y: number,
  size: number,
  facingLeft: boolean,
  mine: boolean,
) {
  const rows = kind === 'wolf' ? MONSTERS.wolf : MONSTERS.skeleton
  const tint = mine ? '#4ade80' : '#60a5fa'

  blit(ctx, sprite(`arpg-minion-${kind}-${mine ? 'm' : 't'}`, W, H, [{
    rows,
    palette: { b: tint, e: '#0f172a', h: tint, w: '#f8fafc', o: tint, g: '#78350f', l: tint },
  }]), x, y, size, size, facingLeft)
}
