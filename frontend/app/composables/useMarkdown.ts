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
    render: (source: string) => md.render(source),
    renderInline: (source: string) => md.renderInline(source),
    stripMarkdown,
  }
}
