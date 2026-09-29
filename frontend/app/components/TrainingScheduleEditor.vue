<script setup lang="ts">
import { Plus, Scissors, Trash2 } from 'lucide-vue-next'
import { Input } from '~/components/ui/input'
import type { TrainingContent } from '~/types'
import { weekRanges } from '~/lib/training'
import {
  blankSession, planColumns, removePhase, setPhaseEnd, setSessionForPhase, slotsUsing, splitPhase, unusedSessions,
} from '~/lib/trainingEdit'

/**
 * The whole-program view of the schedule, edited in place on the draft: the phases, which
 * session each day gets in each phase, one-off swaps, and the library of sessions.
 *
 * The week-by-week view (TrainingProgramView in edit mode) covers the common edits; this is
 * for seeing and changing the pattern all at once.
 */
const props = defineProps<{ draft: TrainingContent }>()

const field = 'w-full rounded-md border bg-background px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-ring'

const sessionIds = computed(() => Object.keys(props.draft.sessions))
const unused = computed(() => unusedSessions(props.draft))

function title(id: string | null) {
  return id ? props.draft.sessions[id]?.title ?? id : 'Rest'
}

// Library: pick any session to edit, scheduled or not.
const libraryId = ref(sessionIds.value[0] ?? '')
const library = computed(() => props.draft.sessions[libraryId.value] ?? null)

function usedOn(id: string) {
  const slots = slotsUsing(props.draft, id)
  const byDay = new Map<string, number[]>()
  for (const s of slots) byDay.set(s.day, [...(byDay.get(s.day) ?? []), s.week])
  return [...byDay.entries()].map(([day, weeks]) => `${props.draft.days.find(d => d.key === day)?.label ?? day} wk ${weekRanges(weeks)}`).join(' · ')
}

function addLibrarySession() {
  let n = sessionIds.value.length + 1
  while (props.draft.sessions[`session-${n}`]) n++
  props.draft.sessions[`session-${n}`] = blankSession()
  libraryId.value = `session-${n}`
}

function deleteUnused(id: string) {
  delete props.draft.sessions[id]
  if (libraryId.value === id) libraryId.value = sessionIds.value[0] ?? ''
}

function addSwap() {
  props.draft.overrides.push({ weeks: [1], day: props.draft.days[0]?.key ?? '', session: null })
}

function setWeeks(o: { weeks: number[] }, text: string) {
  o.weeks = [...new Set(text.split(/[\s,]+/).flatMap((part) => {
    const [a, b] = part.split(/[-–]/).map(Number)
    if (!a) return []
    return b ? Array.from({ length: Math.max(0, b - a + 1) }, (_, i) => a + i) : [a]
  }).filter(n => Number.isInteger(n) && n >= 1 && n <= props.draft.weeks))].sort((x, y) => x - y)
}
</script>

<template>
  <div class="space-y-8">
    <!-- Phases -->
    <section>
      <h3 class="font-semibold">Phases</h3>
      <p class="text-sm text-muted-foreground">Blocks of weeks that share a set of sessions, like Foundation, Build and Peak.</p>
      <div v-for="(p, i) in draft.phases" :key="i" class="mt-3 rounded-lg border bg-background p-3">
        <div class="flex flex-wrap items-center gap-2">
          <Input v-model="p.name" class="h-8 w-40 font-medium" maxlength="40" />
          <span class="text-sm text-muted-foreground">weeks {{ p.from }} to</span>
          <Input
            v-if="i < draft.phases.length - 1"
            type="number"
            :model-value="p.to"
            :min="p.from"
            :max="draft.phases[i + 1]!.to - 1"
            class="h-8 w-16 text-sm"
            @change="setPhaseEnd(draft, i, Number(($event.target as HTMLInputElement).value))"
          />
          <span v-else class="text-sm text-muted-foreground">{{ p.to }}</span>
          <span class="ml-auto flex gap-1">
            <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="p.from === p.to" title="Split into two phases" @click="splitPhase(draft, i)"><Scissors class="h-4 w-4" /></button>
            <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive disabled:opacity-30" :disabled="draft.phases.length === 1" title="Merge into the neighbouring phase" @click="removePhase(draft, i)"><Trash2 class="h-4 w-4" /></button>
          </span>
        </div>
        <textarea v-model="p.line" rows="1" placeholder="One line on what this phase is for" :class="field" class="mt-2" maxlength="500" />
      </div>
    </section>

    <!-- Day × phase -->
    <section>
      <h3 class="font-semibold">Schedule</h3>
      <p class="text-sm text-muted-foreground">Which session each day gets in each phase. Setting a phase here clears that phase's one-off swaps for the day.</p>
      <div class="mt-3 overflow-x-auto rounded-lg border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b bg-muted/40 text-xs text-muted-foreground">
              <th class="px-3 py-2 text-left font-medium">Day</th>
              <th v-for="p in draft.phases" :key="p.name + p.from" class="px-2 py-2 text-left font-medium">
                {{ p.name }} <span class="font-normal">(wk {{ p.from }}–{{ p.to }})</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in draft.days" :key="d.key" class="border-b last:border-b-0">
              <td class="px-3 py-2 font-medium">{{ d.label }}</td>
              <td v-for="(p, i) in draft.phases" :key="p.name + p.from" class="px-2 py-2">
                <select
                  :value="planColumns(draft, d.key)[i] ?? ''"
                  :class="field"
                  class="min-w-[9rem]"
                  @change="setSessionForPhase(draft, i, d.key, ($event.target as HTMLSelectElement).value || null)"
                >
                  <option value="">Rest</option>
                  <option v-for="id in sessionIds" :key="id" :value="id">{{ title(id) }}</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Swaps -->
    <section>
      <h3 class="font-semibold">One-off swaps</h3>
      <p class="text-sm text-muted-foreground">A different session (or rest) on a day in specific weeks, like test days. Changing a single week from the week view adds these for you.</p>
      <div v-for="(o, i) in draft.overrides" :key="i" class="mt-2 flex flex-wrap items-center gap-2">
        <span class="text-sm">Weeks</span>
        <Input :model-value="weekRanges(o.weeks)" class="h-8 w-28 text-sm" placeholder="1, 13" @change="setWeeks(o, ($event.target as HTMLInputElement).value)" />
        <select v-model="o.day" :class="field" class="w-auto">
          <option v-for="d in draft.days" :key="d.key" :value="d.key">{{ d.label }}</option>
        </select>
        <span class="text-sm">becomes</span>
        <select v-model="o.session" :class="field" class="w-auto min-w-0 flex-1">
          <option :value="null">Rest</option>
          <option v-for="id in sessionIds" :key="id" :value="id">{{ title(id) }}</option>
        </select>
        <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive" title="Remove swap" @click="draft.overrides.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
      </div>
      <button type="button" class="mt-3 flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-sm hover:bg-muted" @click="addSwap">
        <Plus class="h-4 w-4" /> Swap
      </button>
    </section>

    <!-- Library -->
    <section>
      <h3 class="font-semibold">All sessions</h3>
      <p class="text-sm text-muted-foreground">Every session in the program, including ones not on the schedule yet.</p>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <select v-model="libraryId" :class="field" class="min-w-0 flex-1" aria-label="Session to edit">
          <option v-for="id in sessionIds" :key="id" :value="id">{{ title(id) }}{{ unused.includes(id) ? ' (not scheduled)' : '' }}</option>
        </select>
        <button type="button" class="flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-sm hover:bg-muted" @click="addLibrarySession">
          <Plus class="h-4 w-4" /> Session
        </button>
        <button
          v-if="library && unused.includes(libraryId)"
          type="button"
          class="rounded-md border p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
          title="Delete this session"
          @click="deleteUnused(libraryId)"
        >
          <Trash2 class="h-4 w-4" />
        </button>
      </div>
      <p v-if="library" class="mt-2 text-xs text-muted-foreground">
        {{ unused.includes(libraryId) ? 'Not on the schedule. Pick it for a day above, or delete it.' : `Used on ${usedOn(libraryId)}` }}
      </p>
      <div v-if="library" class="mt-3 rounded-lg border bg-card p-3 sm:p-4">
        <TrainingSessionEditor :key="libraryId" :session="library" :warmups="draft.warmups" />
      </div>
    </section>
  </div>
</template>
