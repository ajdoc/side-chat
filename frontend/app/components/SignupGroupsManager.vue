<script setup lang="ts">
import { ArrowDown, ArrowUp, Check, Trash2 } from 'lucide-vue-next'
import type { SignupGroup } from '~/types'
import { SIGNUP_COLORS, signupColor } from '~/lib/signups'

/**
 * A channel's groups: add, rename, recolour, reorder, delete. Deleting ungroups the sheets and
 * schedules in it; it never deletes them.
 */
const props = defineProps<{
  signups: ReturnType<typeof useSignups>
}>()

const { groups } = props.signups
const colorNames = Object.keys(SIGNUP_COLORS)
const error = ref('')

const newName = ref('')
const newColor = ref('blue')

function fail(e: any) {
  error.value = e?.data?.message ?? 'Could not save that.'
}

async function add() {
  if (!newName.value.trim()) return
  error.value = ''
  try {
    await props.signups.addGroup(newName.value.trim(), newColor.value)
    newName.value = ''
  }
  catch (e) {
    fail(e)
  }
}

/** Rename on blur/enter, only if it actually changed. */
async function rename(g: SignupGroup, name: string) {
  const next = name.trim()
  if (!next || next === g.name) return
  try {
    await props.signups.updateGroup(g.id, { name: next })
  }
  catch (e) {
    fail(e)
  }
}

async function recolor(g: SignupGroup, color: string) {
  try {
    await props.signups.updateGroup(g.id, { color })
  }
  catch (e) {
    fail(e)
  }
}

async function move(i: number, by: number) {
  const ids = groups.value.map(g => g.id)
  const j = i + by
  if (j < 0 || j >= ids.length) return
  ;[ids[i], ids[j]] = [ids[j]!, ids[i]!]
  try {
    await props.signups.reorderGroups(ids)
  }
  catch (e) {
    fail(e)
  }
}

const deleting = ref<SignupGroup | null>(null)
const busy = ref(false)

async function confirmDelete() {
  if (!deleting.value) return
  busy.value = true
  try {
    await props.signups.deleteGroup(deleting.value.id)
    deleting.value = null
  }
  catch (e) {
    fail(e)
  }
  finally {
    busy.value = false
  }
}

const iconBtn = 'grid h-8 w-8 shrink-0 place-items-center rounded border text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40'
</script>

<template>
  <ConfirmDialog
    :open="deleting !== null"
    :title="`Delete the ${deleting?.name ?? ''} group?`"
    description="Its sheets and schedules are kept and become ungrouped."
    confirm-label="Delete group"
    busy-label="Deleting…"
    :busy="busy"
    @update:open="(v: boolean) => { if (!v) deleting = null }"
    @confirm="confirmDelete"
  />

  <div class="min-h-0 flex-1 overflow-y-auto p-4">
    <div class="mx-auto max-w-xl space-y-4">
      <p class="text-sm text-muted-foreground">
        Groups sort your sheets, e.g. Weekday training, Bootcamp, Leagues. Rename a group by editing its name.
      </p>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

      <ul class="space-y-2">
        <li v-for="(g, i) in groups" :key="g.id" class="flex items-center gap-2">
          <span class="h-8 w-2 shrink-0 rounded" :class="signupColor(g.color).swatch" />
          <input
            :value="g.name"
            class="h-8 min-w-0 flex-1 rounded-md border bg-background px-2 text-sm"
            aria-label="Group name"
            maxlength="60"
            @keydown.enter="($event.target as HTMLInputElement).blur()"
            @blur="rename(g, ($event.target as HTMLInputElement).value)"
          >
          <select
            :value="g.color"
            class="h-8 rounded-md border bg-background px-2 text-sm"
            aria-label="Colour"
            @change="recolor(g, ($event.target as HTMLSelectElement).value)"
          >
            <option v-for="n in colorNames" :key="n" :value="n">{{ n }}</option>
          </select>
          <button type="button" :class="iconBtn" title="Move up" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="h-3.5 w-3.5" /></button>
          <button type="button" :class="iconBtn" title="Move down" :disabled="i === groups.length - 1" @click="move(i, 1)"><ArrowDown class="h-3.5 w-3.5" /></button>
          <button type="button" :class="[iconBtn, 'hover:text-destructive']" title="Delete group" @click="deleting = g"><Trash2 class="h-3.5 w-3.5" /></button>
        </li>
      </ul>

      <p v-if="!groups.length" class="text-sm text-muted-foreground">No groups yet.</p>

      <form class="flex items-center gap-2 border-t pt-4" @submit.prevent="add">
        <span class="h-8 w-2 shrink-0 rounded" :class="signupColor(newColor).swatch" />
        <input v-model="newName" placeholder="New group name" maxlength="60" class="h-8 min-w-0 flex-1 rounded-md border bg-background px-2 text-sm">
        <select v-model="newColor" class="h-8 rounded-md border bg-background px-2 text-sm" aria-label="Colour">
          <option v-for="n in colorNames" :key="n" :value="n">{{ n }}</option>
        </select>
        <button type="submit" class="flex h-8 items-center gap-1 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="!newName.trim()">
          <Check class="h-3.5 w-3.5" /> Add
        </button>
      </form>
    </div>
  </div>
</template>
