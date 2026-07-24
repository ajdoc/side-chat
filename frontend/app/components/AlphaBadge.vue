<script setup lang="ts">
/**
 * A small "alpha" pill, for a feature that works but isn't finished.
 *
 * Worth having as a component rather than a span repeated in four places, because the point of
 * the label is that it says the *same* thing everywhere it appears — a feature marked alpha on
 * the creation screen and unmarked once you're inside it is worse than no label at all.
 *
 * The tooltip carries the actual promise being made (and not made). "Alpha" on its own is
 * jargon that means whatever the reader last saw it mean; `title` is where it gets pinned down.
 */
withDefaults(defineProps<{
  /** What might still change. Shown on hover, after the "in development" preamble. */
  hint?: string
}>(), {
  hint: 'It works, but expect rough edges — and expect it to change.',
})
</script>

<template>
  <span
    class="shrink-0 rounded-full border border-amber-500/40 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase leading-none tracking-wide text-amber-600 dark:text-amber-400"
    :title="`This feature is still in development. ${hint}`"
  >Alpha</span>
</template>
