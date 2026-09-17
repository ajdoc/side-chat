<script setup lang="ts">
import { ChevronDown, ChevronRight } from 'lucide-vue-next'
import type { SignupPayablePerson, SignupPayables } from '~/types'
import { formatMoney } from '~/lib/signups'

/**
 * Who trained in a month (or a year), and what they owe.
 *
 * Every attending name on a sheet owes that sheet's fees; this groups those charges by person.
 * Staff tick charges off as paid, one at a time or a person's whole period at once.
 */
const props = defineProps<{
  signups: ReturnType<typeof useSignups>
  canManage: boolean
  defaultYear: number | null
}>()

const now = new Date()
const period = ref<'month' | 'year'>('month')
const month = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`)
const year = ref(props.defaultYear ?? now.getFullYear())
const onlyUnpaid = ref(false)
const search = ref('')

const data = ref<SignupPayables | null>(null)
const loading = ref(false)
const error = ref('')
const expanded = ref(new Set<number>())

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await props.signups.payables(period.value === 'month' ? { month: month.value } : { year: year.value })
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load payables.'
  }
  finally {
    loading.value = false
  }
}

watch([period, month, year], load, { immediate: true })
// Someone else changed a sheet or a payment.
watch(props.signups.payablesTick, load)

const people = computed(() => (data.value?.people ?? [])
  .filter(p => !onlyUnpaid.value || p.balance > 0)
  .filter(p => !search.value.trim() || p.name.toLowerCase().includes(search.value.trim().toLowerCase())))

function toggle(id: number) {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

const busy = ref(false)

async function mark(ids: number[], paid: boolean) {
  if (!ids.length || busy.value) return
  busy.value = true
  try {
    await props.signups.markPaid(ids, paid)
    await load()
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not save that.'
  }
  finally {
    busy.value = false
  }
}

function settleAll(p: SignupPayablePerson) {
  void mark(p.charges.filter(c => !c.paid).map(c => c.id), true)
}

function shortDate(d: string) {
  return new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

const tiles = computed(() => data.value
  ? [
      { label: 'People', value: String(data.value.totals.people) },
      { label: 'Charged', value: formatMoney(data.value.totals.due) },
      { label: 'Paid', value: formatMoney(data.value.totals.paid) },
      { label: 'Outstanding', value: formatMoney(data.value.totals.balance) },
    ]
  : [])

const years = computed(() => {
  const list = props.signups.years.value.map(y => y.year)
  if (!list.includes(now.getFullYear())) list.unshift(now.getFullYear())
  return list
})
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto p-4">
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex rounded-md border p-0.5">
        <button
          v-for="p in (['month', 'year'] as const)"
          :key="p"
          type="button"
          class="rounded px-2.5 py-1 text-xs"
          :class="period === p ? 'bg-muted font-medium' : 'text-muted-foreground'"
          @click="period = p"
        >
          {{ p === 'month' ? 'Month' : 'Year' }}
        </button>
      </div>
      <input v-if="period === 'month'" v-model="month" type="month" class="h-8 rounded-md border bg-background px-2 text-sm" aria-label="Month">
      <select v-else v-model.number="year" class="h-8 rounded-md border bg-background px-2 text-sm" aria-label="Year">
        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
      </select>
      <input v-model="search" placeholder="Find a name" class="h-8 w-40 rounded-md border bg-background px-2 text-sm">
      <label class="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground">
        <input v-model="onlyUnpaid" type="checkbox" class="h-3.5 w-3.5"> Unpaid only
      </label>
    </div>

    <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>

    <div v-if="tiles.length" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div v-for="t in tiles" :key="t.label" class="rounded-lg border px-3 py-2">
        <p class="text-[11px] uppercase tracking-wide text-muted-foreground">{{ t.label }}</p>
        <p class="text-xl font-semibold tabular-nums">{{ t.value }}</p>
      </div>
    </div>

    <p v-if="loading && !data" class="text-sm text-muted-foreground">Loading…</p>

    <p v-else-if="data && !people.length" class="rounded-xl border border-dashed px-3 py-10 text-center text-sm text-muted-foreground">
      {{ data.people.length ? 'Nobody matches.' : 'No charges in this period. Charges appear when someone signs up in an attending column on a sheet with fees.' }}
    </p>

    <div v-else-if="data" class="overflow-x-auto rounded-lg border">
      <table class="w-full min-w-[34rem] text-sm">
        <thead class="bg-muted/60 text-left text-xs text-muted-foreground">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 text-right font-medium">Sessions</th>
            <th class="px-3 py-2 text-right font-medium">Charged</th>
            <th class="px-3 py-2 text-right font-medium">Paid</th>
            <th class="px-3 py-2 text-right font-medium">Balance</th>
            <th v-if="canManage" class="px-3 py-2" />
          </tr>
        </thead>
        <tbody>
          <template v-for="p in people" :key="p.person_id">
            <tr class="cursor-pointer border-t hover:bg-muted/40" @click="toggle(p.person_id)">
              <td class="px-3 py-2 font-medium">
                <component :is="expanded.has(p.person_id) ? ChevronDown : ChevronRight" class="mr-1 inline h-3.5 w-3.5 text-muted-foreground" />
                {{ p.name }}
              </td>
              <td class="px-3 py-2 text-right tabular-nums">{{ p.sessions }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(p.due) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(p.paid) }}</td>
              <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="p.balance > 0 ? 'text-destructive' : 'text-muted-foreground'">
                {{ p.balance > 0 ? formatMoney(p.balance) : 'Settled' }}
              </td>
              <td v-if="canManage" class="px-3 py-2 text-right">
                <button
                  v-if="p.balance > 0"
                  type="button"
                  class="rounded-md border px-2 py-1 text-xs transition-colors hover:bg-muted disabled:opacity-50"
                  :disabled="busy"
                  @click.stop="settleAll(p)"
                >
                  Mark all paid
                </button>
              </td>
            </tr>
            <tr v-if="expanded.has(p.person_id)" class="bg-muted/20">
              <td :colspan="canManage ? 6 : 5" class="px-3 pb-3 pt-1">
                <ul class="divide-y rounded-md border bg-background">
                  <li v-for="c in p.charges" :key="c.id" class="flex items-center gap-3 px-3 py-1.5 text-xs">
                    <label class="flex min-w-0 flex-1 items-center gap-2" :class="canManage ? 'cursor-pointer' : ''">
                      <input
                        type="checkbox"
                        class="h-3.5 w-3.5"
                        :checked="c.paid"
                        :disabled="!canManage || busy"
                        :aria-label="`${c.label} paid`"
                        @change="mark([c.id], !c.paid)"
                      >
                      <span class="w-14 shrink-0 tabular-nums text-muted-foreground">{{ shortDate(c.billed_on) }}</span>
                      <span class="truncate">{{ c.sheet_title }}</span>
                      <span v-if="c.group" class="shrink-0 text-muted-foreground">· {{ c.group }}</span>
                    </label>
                    <span class="shrink-0 text-muted-foreground">{{ c.label }}</span>
                    <span class="w-16 shrink-0 text-right tabular-nums" :class="c.paid ? 'text-muted-foreground line-through' : ''">{{ formatMoney(c.amount) }}</span>
                  </li>
                </ul>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>
