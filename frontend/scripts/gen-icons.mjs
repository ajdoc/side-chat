/**
 * Regenerates every app icon — web, Electron, iOS, Android — from one square 1024×1024
 * artwork at `frontend/brand/icon-source.png`. Run it with `make icons`; see the icon
 * section of APPS.md for what each output is for.
 *
 * The artwork deliberately lives outside `public/`, which Nuxt copies wholesale into the
 * bundle: it is build-time input, and at ~1.3 MB it would otherwise be shipped to every web
 * visitor and packaged into both native shells for nothing.
 *
 * This runs inside the frontend container (sharp needs libvips, and is installed on the fly
 * into /tmp rather than added to package.json — nothing at runtime uses it). The container
 * only has `frontend/` mounted, so everything lands in a staging directory and the Makefile
 * copies it out to `desktop/` and `mobile/` afterwards.
 */
import { createRequire } from 'node:module'
import { mkdir, rm, writeFile } from 'node:fs/promises'

const sharp = createRequire(import.meta.url)('/tmp/imgtool/node_modules/sharp')

const SRC = new URL('../brand/icon-source.png', import.meta.url).pathname
const OUT = new URL('../.icons/', import.meta.url).pathname

const png = (size) =>
  sharp(SRC).resize(size, size, { fit: 'cover' }).png({ compressionLevel: 9 }).toBuffer()

async function write(name, buf) {
  await writeFile(OUT + name, buf)
  console.log(`  ${name} (${(buf.length / 1024).toFixed(0)} KB)`)
}

/**
 * A .ico is a 6-byte header, one 16-byte directory entry per image, then the images
 * themselves. Windows Vista and everything since accepts PNG-encoded entries verbatim, so
 * there is no BMP encoding to do here.
 */
function ico(images) {
  const header = Buffer.alloc(6)
  header.writeUInt16LE(1, 2) // type: icon
  header.writeUInt16LE(images.length, 4)

  let offset = 6 + images.length * 16
  const dir = images.map(({ size, data }) => {
    const e = Buffer.alloc(16)
    e.writeUInt8(size >= 256 ? 0 : size, 0) // 0 means 256
    e.writeUInt8(size >= 256 ? 0 : size, 1)
    e.writeUInt16LE(1, 4) // colour planes
    e.writeUInt16LE(32, 6) // bits per pixel
    e.writeUInt32LE(data.length, 8)
    e.writeUInt32LE(offset, 12)
    offset += data.length
    return e
  })

  return Buffer.concat([header, ...dir, ...images.map((i) => i.data)])
}

/**
 * Android's adaptive icon crops the outer ~22% on every side and masks what remains to
 * whatever shape the launcher likes, so the foreground layer is the artwork inset onto a
 * transparent canvas. What shows through at the edges is `values/ic_launcher_background.xml`,
 * which holds the cream the artwork fades out to.
 */
async function foreground(size) {
  const inner = Math.round(size * 0.82)
  const pad = Math.round((size - inner) / 2)
  return sharp({
    create: { width: size, height: size, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } },
  })
    .composite([{ input: await png(inner), top: pad, left: pad }])
    .png()
    .toBuffer()
}

async function round(size) {
  const circle = Buffer.from(
    `<svg width="${size}" height="${size}">`
    + `<circle cx="${size / 2}" cy="${size / 2}" r="${size / 2}" fill="#fff"/></svg>`,
  )
  return sharp(await png(size))
    .composite([{ input: circle, blend: 'dest-in' }])
    .png()
    .toBuffer()
}

const { width, height } = await sharp(SRC).metadata()
if (width !== height) throw new Error(`icon-source.png must be square, got ${width}×${height}`)
if (width < 1024) throw new Error(`icon-source.png must be at least 1024px, got ${width}px`)

await rm(OUT, { recursive: true, force: true })
await mkdir(OUT, { recursive: true })

console.log('web:')
await write('favicon.ico', ico(await Promise.all([16, 32, 48].map(async (size) => ({ size, data: await png(size) })))))
await write('apple-touch-icon.png', await png(180))
await write('icon-192.png', await png(192))
await write('icon-512.png', await png(512))
await write('logo.png', await png(256))

// Electron derives .ico and .icns from this; iOS wants exactly this one size and slices the
// rest itself. Both take the artwork uncropped.
console.log('electron + ios:')
await write('icon-1024.png', await png(1024))

console.log('android:')
for (const [density, size] of Object.entries({ mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 })) {
  await write(`android-${density}-ic_launcher.png`, await png(size))
  await write(`android-${density}-ic_launcher_round.png`, await round(size))
  // The adaptive foreground is authored on the 108dp canvas — 2.25× the legacy 48dp icon.
  await write(`android-${density}-ic_launcher_foreground.png`, await foreground(Math.round(size * 2.25)))
}
