<script setup lang="ts">
import type { SignupPerson } from '~/types'
import { joinSlotText, splitSlotText } from '~/lib/signups'

/**
 * One cell on a sheet. Click to type; names already on the roster are suggested as you go.
 * Enter or leaving the cell saves; Escape abandons; clearing the text frees the slot.
 */
const props = defineProps<{
  name: string | null
  note: string | null
  roster: SignupPerson[]
  /** Names already on this sheet — ranked below the rest so the list offers who's missing. */
  taken: Set<string>
  editable: boolean
  cellClass: string
  /** Awaited, so the cell can show the new text until the sheet comes back — or roll back. */
  save: (name: string, note: string | null) => Promise<void>
}>()

const editing = ref(false)
const text = ref('')
const highlighted = ref(0)
const input = ref<HTMLInputElement | null>(null)
/** Arrowed into the list? Only then does Enter take a suggestion over what was typed. */
const navigated = ref(false)
/** The text just saved, shown until the save settles. */
const pending = ref<string | null>(null)

const saved = computed(() => props.name ? joinSlotText(props.name, props.note) : '')
const shown = computed(() => pending.value ?? saved.value)

async function start() {
  if (!props.editable || pending.value !== null) return
  text.value = saved.value
  highlighted.value = 0
  navigated.value = false
  editing.value = true
  await nextTick()
  input.value?.select()
}

const suggestions = computed(() => {
  const q = splitSlotText(text.value).name.toLowerCase()
  if (!q || text.value.includes(' - ')) return []
  return props.roster
    .filter(p => p.name.toLowerCase().includes(q) && p.name.toLowerCase() !== q)
    .sort((a, b) => Number(props.taken.has(a.name.toLowerCase())) - Number(props.taken.has(b.name.toLowerCase()))
      || Number(!a.name.toLowerCase().startsWith(q)) - Number(!b.name.toLowerCase().startsWith(q))
      || b.signups - a.signups)
    .slice(0, 6)
})

async function commit() {
  if (!editing.value) return
  editing.value = false
  const { name, note } = splitSlotText(text.value)
  const next = name ? joinSlotText(name, note) : ''
  if (next === saved.value) return
  pending.value = next
  try {
    await props.save(name, note)
  }
  finally {
    pending.value = null
  }
}

function pick(p: SignupPerson) {
  const { note } = splitSlotText(text.value)
  text.value = joinSlotText(p.name, note)
  void commit()
}

function onKey(e: KeyboardEvent) {
  const list = suggestions.value
  if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && list.length) {
    e.preventDefault()
    const by = e.key === 'ArrowDown' ? 1 : -1
    highlighted.value = navigated.value ? (highlighted.value + by + list.length) % list.length : 0
    navigated.value = true
  }
  else if (e.key === 'Enter') {
    e.preventDefault()
    const hit = navigated.value ? list[highlighted.value] : undefined
    if (hit) pick(hit)
    else void commit()
  }
  else if (e.key === 'Tab' && list.length) {
    e.preventDefault()
    pick(list[highlighted.value]!)
  }
  else if (e.key === 'Escape') {
    editing.value = false
  }
}
</script>

<template>
  <td class="relative h-8 border border-border/60 p-0 text-center text-sm" :class="cellClass">
    <template v-if="editing">
      <input
        ref="input"
        v-model="text"
        class="h-8 w-full bg-background px-2 text-center outline-none ring-2 ring-primary"
        aria-label="Name"
        @keydown="onKey"
        @input="highlighted = 0; navigated = false"
        @blur="commit"
      >
      <ul
        v-if="suggestions.length"
        class="absolute left-0 right-0 top-full z-30 mt-0.5 overflow-hidden rounded-md border bg-popover text-left text-sm shadow-lg"
      >
        <li v-for="(p, i) in suggestions" :key="p.id">
          <!-- mousedown, not click: click lands after the input's blur has already committed. -->
          <button
            type="button"
            class="flex w-full items-center justify-between gap-2 px-2 py-1.5"
            :class="i === highlighted && navigated ? 'bg-muted' : ''"
            @mousedown.prevent="pick(p)"
          >
            <span class="truncate">{{ p.name }}</span>
            <span v-if="taken.has(p.name.toLowerCase())" class="shrink-0 text-[10px] text-muted-foreground">on sheet</span>
          </button>
        </li>
      </ul>
    </template>
    <button
      v-else
      type="button"
      class="h-8 w-full truncate px-2"
      :class="[editable ? 'cursor-text hover:bg-background/60' : 'cursor-default', pending !== null ? 'opacity-60' : '']"
      :disabled="!editable"
      :title="shown || undefined"
      @click="start"
    >
      {{ shown }}
    </button>
  </td>
</template>
