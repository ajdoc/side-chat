<script setup lang="ts">
import type { User } from '~/types'

/**
 * A person, as a small round avatar — the assignee chip on a task row, the author beside a
 * comment, the actor on a history line.
 *
 * Its own component because the Tracker draws one of these in six places at three sizes, and
 * the fallback matters: plenty of accounts have no picture, and an empty circle in a task row
 * reads as unassigned rather than as "assigned to somebody without an avatar". Initials are the
 * difference between those two.
 *
 * `null` is a real state, not an absence — it draws the dashed "unassigned" circle rather than
 * nothing, so the slot is still a target you can click.
 */
const props = withDefaults(defineProps<{
  user?: User | null
  size?: 'xs' | 'sm' | 'md'
}>(), { user: null, size: 'sm' })

const sizes = {
  xs: 'h-4 w-4 text-[8px]',
  sm: 'h-5 w-5 text-[9px]',
  md: 'h-7 w-7 text-[11px]',
} as const

const box = computed(() => sizes[props.size])
</script>

<template>
  <span
    v-if="user"
    class="grid shrink-0 place-items-center overflow-hidden rounded-full bg-primary/15 font-medium text-primary"
    :class="box"
    :title="user.name"
  >
    <img v-if="user.avatar" :src="user.avatar" :alt="user.name" class="h-full w-full object-cover">
    <span v-else>{{ initialsOf(user.name) }}</span>
  </span>
  <span
    v-else
    class="grid shrink-0 place-items-center rounded-full border border-dashed border-muted-foreground/40 text-muted-foreground"
    :class="box"
    title="Unassigned"
  >
    <span class="text-[9px]">?</span>
  </span>
</template>
