import MarkdownIt from 'markdown-it'

/**
 * Chat-safe markdown: a small subset that can't hijack the message list.
 *
 * Built on the `zero` preset (everything off) and opted back in rule by rule, so
 * anything markdown-it adds in a future release stays off until we ask for it.
 * Notably absent: headings, images, tables and indented code blocks.
 *
 * `html: false` escapes raw HTML in the source, and markdown-it's own link
 * validator rejects `javascript:`/`data:` URLs — so the output of render() is
 * safe to hand to v-html without a separate sanitiser pass.
 */
const md = new MarkdownIt('zero', {
  html: false,
  linkify: true,
  breaks: true, // a single newline is a line break — people type chat messages, not documents
})

md.enable([
  // inline
  'emphasis', // **bold**, *italic*
  'strikethrough', // ~~struck~~
  'backticks', // `code`
  'link', // [text](url)
  'escape', // \*not italic\*
  'entity',
  'newline',
  // block
  'fence', // ```code```
  'blockquote',
  'list',
  // core
  'linkify', // bare urls
])

// Links always leave the app, and must not hand the opener over to the target page.
const defaultLink = md.renderer.rules.link_open
  ?? ((tokens, i, options, _env, self) => self.renderToken(tokens, i, options))

md.renderer.rules.link_open = (tokens, i, options, env, self) => {
  const token = tokens[i]!
  token.attrSet('target', '_blank')
  token.attrSet('rel', 'noopener noreferrer nofollow')
  return defaultLink(tokens, i, options, env, self)
}

/* --------------------------------------------------------------- mentions */

/**
 * `@all` and `@Display Name` become chips.
 *
 * Matched against the channel's actual roster (passed in as `env.mentionNames`) rather than
 * a blanket `@word`, so a name with a space survives whole and a stray `@` — in an email, in
 * prose — is left as plain text. `@all` is always a candidate, so it lights up even in the
 * one-line previews that render without a roster. This is the display half of the same
 * parse the server runs to decide whose sidebar to badge (see MentionParser.php).
 */
const isWord = (ch: string | undefined) => !!ch && /\w/.test(ch)

md.inline.ruler.before('emphasis', 'mention', (state, silent) => {
  const src = state.src
  const start = state.pos
  if (src[start] !== '@') return false

  // Not part of a word or an address: `foo@all` and `a@b` are not mentions.
  if (isWord(src[start - 1]) || src[start - 1] === '@') return false

  const names: string[] = state.env?.mentionNames ?? []
  // Longest first, so "@Ada Lovelace" wins over a member who is also just "@Ada".
  const candidates = ['all', ...names].sort((a, b) => b.length - a.length)

  const rest = src.slice(start + 1)
  const restLower = rest.toLowerCase()
  const match = candidates.find((name) => {
    if (!name || !restLower.startsWith(name.toLowerCase())) return false
    return !isWord(rest[name.length]) // a boundary must follow — no `@all` inside `@allan`
  })
  if (!match) return false

  if (!silent) {
    const token = state.push('mention', 'span', 0)
    token.content = rest.slice(0, match.length) // keep the user's own casing
    token.meta = { all: match.toLowerCase() === 'all' }
  }
  state.pos = start + 1 + match.length
  return true
})

md.renderer.rules.mention = (tokens, i) => {
  const token = tokens[i]!
  const cls = token.meta?.all ? 'md-mention md-mention-all' : 'md-mention'
  return `<span class="${cls}">@${md.utils.escapeHtml(token.content)}</span>`
}

/**
 * Flatten markdown to a single line of readable text — for the one-line previews
 * (reply references, thread parents) where rendering formatting would be noise
 * but showing raw `**asterisks**` looks broken.
 *
 * Deliberately leaves lone underscores alone: mangling snake_case identifiers
 * costs more than the occasional _italic_ marker it would strip.
 */
export function stripMarkdown(source: string): string {
  return source
    .replace(/```(?:\w+)?\n?([\s\S]*?)```/g, '$1') // fenced code → its contents
    .replace(/`([^`]+)`/g, '$1') // inline code
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1') // links → their label
    .replace(/\*\*|~~|__|\*/g, '') // emphasis markers
    .replace(/^\s*(?:[-*+]|\d+\.|>)\s+/gm, '') // list bullets and quote markers
    .replace(/\s+/g, ' ')
    .trim()
}

export function useMarkdown() {
  return {
    // `mentionNames` is the channel roster, so `@Name` chips resolve; `@all` needs no roster.
    render: (source: string, mentionNames: string[] = []) => md.render(source, { mentionNames }),
    renderInline: (source: string, mentionNames: string[] = []) => md.renderInline(source, { mentionNames }),
    stripMarkdown,
  }
}
