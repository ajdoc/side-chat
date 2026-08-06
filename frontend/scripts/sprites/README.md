# Preparing sprite artwork

Two scripts for getting a hand-drawn or generated image into `public/sprites/` in the state the
room wants it: transparent background, trimmed, and small. They're the mechanical half of the
checklist in [`public/sprites/README.md`](../../public/sprites/README.md) — read that for *why*
each step matters and what the room does with the result.

Pure `zlib` and `struct`, no Pillow and no ImageMagick, because neither was available and a
PNG is a short spec. 8-bit non-interlaced only, which is what everything here is.

## Usage

    python3 scripts/sprites/keyout.py   raw.png   keyed.png
    python3 scripts/sprites/quantise.py keyed.png final.png 64

For an **animation sheet**, add `--no-trim` to the first step:

    python3 scripts/sprites/keyout.py raw.png keyed.png --no-trim

**`keyout.py`** floods in from the borders over near-neutral bright pixels, takes two passes of
the blended rim so nothing keeps a halo, and trims to the artwork — unless `--no-trim`, which a
sheet needs: the room locates a frame by dividing the image by the column count and by eight, so
a crop that moves the edges throws every frame off-centre. Flooding rather than testing
every pixel is what keeps the white *inside* a sprite — metal highlights, cream ear linings —
from being punched out with the background.

**`quantise.py`** median-cuts to a palette (in four dimensions, so the antialiased rim survives)
and writes an indexed PNG with `tRNS`. One byte a pixel instead of four, and it flattens the
colour noise an upscale carries, which is a quality win as much as a size one.

Don't resize. The room scales these down at draw time; shrinking the file first throws away the
detail that survives that.

## What it's done so far

| Sprite | Before | After |
| --- | --- | --- |
| `decor/iron-throne.png` | 1.73 MB, 1408×768 screenshot with the checkerboard baked in | 121 KB, 458×623, 64 colours |
| `espurr-pickachu/*-Anim.png` | 1.87 MB, 624×1664 sheet, fully opaque | 112 KB, same size, 40 colours |
| `espurr-winged-gundam/*-Anim.png` | 1.42 MB, 656×1632 sheet, fully opaque | 217 KB, same size, 48 colours |
