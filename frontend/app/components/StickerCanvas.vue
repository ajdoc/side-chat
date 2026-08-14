<script setup lang="ts">
import type { StickerContent } from '~/lib/stickers'
import { pathData, shapePath, stickerLayers } from '~/lib/stickers'

/**
 * A sticker, drawn.
 *
 * One renderer for every place a sticker appears — the wall, the editor's live preview, the
 * strip of your own stickers. Everything is in a 0–100 viewBox, so the same content renders at
 * 40px on the wall and at 400px in the editor with no separate code path and no rasterising.
 */
const props = defineProps<{ content: StickerContent }>()

/**
 * Every visible path, flattened in layer order.
 *
 * Flattened rather than drawn as nested groups: SVG paints in document order anyway, so a group
 * per layer would be markup with no effect — and the wall draws hundreds of these.
 */
const paths = computed(() =>
  stickerLayers(props.content).filter(l => l.visible).flatMap(l => l.paths))
</script>

<template>
  <svg viewBox="0 0 100 100" class="h-full w-full" preserveAspectRatio="none">
    <!-- `shape: 'none'` gives a transparent sticker: a drawing with no card behind it, which
         is how you get a cut-out on the wall rather than a tile. -->
    <path
      v-if="content.shape !== 'none'"
      :d="shapePath(content.shape)"
      :fill="content.fill"
      :fill-opacity="content.fillOpacity"
      :stroke="content.stroke"
      stroke-width="1.5"
    />
    <path
      v-for="(p, i) in paths"
      :key="i"
      :d="pathData(p)"
      fill="none"
      :stroke="p.color"
      :stroke-width="p.width"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
    <text
      v-if="content.text"
      x="50"
      y="54"
      text-anchor="middle"
      dominant-baseline="middle"
      font-size="14"
      font-weight="700"
      :fill="content.textColor ?? '#000000'"
    >{{ content.text }}</text>
  </svg>
</template>
