"""
Median-cut an RGBA PNG down to a small palette and write it indexed (colour type 3 + tRNS).

Two wins at once, and the second is the bigger one. Structurally, indexed is one byte a pixel
where RGBA is four. And the source is an upscale, so it carries tens of thousands of distinct
colours for artwork that is really about three dozen — that noise is what deflate can't
compress, and flattening it reads as pixel art where the noise reads as a JPEG of pixel art.

Cut in *four* dimensions rather than three, so alpha is quantised alongside colour: the
antialiased rim round each blade is a real part of this sprite and squashing it to on/off would
put the jaggies back that keying the background out just took away.
"""
import struct
import sys
import zlib

SRC, DST = sys.argv[1], sys.argv[2]
COLOURS = int(sys.argv[3]) if len(sys.argv) > 3 else 64

sys.path.insert(0, __file__.rsplit('/', 1)[0])
from keyout import read_png  # noqa: E402  (shares the decoder; see the note there)

w, h, px = read_png(SRC)

# Distinct colours and how often each occurs. The counts are what make the cut follow the
# picture: a box is split at the *median by population*, so a colour covering half the sprite
# gets more of the palette than one covering nine pixels.
hist = {}
for p in range(w * h):
    key = bytes(px[p * 4:p * 4 + 4])
    hist[key] = hist.get(key, 0) + 1

print(f'{w}x{h}, {len(hist)} distinct colours')

boxes = [list(hist.items())]

while len(boxes) < COLOURS:
    # Split whichever box is widest along any one axis — that's the one whose colours are
    # furthest from being one colour, and therefore the one costing the most fidelity.
    best, axis, spread = None, 0, -1

    for box in boxes:
        if len(box) < 2:
            continue

        for a in range(4):
            lo = min(c[a] for c, _ in box)
            hi = max(c[a] for c, _ in box)

            if (hi - lo) > spread:
                best, axis, spread = box, a, hi - lo

    if best is None:
        break

    best.sort(key=lambda item: item[0][axis])
    total = sum(n for _, n in best)
    half, cut = 0, 0

    for i, (_, n) in enumerate(best):
        half += n
        if half >= total / 2:
            cut = max(1, min(i, len(best) - 1))
            break

    boxes.remove(best)
    boxes += [best[:cut], best[cut:]]

# Each box becomes one palette entry: the population-weighted average of what fell in it.
palette, lookup = [], {}

for box in boxes:
    total = sum(n for _, n in box)
    entry = tuple(round(sum(c[a] * n for c, n in box) / total) for a in range(4))

    # A box that holds any fully-transparent pixel must *stay* fully transparent, or the
    # background comes back as a faint grey wash. Averaging can't be trusted with alpha 0.
    if any(c[3] == 0 for c, _ in box):
        entry = (0, 0, 0, 0)

    for c, _ in box:
        lookup[c] = len(palette)

    palette.append(entry)

# Opaque entries last, so tRNS (which is a prefix of the palette) stays short.
order = sorted(range(len(palette)), key=lambda i: palette[i][3])
remap = {old: new for new, old in enumerate(order)}
palette = [palette[i] for i in order]

indexed = bytes(remap[lookup[bytes(px[p * 4:p * 4 + 4])]] for p in range(w * h))

raw = bytearray()
for y in range(h):
    raw.append(1)  # Sub: flat runs become runs of zero.
    line = indexed[y * w:(y + 1) * w]
    raw += bytes([(line[x] - (line[x - 1] if x else 0)) & 0xFF for x in range(w)])

alphas = [e[3] for e in palette]
while alphas and alphas[-1] == 255:
    alphas.pop()


def chunk(typ, body):
    return struct.pack('>I', len(body)) + typ + body + struct.pack('>I', zlib.crc32(typ + body))


out = (
    b'\x89PNG\r\n\x1a\n'
    + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 3, 0, 0, 0))
    + chunk(b'PLTE', bytes(b for e in palette for b in e[:3]))
    + (chunk(b'tRNS', bytes(alphas)) if alphas else b'')
    + chunk(b'IDAT', zlib.compress(bytes(raw), 9))
    + chunk(b'IEND', b'')
)

open(DST, 'wb').write(out)
print(f'{len(palette)} colours, {len(out)} bytes')
