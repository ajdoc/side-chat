<script setup lang="ts">
import { FLOOR, VOID } from '~/lib/spaceMapEngine'

/**
 * A tiny picture of a room, drawn straight from its tile grid.
 *
 * Deliberately not an illustration: the preset picker shows the actual geometry the server
 * will seed, so choosing "Office" and getting an office is something you can see before you
 * commit rather than after. SVG rather than canvas because it's static, it's small, and it
 * wants to scale with its box without anybody measuring anything.
 *
 * One `<rect>` per *run* of identical tiles rather than per tile — a 30×20 grid is 600 tiles
 * and most rows are one long stretch of floor, so this is usually a couple of dozen rects.
 */
const props = defineProps<{ tiles: string[], width: number, height: number }>()

interface Run { x: number, y: number, w: number, floor: boolean }

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
          out.push({ x: start, y, w: x - start, floor: prev === FLOOR })
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
      :class="r.floor ? 'fill-background' : 'fill-muted-foreground/50'"
    />
  </svg>
</template>
