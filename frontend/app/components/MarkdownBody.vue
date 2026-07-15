<script setup lang="ts">
const props = defineProps<{
  source: string
  edited?: boolean
}>()

const { render } = useMarkdown()

const html = computed(() => {
  const out = render(props.source)
  if (!props.edited) return out

  // Keep "(edited)" on the same line as the text it belongs to. If the message
  // ends in a paragraph we tuck the marker inside it; otherwise (code block,
  // list, quote) it goes underneath on its own.
  const marker = '<span class="md-edited">(edited)</span>'
  const trimmed = out.trimEnd()
  return trimmed.endsWith('</p>')
    ? `${trimmed.slice(0, -'</p>'.length)} ${marker}</p>`
    : `${trimmed}${marker}`
})
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -- output comes from useMarkdown, which escapes raw HTML -->
  <div class="md break-words text-sm" v-html="html" />
</template>

<style scoped>
/* v-html content is outside the scoped-style compiler's reach, hence :deep(). */
.md :deep(p) {
  margin: 0;
  white-space: pre-wrap; /* markdown-it keeps hard breaks, but preserve runs of spaces too */
}

.md :deep(p + p),
.md :deep(p + ul),
.md :deep(p + ol),
.md :deep(p + pre),
.md :deep(p + blockquote) {
  margin-top: 0.375rem;
}

.md :deep(strong) {
  font-weight: 600;
}

/* Links ride the user's accent — --primary already resolves per light/dark. */
.md :deep(a) {
  color: var(--primary);
  text-decoration: underline;
  text-underline-offset: 2px;
}

.md :deep(code) {
  border-radius: calc(var(--radius) - 4px);
  background-color: var(--muted);
  padding: 0.1rem 0.3rem;
  font-family: var(--font-mono, ui-monospace, monospace);
  font-size: 0.85em;
}

.md :deep(pre) {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) - 2px);
  background-color: color-mix(in oklab, var(--muted) 60%, transparent);
  padding: 0.5rem 0.625rem;
  margin: 0.375rem 0;
}

/* the <code> inside a fence is the block itself — drop the inline pill styling */
.md :deep(pre code) {
  background: none;
  padding: 0;
  font-size: 0.8125rem;
  line-height: 1.45;
  white-space: pre;
}

.md :deep(blockquote) {
  border-left: 3px solid var(--border);
  padding-left: 0.625rem;
  margin: 0.375rem 0;
  color: var(--muted-foreground);
}

.md :deep(ul),
.md :deep(ol) {
  margin: 0.375rem 0;
  padding-left: 1.25rem;
}

.md :deep(ul) {
  list-style: disc;
}

.md :deep(ol) {
  list-style: decimal;
}

.md :deep(li)::marker {
  color: var(--muted-foreground);
}

.md :deep(li > p) {
  display: inline; /* tight list items — no paragraph break after the bullet */
}

.md :deep(.md-edited) {
  margin-left: 0.25rem;
  font-size: 0.75rem;
  color: var(--muted-foreground);
  white-space: nowrap;
}
</style>
