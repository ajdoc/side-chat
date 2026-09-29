import type { TrainingContent } from '~/types'

/**
 * Reading a training program out of a file someone picked.
 *
 * Two shapes: a plain `.json` program, or an HTML page that carries one in
 *
 *     <script type="application/json" id="training-program">{ … }</script>
 *
 * The page itself is never run — its markup and scripts are ignored, only that block is read.
 * That's what makes importing a page safe, and why a page without the block is refused rather
 * than guessed at. The server validates whatever comes out (TrainingContent.php); this only
 * finds it and gives a useful message when it can't.
 */

const BLOCK = /<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi

export class TrainingImportError extends Error {}

export function extractProgram(text: string, filename = ''): TrainingContent {
  const trimmed = text.trim()
  const looksJson = /\.json$/i.test(filename) || trimmed.startsWith('{')

  let json: string | null = null
  if (looksJson) {
    json = trimmed
  }
  else {
    for (const m of trimmed.matchAll(BLOCK)) {
      if (/\bid\s*=\s*["']?training-program["']?(?=[\s>]|$)/i.test(m[1] ?? '')) {
        json = m[2] ?? ''
        break
      }
    }
    if (json === null) {
      throw new TrainingImportError(
        'This page has no program data in it. Only pages with a <script type="application/json" id="training-program"> block can be imported. Ask whoever made the page (or Claude) to add one.',
      )
    }
  }

  let doc: unknown
  try {
    doc = JSON.parse(json)
  }
  catch {
    throw new TrainingImportError('The program data isn’t valid JSON.')
  }
  if (!doc || typeof doc !== 'object' || Array.isArray(doc)) {
    throw new TrainingImportError('The program data should be a single JSON object.')
  }
  return doc as TrainingContent
}

/**
 * The first few problems from a 422, as readable lines. Paths arrive as
 * `content.sessions.run.blocks.0.name`; blocks and items are shown 1-based.
 */
export function describeImportErrors(errors: Record<string, string[]> | undefined): string[] {
  if (!errors) return []
  return Object.entries(errors).slice(0, 5).map(([path, msgs]) => {
    const where = path
      .replace(/^content\.?/, '')
      .split('.')
      .map((part, i, all) => (/^\d+$/.test(part) && /^(blocks|items|days|phases|videos|overrides|reference)$/.test(all[i - 1] ?? '') ? `#${Number(part) + 1}` : part))
      .join(' › ')
    return where ? `${where}: ${msgs[0]}` : msgs[0] ?? ''
  })
}

/** A program as a downloadable `.json` file name. */
export function programFileName(title: string) {
  return `${title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'program'}.json`
}
