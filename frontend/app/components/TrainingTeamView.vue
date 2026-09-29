<script setup lang="ts">
import type { TrainingProgram } from '~/types'
import { currentWeek, programSessionsDone, programSessionsTotal, weekSessionsDone, weekSessionsTotal } from '~/lib/training'

/**
 * Everyone's progress on one program: sessions finished per week, for each person who has
 * ticked anything. Kept live by the composable, so a teammate finishing a session shows here.
 */
const props = defineProps<{
  program: TrainingProgram
  training: ReturnType<typeof useTraining>
}>()

const { team } = props.training
const error = ref('')

onMounted(async () => {
  try {
    await props.training.loadTeam()
  }
  catch {
    error.value = 'Couldn’t load the team’s progress.'
  }
})

const content = computed(() => props.program.content)
const weeks = computed(() => Array.from({ length: content.value.weeks }, (_, i) => i + 1))
const perWeek = computed(() => Math.max(...weeks.value.map(w => weekSessionsTotal(content.value, w)), 0))
const total = computed(() => programSessionsTotal(content.value))
const thisWeek = computed(() => currentWeek(props.program.starts_on, props.program.weeks))

const rows = computed(() => (team.value ?? []).map((m) => {
  const set = new Set(m.ticks)
  const byWeek = weeks.value.map(w => ({ n: weekSessionsDone(content.value, set, w), of: weekSessionsTotal(content.value, w) }))
  return { user: m.user, byWeek, done: programSessionsDone(content.value, set) }
}).sort((a, b) => b.done - a.done || a.user.name.localeCompare(b.user.name)))

function cellClass(n: number, of: number) {
  if (n === 0) return 'text-muted-foreground/60'
  if (n >= of) return 'bg-emerald-700 font-semibold text-white'
  return 'bg-emerald-500/20 text-foreground'
}
</script>

<template>
  <div class="mx-auto w-full max-w-4xl px-4 pb-10 pt-4">
    <p class="text-sm text-muted-foreground">
      Sessions finished each week (usually out of {{ perWeek }}). A session counts once every block in it is ticked.
    </p>

    <p v-if="error" class="mt-4 text-sm text-destructive">{{ error }}</p>
    <p v-else-if="team === null" class="mt-6 text-sm text-muted-foreground">Loading…</p>
    <p v-else-if="!rows.length" class="mt-6 rounded-lg border bg-card p-5 text-sm text-muted-foreground">
      Nobody has ticked anything yet. Progress shows up here once people start.
    </p>

    <div v-else class="mt-4 overflow-x-auto rounded-lg border">
      <table class="w-full border-collapse text-sm">
        <thead>
          <tr class="border-b bg-muted/40 text-xs text-muted-foreground">
            <th class="sticky left-0 z-10 bg-muted px-3 py-2 text-left font-medium">Name</th>
            <th
              v-for="w in weeks"
              :key="w"
              class="w-9 px-1 py-2 text-center font-medium"
              :class="w === thisWeek ? 'text-foreground underline decoration-amber-400 decoration-2 underline-offset-4' : ''"
              :title="`Week ${w}`"
            >
              {{ w }}
            </th>
            <th class="px-3 py-2 text-right font-medium">Total</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.user.id" class="border-b last:border-b-0">
            <td class="sticky left-0 z-10 max-w-[10rem] truncate bg-background px-3 py-2 font-medium">{{ r.user.name }}</td>
            <td v-for="(c, i) in r.byWeek" :key="i" class="p-0.5 text-center">
              <span class="grid h-7 place-items-center rounded tabular-nums" :class="cellClass(c.n, c.of)" :title="`Week ${i + 1}: ${c.n} of ${c.of}`">{{ c.n || '·' }}</span>
            </td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
              {{ r.done }}<span class="text-muted-foreground"> / {{ total }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
