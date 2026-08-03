<script setup lang="ts">
import { X } from 'lucide-vue-next'
import type { Channel } from '~/types'

/**
 * "Post this in these channels."
 *
 * A primary channel plus extras, rather than one flat list, because that's the shape the
 * data has: the first channel is a real foreign key that cascades when the channel is
 * deleted, and the rest ride along in a JSON column. Hiding that distinction behind one
 * control would mean the first pick silently behaving differently from the others — for a
 * giveaway it's also where the winners get announced.
 *
 * So the primary is a dropdown and the extras are chips, and the labels say which is which.
 */
const primary = defineModel<number | null>('primary', { default: null })
const extras = defineModel<number[]>('extras', { default: () => [] })

const props = defineProps<{
  channels: Channel[]
  /** Shown as the blank option. Omit to make a primary channel required. */
  emptyLabel?: string
}>()

/** Everything not already chosen — a channel can't be picked twice. */
const available = computed(() =>
  props.channels.filter(c => c.id !== primary.value && !extras.value.includes(c.id)))

const nameOf = (id: number) => props.channels.find(c => c.id === id)?.name ?? 'deleted channel'

function add(event: Event) {
  const select = event.target as HTMLSelectElement
  const id = Number(select.value)
  if (id) extras.value = [...extras.value, id]
  // Back to the placeholder, so the control reads as "add another" rather than as a
  // dropdown that now claims to hold one value.
  select.value = ''
}
</script>

<template>
  <div class="space-y-1.5">
    <select v-model.number="primary" class="w-full rounded border bg-background px-2 py-1 text-xs">
      <option v-if="emptyLabel" :value="null">{{ emptyLabel }}</option>
      <option v-for="channel in channels" :key="channel.id" :value="channel.id"># {{ channel.name }}</option>
    </select>

    <div v-if="extras.length" class="flex flex-wrap gap-1">
      <span
        v-for="id in extras"
        :key="id"
        class="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[11px]"
      >
        # {{ nameOf(id) }}
        <button
          class="text-muted-foreground hover:text-destructive"
          :aria-label="`Remove ${nameOf(id)}`"
          @click="extras = extras.filter(x => x !== id)"
        >
          <X class="h-3 w-3" />
        </button>
      </span>
    </div>

    <!-- Offered even when the primary is the fallback ("the reminders channel"): extras
         are additive, and the backend applies the fallback only when nothing is named at
         all — so "reminders, plus #general" is a real and reasonable choice. -->
    <select
      v-if="available.length"
      class="w-full rounded border border-dashed bg-background px-2 py-1 text-[11px] text-muted-foreground"
      value=""
      @change="add"
    >
      <option value="">+ also post in…</option>
      <option v-for="channel in available" :key="channel.id" :value="channel.id"># {{ channel.name }}</option>
    </select>
  </div>
</template>
