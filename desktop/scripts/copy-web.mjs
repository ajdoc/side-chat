// Mirror the generated Nuxt bundle into `desktop/web`, which main.js serves over `app://`
// and electron-builder packages. Same job as mobile/scripts/copy-web.mjs.
import { cp, rm, stat } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const source = resolve(here, '../../frontend/.output/public')
const target = resolve(here, '../web')

try {
  await stat(source)
} catch {
  console.error(`No generated bundle at ${source} — run \`npm --prefix ../frontend run generate\` first.`)
  process.exit(1)
}

await rm(target, { recursive: true, force: true })
await cp(source, target, { recursive: true })
console.log(`Copied ${source} → ${target}`)
