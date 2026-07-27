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

**Missing files are not an error.** Each falls back to the hand-drawn 16×16 grid it shipped with,
so the room looks finished either way and adding artwork is dropping in a file rather than a
deploy that can half-succeed. A sheet that 404s is remembered as absent and not re-requested.

## Adding another

Add a `SheetSpec` — `{ name: 'thing/Walk', columns: 4, scale: 1.35 }` — next to the fallback art.
`scale` is how tall one frame draws in tiles, and it has to be by eye: a sheet's padding says
nothing about how big the creature should look standing in a room.
