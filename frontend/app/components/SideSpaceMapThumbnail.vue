<script setup lang="ts">
import { TILE_BRUSHES, VOID } from '~/lib/spaceMapEngine'

/**
 * A tiny picture of a room, drawn straight from its tile grid.
 *
 * Deliberately not an illustration: the preset picker shows the actual geometry the server
 * will seed, so choosing "Park" and getting a park is something you can see before you commit
 * rather than after. SVG rather than canvas because it's static, it's small, and it wants to
 * scale with its box without anybody measuring anything.
 *
 * One `<rect>` per *run* of identical tiles rather than per tile — a 30×20 grid is 600 tiles and
 * most rows are one long stretch of the same thing, so this is usually a couple of dozen rects.
 *
 * Colours come from the same swatches the editor's brushes use, which is what makes the
 * thumbnail recognisably the room rather than a floor plan of it: the pond is blue, the path is
 * sand-coloured, the trees are green. It doesn't attempt the tile *art* — at four pixels a tile
 * there is nothing to draw.
 */
const props = defineProps<{ tiles: string[], width: number, height: number }>()

interface Run { x: number, y: number, w: number, fill: string }

/** Tile character → the flat colour that stands for it. */
const SWATCH: Record<string, string> = Object.fromEntries(
  TILE_BRUSHES.map(b => [b.tile, b.swatch]),
)

const runs = computed<Run[]>(() => {
  const out: Run[] = []

  props.tiles.forEach((row, y) => {
    let start = 0

    for (let x = 0; x <= props.width; x++) {
      const here = row[x]
      const prev = row[start]

      // Close the run at a change of tile, at the end of the row, or on void (which draws as
      // nothing at all — it's what's *outside* the room).
      if (x === props.width || here !== prev) {
        if (prev !== undefined && prev !== VOID) {
          out.push({ x: start, y, w: x - start, fill: SWATCH[prev] ?? '#9ca3af' })
        }
        start = x
      }
    }
  })

  return out
})
</script>

<template>
  <svg
    :viewBox="`0 0 ${width} ${height}`"
    class="block h-16 w-full rounded bg-muted/40"
    preserveAspectRatio="xMidYMid meet"
    aria-hidden="true"
  >
    <rect
      v-for="(r, i) in runs"
      :key="i"
      :x="r.x"
      :y="r.y"
      :width="r.w"
      height="1"
      :fill="r.fill"
    />
  </svg>
</template>
