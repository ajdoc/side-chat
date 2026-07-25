// Move the generated Nuxt bundle into the place Capacitor packages from.
//
// Capacitor wants its `webDir` inside the native project, and Nuxt writes to
// `frontend/.output/public`. Rather than point one at the other across the repo (which
// `cap copy` handles poorly), we mirror the bundle into `mobile/www` on every sync.
import { cp, rm, stat } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const source = resolve(here, '../../frontend/.output/public')
const target = resolve(here, '../www')

try {
  await stat(source)
} catch {
  console.error(`No generated bundle at ${source} — run \`npm --prefix ../frontend run generate\` first.`)
  process.exit(1)
}

await rm(target, { recursive: true, force: true })
await cp(source, target, { recursive: true })
console.log(`Copied ${source} → ${target}`)
