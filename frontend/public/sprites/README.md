# Sheet sprites

Artwork that arrives as a PNG rather than being authored in code. Everything else in a Side
Space is a grid of characters in `app/lib/space*.ts`; this is for sprites somebody drew.

Files here are served from the site root, so `sprites/espurr/Walk-Anim.png` is fetched as
`/sprites/espurr/Walk-Anim.png`. See `app/lib/spriteSheet.ts`, which is the only thing that
reads them.

## Layout

PMD / SpriteCollab sheets, unchanged from how they're distributed:

- **frames left to right** — 4 per direction for Espurr, and the count is declared per sheet
  (`columns` in the `SheetSpec`) rather than guessed.
- **eight directions top to bottom**, in the standard order: Down, Down-Right, Right, Up-Right,
  Up, Up-Left, Left, Down-Left. The room only draws four of them, but the row indices have to be
  right, so the whole order matters.

Frame size is derived from the image — width ÷ columns, height ÷ 8 — so a sheet re-exported at a
different resolution keeps working and no `AnimData.xml` is needed.

Only the `-Anim` sheet is read. The `-Offsets` and `-Shadow` sheets that ship alongside it can be
kept here for reference or left out; offsets position held items, and the room draws its own
shadow under every sprite already.

## Espurr

    sprites/espurr/Idle-Anim.png
    sprites/espurr/Walk-Anim.png

Used by both the Espurr pet (`app/lib/spacePets.ts`) and the Espurr Suit costume
(`app/lib/spaceAvatar.ts`) — one creature, one set of artwork.

## Espurr Vessel

    sprites/espurr-vessel/Idle-Anim.png
    sprites/espurr-vessel/Walk-Anim.png

The robed and masked version, shared the same way by the `espurr_vessel` pet and costume. Same
layout as above: 4 frames per direction, eight directions top to bottom.

## Espurr Pikachu

    sprites/espurr-pickachu/Idle-Anim.png
    sprites/espurr-pickachu/Walk-Anim.png

The yellow-hooded version, shared the same way by the `espurr_pickachu` pet and costume.

## Espurr Wing

    sprites/espurr-winged-gundam/Idle-Anim.png
    sprites/espurr-winged-gundam/Walk-Anim.png

The one in winged mobile armour, shared by the `espurr_winged_gundam` pet and costume and by the
`plush_gundam` furniture.

## Cubone Vessel

    sprites/cubone-vessel/Idle-Anim.png
    sprites/cubone-vessel/Walk-Anim.png

A different creature rather than another Espurr outfit, shared by the `cubone_vessel` pet and
costume and by the `plush_cubone` furniture.

## Stills — furniture that doesn't move

    sprites/decor/iron-throne.png

Not a sheet. A `StillSpec` is one image with one view: no frames, no eight directions, no
`columns`. Most furniture is this shape — a throne, a statue, a sign — and making it pretend to
be a one-frame animation would mean a PNG that is seven-eighths empty.

Requirements are only two: **transparent background** (same as below) and **taller than it is
wide is fine** — `scale` says how many tiles tall it draws and the width follows from the
image's own aspect ratio, anchored at the bottom centre of the piece's footprint. So a throne on
a 2×2 footprint drawn at `scale: 3` rises a whole tile above the back of it, and somebody sitting
on the front row is drawn in front of the seat with the blades still over their head.

Declared next to the fallback art in `app/lib/spaceDecor.ts`:

    still: { src: 'decor/iron-throne.png', scale: 2.8 }

Pick `scale` so the *width* that follows lands on the footprint: the throne is 458×623 on a 2×2
piece, so 2.8 tiles tall is 2.06 wide — it fills its floor space instead of overhanging it, and
the extra 0.8 goes up. Missing files fall back to the character grid, exactly as sheets do.

## Preparing a generated sheet

Sheets that come out of an image generator need two passes before they're committed, and neither
of them is a resize — **leave the resolution alone.** These are drawn at 624×1664 and scaled down
by the browser at draw time; downscaling the file instead throws away the detail that survives
that and the sprite goes soft.

1. **Key out the background.** The generator paints its own transparency checkerboard *into* the
   pixels, so the file arrives fully opaque and the room draws a grey-and-white card behind the
   character. Flood-fill the light greyscale background in from the borders rather than testing
   every pixel for it — that keeps the white *inside* the sprite (eye highlights, the cream ear
   linings) from being punched out too — and take one or two passes of the blended edge pixels
   with it, or the sprite keeps a pale halo.
2. **Quantise to a small palette and save indexed.** The upscale is noisy — 80,000 distinct
   colours for artwork that is really about two dozen. Median-cut to ~40 and write a colour-type-3
   PNG. This is a quality win as much as a size one: flat colour reads as pixel art where the
   noise reads as a JPEG of pixel art.

Together those took the Espurr Pikachu sheet from 1.87 MB to 112 KB at full resolution.

Both passes are scripted — see [`scripts/sprites/`](../../scripts/sprites/README.md). They work
on stills as well as sheets: the iron throne arrived as a 1.73 MB screenshot with the
checkerboard painted into the pixels and came out 121 KB.

## Backgrounds must be transparent

A sheet is drawn straight onto the room, so whatever is behind the sprite is drawn too: a frame
saved on white paper puts a white card under the character and over the floor. RGBA alone isn't
enough — the alpha has to actually be zero. If a sheet arrives opaque, key the background out
before dropping it in here rather than trying to fix it at draw time; the renderer has no way to
tell the paper from a pale part of the sprite.

**Missing files are not an error.** Each falls back to the hand-drawn 16×16 grid it shipped with,
so the room looks finished either way and adding artwork is dropping in a file rather than a
deploy that can half-succeed. A sheet that 404s is remembered as absent and not re-requested.

## Adding another

Add a `SheetSpec` — `{ name: 'thing/Walk', columns: 4, scale: 1.35 }` — next to the fallback art.
`scale` is how tall one frame draws in tiles, and it has to be by eye: a sheet's padding says
nothing about how big the creature should look standing in a room.
