<script setup lang="ts">
import { Plus, X } from 'lucide-vue-next'
import type { SignupGroup, SignupSheet } from '~/types'
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '~/components/ui/dialog'

/**
 * Copy one sheet — to one date or several, into any group, with or without its names.
 * The copies are one-off sheets, even when the original came from a schedule.
 */
const props = defineProps<{
  sheet: SignupSheet | null
  groups: SignupGroup[]
  copy: (id: number, input: { title: string | null, dates: string[] | null, group_id: number | null, with_names: boolean }) => Promise<{ created: number, data: SignupSheet }>
}>()

const emit = defineEmits<{ copied: [sheet: SignupSheet, created: number] }>()

const open = defineModel<boolean>('open', { required: true })

const title = ref('')
const dates = ref<string[]>([''])
const groupId = ref<number | null>(null)
const withNames = ref(false)
const busy = ref(false)
const error = ref('')

/** A week after the original — the usual "same again next week". */
function weekAfter(d: string | null) {
  if (!d) return ''
  const next = new Date(`${d}T00:00:00`)
  next.setDate(next.getDate() + 7)
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`
}

watch(open, (isOpen) => {
  if (!isOpen || !props.sheet) return
  title.value = props.sheet.title
  dates.value = [weekAfter(props.sheet.event_date)]
  groupId.value = props.sheet.group_id
  withNames.value = false
  error.value = ''
})

const filled = computed(() => dates.value.filter(Boolean))
const duplicateDates = computed(() => new Set(filled.value).size !== filled.value.length)

function addDate() {
  const last = filled.value[filled.value.length - 1] ?? props.sheet?.event_date ?? null
  dates.value.push(weekAfter(last))
}

async function submit() {
  if (!props.sheet || duplicateDates.value) return
  busy.value = true
  error.value = ''
  try {
    const res = await props.copy(props.sheet.id, {
      title: title.value.trim() || null,
      dates: filled.value.length ? [...filled.value] : null,
      group_id: groupId.value,
      with_names: withNames.value,
    })
    open.value = false
    emit('copied', res.data, res.created)
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not copy the sheet.'
  }
  finally {
    busy.value = false
  }
}

const count = computed(() => Math.max(1, filled.value.length))
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>Copy sheet</DialogTitle>
        <DialogDescription>
          Copies the header, columns and fees of “{{ sheet?.title }}”. Copies are one-off sheets, even if the original came from a schedule.
        </DialogDescription>
      </DialogHeader>

      <form id="signup-copy-form" class="space-y-4" @submit.prevent="submit">
        <label class="flex flex-col gap-1 text-xs text-muted-foreground">
          Title
          <input v-model="title" maxlength="120" class="h-9 rounded-md border bg-background px-2 text-sm text-foreground">
        </label>

        <div class="space-y-1.5">
          <p class="text-xs text-muted-foreground">Dates <span class="text-muted-foreground/70">(one copy per date)</span></p>
          <div v-for="(_, i) in dates" :key="i" class="flex items-center gap-2">
            <input v-model="dates[i]" type="date" class="h-9 flex-1 rounded-md border bg-background px-2 text-sm" :aria-label="`Date ${i + 1}`">
            <button
              v-if="dates.length > 1"
              type="button"
              class="grid h-9 w-9 place-items-center rounded border text-muted-foreground hover:bg-muted"
              title="Remove date"
              @click="dates.splice(i, 1)"
            >
              <X class="h-3.5 w-3.5" />
            </button>
          </div>
          <button v-if="dates.length < 31" type="button" class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="addDate">
            <Plus class="h-3.5 w-3.5" /> Add another date
          </button>
          <p v-if="duplicateDates" class="text-xs text-destructive">The same date is listed twice.</p>
        </div>

        <label class="flex flex-col gap-1 text-xs text-muted-foreground">
          Group
          <select v-model="groupId" class="h-9 rounded-md border bg-background px-2 text-sm text-foreground">
            <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
            <option :value="null">Ungrouped</option>
          </select>
        </label>

        <label class="flex cursor-pointer items-start gap-2 text-sm">
          <input v-model="withNames" type="checkbox" class="mt-1 h-3.5 w-3.5">
          <span>
            Include names
            <span class="block text-xs text-muted-foreground">Everyone attending is charged again on each copy. Payments aren't copied.</span>
          </span>
        </label>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </form>

      <DialogFooter>
        <button type="button" class="px-3 py-1.5 text-sm text-muted-foreground" @click="open = false">Cancel</button>
        <button
          type="submit"
          form="signup-copy-form"
          class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-50"
          :disabled="busy || duplicateDates || !sheet"
        >
          {{ busy ? 'Copying…' : count === 1 ? 'Copy sheet' : `Make ${count} copies` }}
        </button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
