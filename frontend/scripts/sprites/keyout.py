"""
Key the checkerboard out of a sprite, trim it, and write it back as a compact RGBA PNG.

Pure zlib/struct, because this box has no image libraries at all. Only what the job needs:
8-bit, non-interlaced, colour type 2 or 6 in; colour type 6 out.

The rule for "that's the background" is the one in public/sprites/README.md — flood-fill inward
from the borders over near-neutral bright pixels, rather than testing every pixel in the image,
so the white *inside* the sprite (metal highlights, the bright edges of the blades) is kept.
"""
import struct
import sys
import zlib
from collections import deque

SRC, DST = sys.argv[1], sys.argv[2]


def read_png(path):
    data = open(path, 'rb').read()
    assert data[:8] == b'\x89PNG\r\n\x1a\n', 'not a PNG'

    idat = bytearray()
    i, hdr = 8, None

    while i < len(data):
        (ln,) = struct.unpack('>I', data[i:i + 4])
        typ = data[i + 4:i + 8]
        body = data[i + 8:i + 8 + ln]

        if typ == b'IHDR':
            hdr = struct.unpack('>IIBBBBB', body)
        elif typ == b'IDAT':
            idat += body
        elif typ == b'IEND':
            break

        i += 12 + ln

    w, h, depth, colour, _, _, interlace = hdr
    assert depth == 8 and interlace == 0 and colour in (2, 6), f'unsupported: {depth}/{colour}'

    channels = 4 if colour == 6 else 3
    raw = zlib.decompress(bytes(idat))
    stride = w * channels

    # Undo the per-scanline filters. Straight from the spec; `prior` is the reconstructed row
    # above, which is what filters 2-4 are defined against.
    out = bytearray(h * stride)
    prior = bytearray(stride)
    pos = 0

    for y in range(h):
        ft = raw[pos]
        pos += 1
        line = bytearray(raw[pos:pos + stride])
        pos += stride

        if ft == 1:
            for x in range(channels, stride):
                line[x] = (line[x] + line[x - channels]) & 0xFF
        elif ft == 2:
            for x in range(stride):
                line[x] = (line[x] + prior[x]) & 0xFF
        elif ft == 3:
            for x in range(stride):
                left = line[x - channels] if x >= channels else 0
                line[x] = (line[x] + ((left + prior[x]) >> 1)) & 0xFF
        elif ft == 4:
            for x in range(stride):
                a = line[x - channels] if x >= channels else 0
                b = prior[x]
                c = prior[x - channels] if x >= channels else 0
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                pr = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
                line[x] = (line[x] + pr) & 0xFF
        elif ft != 0:
            raise AssertionError(f'bad filter {ft}')

        out[y * stride:(y + 1) * stride] = line
        prior = line

    # Normalise to RGBA so everything below has one shape to think about.
    if channels == 3:
        rgba = bytearray(w * h * 4)
        for p in range(w * h):
            rgba[p * 4:p * 4 + 3] = out[p * 3:p * 3 + 3]
            rgba[p * 4 + 3] = 255
        out = rgba

    return w, h, out


def write_png(path, w, h, px):
    stride = w * 4
    raw = bytearray()

    # Filter 1 (Sub) on every line. Cheap, and a big win on flat artwork: long runs of one
    # colour become long runs of zero, which is exactly what deflate is good at.
    for y in range(h):
        line = px[y * stride:(y + 1) * stride]
        raw.append(1)
        raw += bytes([(line[x] - (line[x - 4] if x >= 4 else 0)) & 0xFF for x in range(stride)])

    def chunk(typ, body):
        return struct.pack('>I', len(body)) + typ + body + struct.pack('>I', zlib.crc32(typ + body))

    open(path, 'wb').write(
        b'\x89PNG\r\n\x1a\n'
        + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0))
        + chunk(b'IDAT', zlib.compress(bytes(raw), 9))
        + chunk(b'IEND', b'')
    )


w, h, px = read_png(SRC)
print(f'in  {w}x{h}')


def backgroundish(p, bright=170, neutral=26):
    """Light and colourless — the two things a checkerboard square is and a sword isn't."""
    r, g, b = px[p * 4], px[p * 4 + 1], px[p * 4 + 2]

    return min(r, g, b) >= bright and (max(r, g, b) - min(r, g, b)) <= neutral


# --- flood fill inward from every border pixel ---
seen = bytearray(w * h)
queue = deque()

for x in range(w):
    for p in (x, (h - 1) * w + x):
        if not seen[p] and backgroundish(p):
            seen[p] = 1
            queue.append(p)

for y in range(h):
    for p in (y * w, y * w + w - 1):
        if not seen[p] and backgroundish(p):
            seen[p] = 1
            queue.append(p)

while queue:
    p = queue.popleft()
    x, y = p % w, p // w

    for q in ((p - 1) if x else -1, (p + 1) if x < w - 1 else -1,
              (p - w) if y else -1, (p + w) if y < h - 1 else -1):
        if q >= 0 and not seen[q] and backgroundish(q):
            seen[q] = 1
            queue.append(q)

# --- two passes of the blended edge ---
# The screenshot's antialiasing leaves a rim of pixels that are part checkerboard and part
# sprite. Left alone they read as a pale halo round every blade. Each pass takes the rim
# pixels that are still mostly background, with the bar dropped a little each time.
for bright in (200, 215):
    edge = [p for p in range(w * h) if not seen[p] and backgroundish(p, bright=bright, neutral=18)
            and any(seen[q] for q in (p - 1, p + 1, p - w, p + w) if 0 <= q < w * h)]

    for p in edge:
        seen[p] = 1

for p in range(w * h):
    if seen[p]:
        px[p * 4:p * 4 + 4] = b'\x00\x00\x00\x00'

# --- trim to what's left ---
x0, y0, x1, y1 = w, h, -1, -1

for y in range(h):
    for x in range(w):
        if px[(y * w + x) * 4 + 3]:
            x0, y0 = min(x0, x), min(y0, y)
            x1, y1 = max(x1, x), max(y1, y)

nw, nh = x1 - x0 + 1, y1 - y0 + 1
crop = bytearray(nw * nh * 4)

for y in range(nh):
    src = ((y + y0) * w + x0) * 4
    crop[y * nw * 4:(y + 1) * nw * 4] = px[src:src + nw * 4]

write_png(DST, nw, nh, crop)
print(f'out {nw}x{nh}  (cropped from {x0},{y0})')
