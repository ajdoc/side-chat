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

export type PetKind = 'leafling' | 'emberpup' | 'shellow' | 'sprigling' | 'cinderkit' | 'snapling' | 'espurr' | 'espurr_vessel' | 'espurr_pickachu' | 'espurr_winged_gundam' | 'cubone_vessel'

export interface PetInfo {
  label: string
  // Only the first three are in the battle's type triangle; the guests sit outside it and take
  // no bonus either way, which is what `BEATS[...] ?? null` on the server already assumes.
  element: 'grass' | 'fire' | 'water' | 'psychic' | 'ground'
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
  // The same creature under a hooded robe and a painted mask, which is enough of a different
  // silhouette to be its own pet rather than a recolour of the one above.
  espurr_vessel: {
    label: 'Espurr Vessel',
    element: 'psychic',
    region: 'guest',
    blurb: 'Robed, masked, and staring through it anyway.',
    sheets: {
      idle: { name: 'espurr-vessel/Idle', columns: 4, scale: 1.35 },
      walk: { name: 'espurr-vessel/Walk', columns: 4, scale: 1.35 },
    },
  },
  // The third of the visitor's outfits: the same creature in a yellow hood with round black
  // ear-tips. A costume rather than a species, which is why the ears and the stare are still
  // the ones above.
  espurr_pickachu: {
    label: 'Espurr Pikachu',
    element: 'psychic',
    region: 'guest',
    blurb: 'Yellow hood, red cheeks, same unblinking stare.',
    sheets: {
      idle: { name: 'espurr-pickachu/Idle', columns: 4, scale: 1.35 },
      walk: { name: 'espurr-pickachu/Walk', columns: 4, scale: 1.35 },
    },
  },
  // The visitor in a suit of winged mobile armour. Wider than anything else in the picker, and
  // scaled up a little to match — the wings are most of the silhouette and they read as clutter
  // if it's drawn at the size of a small grey cat.
  espurr_winged_gundam: {
    label: 'Espurr Wing',
    element: 'psychic',
    region: 'guest',
    blurb: 'Winged armour, twin vents, and a stare behind the visor.',
    sheets: {
      idle: { name: 'espurr-winged-gundam/Idle', columns: 4, scale: 1.5 },
      walk: { name: 'espurr-winged-gundam/Walk', columns: 4, scale: 1.5 },
    },
  },
  // Not the visitor at all — a second creature, in the same order's robe as the Espurr Vessel.
  // Ground rather than psychic: the first pet in the guest row that isn't a recolour of the
  // others, and the element is the clearest way to say so in the picker.
  cubone_vessel: {
    label: 'Cubone Vessel',
    element: 'ground',
    region: 'guest',
    blurb: 'Wears the skull, carries the bone, keeps its own counsel.',
    sheets: {
      idle: { name: 'cubone-vessel/Idle', columns: 4, scale: 1.35 },
      walk: { name: 'cubone-vessel/Walk', columns: 4, scale: 1.35 },
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

/**
 * Espurr Vessel: the same grey creature in a black hooded robe, wearing a pale mask painted
 * with a sigil. Extra slots on top of the shared six: `K` the robe, `g` its gold trim, `M` the
 * mask and `k` the marks on it. Facing away there's no mask at all — just the hood and the tail,
 * which is the whole point of the back view.
 */
const ESPURR_VESSEL: Record<PetDir, string[]> = {
  down: [
    '................',
    '..o..........o..',
    '..oA........Ao..',
    '..oAAo....oAAo..',
    '..oAAAo..oAAAo..',
    '..oBBBBBBBBBBo..',
    '.oBBoMMMMMMoBBo.',
    '.oBoMkMkkMkMoBo.',
    '.oBoMkMMMMkMoBo.',
    '.oBoMMkMMkMMoBo.',
    '.oKKoMMkkMMoKKo.',
    '.oKgKKKKKKKKgKo.',
    '..oKKKgKKgKKKo..',
    '..oKKKKKKKKKKo..',
    '...oKKo..oKKo...',
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
    '.oKKKKKKKKKKKKo.',
    '.oKKKKKgKKKKKKo.',
    '.oKKKKgggKKKKKo.',
    '.oKKKKKgKKKKKKo.',
    '.oKgKKKgKKKKgKo.',
    '..oKKKKKKKKKKo..',
    '..oKKKKKKKKDDo..',
    '...oKKo..oKKo...',
    '...ooo....ooo...',
  ],
  right: [
    '................',
    '....o......o....',
    '....oAo...oAo...',
    '....oAAo.oAAo...',
    '...oAAAoAAAo....',
    '...oBBBBBBBo....',
    '..oBoMMMMMBo....',
    '..oBoMkkMMBo....',
    '..oBoMkMMMBo....',
    '..oKoMMkMMKo....',
    '..oKKKKKKKKo..D.',
    '..oKgKKKKgKoDDD.',
    '...oKKKKKKKo....',
    '...oKKKKKKKo....',
    '...oKKo.oKKo....',
    '...ooo..ooo.....',
  ],
}

/**
 * Espurr Pikachu: the same creature in a yellow hood, with the ear-tips blacked out and a red
 * cheek either side. Extra slot on top of the shared six: `C`, the cheek. It is the cheeks that
 * do the work here — a yellow recolour on its own just reads as a differently-lit Espurr.
 */
const ESPURR_PICKACHU: Record<PetDir, string[]> = {
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
    '.oCBBBBmmBBBBCo.',
    '.oCWWWWWWWWWWCo.',
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
    '..omBBBBBBBCo...',
    '..oBWWWWWWWCo...',
    '...oBBBBBBBo....',
    '...oDo..oDo.....',
    '...oDo..oDo.....',
    '...ooo..ooo.....',
  ],
}

/**
 * Espurr Wing: the visitor inside a suit of winged mobile armour. The wings are the silhouette —
 * they take the space the ears do on every other version of it — and the visor is the face, so
 * the stare is one green slot rather than two lilac discs. Extra slots on top of the shared six:
 * `V` the fin, `R` the vents.
 */
const ESPURR_WINGED_GUNDAM: Record<PetDir, string[]> = {
  down: [
    '................',
    '.....oVVVVo.....',
    '....oBLLLLBo....',
    '...oBBBBBBBBo...',
    '.oAoBEBBBBEBoAo.',
    'oAAoBBBmmBBBoAAo',
    'oAAAoBBBBBBoAAAo',
    'oAAAoRWWWWRoAAAo',
    '.oAAoWWWWWWoAAo.',
    '..oAoBWWWWBoAo..',
    '...oBBBBBBBBo...',
    '...oBBBBBBBBo...',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  up: [
    '................',
    '.....oBBBBo.....',
    '....oBBBBBBo....',
    '...oBBBBBBBBo...',
    '.oAoBBBBBBBBoAo.',
    'oAAoBBBBBBBBoAAo',
    'oAAAoBBBBBBoAAAo',
    'oAAAoRBBBBRoAAAo',
    '.oAAoBBBBBBoAAo.',
    '..oAoBBBBBBoAo..',
    '...oBBBBBBBBo...',
    '...oBRRRRRRBo...',
    '....oBBBBBBo....',
    '....oDo..oDo....',
    '....oDo..oDo....',
    '....ooo..ooo....',
  ],
  right: [
    '................',
    '....oVVVo.......',
    '...oBLLLBo......',
    '..oBBBBBBBo.....',
    '..oBEBBBBBo.....',
    '.oAoBBBBBBoAo...',
    'oAAoRWWWWBoAAo..',
    'oAAoWWWWWBoAAo..',
    '.oAoBWWWWBoAo...',
    '..oBBBBBBBo.....',
    '..oBBBBBBBo.....',
    '..oBBBBBBBo.....',
    '...oBBBBBo......',
    '...oDo.oDo......',
    '...oDo.oDo......',
    '...ooo.ooo......',
  ],
}

/**
 * Cubone Vessel: a small robed thing wearing a skull, with a bone held at its side.
 *
 * The skull is the whole sprite — it takes the top half and the horns break the silhouette, so
 * it reads before any of the detail does. Facing away there's no skull and no bone hand, just
 * the hood and the club poking out past the robe, which is the same trick the Espurr Vessel's
 * back view plays. Extra slots on top of the shared six: `K` the robe, `S` the skull, `k` the
 * red sigils painted on it, `n` the bone.
 */
const CUBONE_VESSEL: Record<PetDir, string[]> = {
  down: [
    '................',
    '.....o....o.....',
    '....oSo..oSo....',
    '....oSSooSSo....',
    '...oSSSSSSSSo...',
    '..oSSkSSSSkSSo..',
    '..oSSSSkkSSSSo..',
    '..oSSEkSSkESSo..',
    '..oSSSSkkSSSSo..',
    '..noSSSSSSSSon..',
    '.nnoKKKKKKKKonn.',
    '.nnoKKKkkKKKonn.',
    '..noKKKKKKKKon..',
    '...oKKo..oKKo...',
    '...oKKo..oKKo...',
    '...ooo....ooo...',
  ],
  up: [
    '................',
    '.....o....o.....',
    '....oSo..oSo....',
    '....oSSooSSo....',
    '...oSSSSSSSSo...',
    '..oSSSSSSSSSSo..',
    '..oSSSSSSSSSSo..',
    '..oKKKKKKKKKKo..',
    '..oKKKKKKKKKKo..',
    '..oKKKKKKKKKKon.',
    '.noKKKKkkKKKKonn',
    '.nnoKKKKKKKKon..',
    '..noKKKKKKKKo...',
    '...oKKo..oKKo...',
    '...oKKo..oKKo...',
    '...ooo....ooo...',
  ],
  right: [
    '................',
    '.....o...o......',
    '....oSo.oSo.....',
    '...oSSooSSo.....',
    '...oSSSSSSSo....',
    '..oSSkSSSSSo....',
    '..oSSSkkSSSo....',
    '..oSEkSSSSSo....',
    '..oSSSkSSSSo....',
    '..oSSSSSSSSo....',
    '...oKKKKKKo.nn..',
    '...oKKKkKKonnn..',
    '...oKKKKKKonn...',
    '...oKKo.oKKo....',
    '...oKKo.oKKo....',
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
  espurr_vessel: ESPURR_VESSEL,
  espurr_pickachu: ESPURR_PICKACHU,
  espurr_winged_gundam: ESPURR_WINGED_GUNDAM,
  cubone_vessel: CUBONE_VESSEL,
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
  // Same fur, but the robe is the sprite: near-black with a thin gold trim, and a bone mask
  // that has to stay the brightest thing on it or the face disappears at this size.
  espurr_vessel: {
    ...paint('#8f8ba0', '#a9a5ba', '#6a6678', '#e8e6ee', '#ded6bd', '#f2ecd9'),
    K: '#221d28',
    g: '#b98f4c',
    M: '#efe7d2',
    k: '#1a1620',
  },
  // Yellow all the way through, with the ear-tips dark enough to read as a silhouette against
  // it. The cheek is the only saturated red on the creature, which is what makes it the thing
  // you see first.
  espurr_pickachu: {
    ...paint('#f2c53d', '#ffdd6b', '#c1912a', '#fdefc0', '#3a3330', '#5a4f45'),
    I: '#2a2320',
    P: '#fff6da',
    C: '#e0503c',
  },
  // Off-white armour rather than white, so the lit slot has somewhere brighter to go and the
  // thing doesn't disappear against a pale floor. The visor is the only green on any of them.
  espurr_winged_gundam: {
    ...paint('#dfe2ea', '#f4f6fa', '#9aa0b0', '#2f4f9e', '#eef1f7', '#ffffff'),
    E: '#5fd08a',
    V: '#f2c53d',
    R: '#c2352f',
  },
  // Bone and near-black, and one red doing all the talking. The robe is a shade off the outline
  // rather than the same colour as it, or the whole lower half reads as a single blob.
  cubone_vessel: {
    ...paint('#2a2630', '#3b3644', '#1d1a23', '#d9d2c0', '#e8e2d2', '#f5f1e6'),
    K: '#241f2b',
    S: '#e3ddcb',
    k: '#a32b28',
    n: '#efe9d9',
    E: '#1c1a26',
  },
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
