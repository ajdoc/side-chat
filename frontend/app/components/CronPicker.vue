<script setup lang="ts">
/**
 * Picking when a schedule runs, without anybody having to know cron.
 *
 * The stored value has always been a five-field cron expression — the column is general on
 * purpose — but the first version of this offered four fixed presets, which meant "every
 * weekday at 8:30" or "Mondays and Thursdays" simply couldn't be expressed. This builds the
 * expression from the three questions people actually ask: how often, what time, and which
 * days.
 *
 * The raw field stays, one click away. Anything the builder can't say (every 15 minutes, the
 * last Friday of the quarter) is still typeable, and a rule that arrives with an expression
 * the builder can't represent opens straight into that field rather than being silently
 * rewritten into something simpler.
 */
const model = defineModel<string>({ default: '0 9 * * 1' })

type Frequency = 'hourly' | 'daily' | 'weekly' | 'monthly' | 'custom'

const DAYS = [
  { value: 1, short: 'Mon' },
  { value: 2, short: 'Tue' },
  { value: 3, short: 'Wed' },
  { value: 4, short: 'Thu' },
  { value: 5, short: 'Fri' },
  { value: 6, short: 'Sat' },
  { value: 0, short: 'Sun' },
]

const frequency = ref<Frequency>('weekly')
const time = ref('09:00')
const weekdays = ref<number[]>([1])
const monthDay = ref(1)
const minute = ref(0)

/** Parse whatever we were handed, so editing an existing schedule starts where it left off. */
function readFrom(expression: string) {
  const parts = expression.trim().split(/\s+/)
  if (parts.length !== 5) return void (frequency.value = 'custom')

  const [min, hour, dom, month, dow] = parts as [string, string, string, string, string]
  const simpleMinute = /^\d{1,2}$/.test(min)

  // Anything with a step, a range or a list where we don't support one is left as custom
  // rather than approximated — showing "every day at 9" for `*/15 * * * *` would be a lie.
  if (month !== '*' || !simpleMinute) return void (frequency.value = 'custom')

  if (hour === '*' && dom === '*' && dow === '*') {
    frequency.value = 'hourly'
    minute.value = Number(min)
    return
  }

  if (!/^\d{1,2}$/.test(hour)) return void (frequency.value = 'custom')

  time.value = `${String(hour).padStart(2, '0')}:${String(min).padStart(2, '0')}`

  if (dom === '*' && dow === '*') return void (frequency.value = 'daily')

  if (dom === '*' && /^[0-6](,[0-6])*$/.test(dow)) {
    frequency.value = 'weekly'
    weekdays.value = dow.split(',').map(Number)
    return
  }

  if (dow === '*' && /^\d{1,2}$/.test(dom)) {
    frequency.value = 'monthly'
    monthDay.value = Number(dom)
    return
  }

  frequency.value = 'custom'
}

readFrom(model.value)

/** Build the expression from the answers. Custom is left alone — it *is* the answer. */
function write() {
  if (frequency.value === 'custom') return

  const [hour = '9', min = '0'] = time.value.split(':')
  const h = Number(hour)
  const m = Number(min)

  model.value = {
    hourly: `${minute.value} * * * *`,
    // An empty day selection would produce an expression that never runs, so it falls back
    // to Monday rather than silently becoming a schedule that does nothing.
    weekly: `${m} ${h} * * ${(weekdays.value.length ? [...weekdays.value].sort() : [1]).join(',')}`,
    daily: `${m} ${h} * * *`,
    monthly: `${m} ${h} ${monthDay.value} * *`,
  }[frequency.value]
}

function toggleDay(day: number) {
  weekdays.value = weekdays.value.includes(day)
    ? weekdays.value.filter(d => d !== day)
    : [...weekdays.value, day]
  write()
}

/** Weekdays / weekends in one click — by far the two most-asked-for sets. */
function setDays(days: number[]) {
  weekdays.value = days
  write()
}

watch([frequency, time, monthDay, minute], write)

const summary = computed(() => {
  const parts = model.value.trim().split(/\s+/)
  if (parts.length !== 5) return 'Not a schedule we can read.'

  const [min, hour, dom, , dow] = parts as [string, string, string, string, string]
  const at = /^\d{1,2}$/.test(hour) && /^\d{1,2}$/.test(min)
    ? `${String(hour).padStart(2, '0')}:${String(min).padStart(2, '0')}`
    : null

  if (frequency.value === 'custom') return `Cron: ${model.value}`
  if (hour === '*') return `Every hour at ${String(min).padStart(2, '0')} past`
  if (dom === '*' && dow === '*') return `Every day at ${at}`
  if (dom === '*') {
    const names = dow.split(',').map(d => DAYS.find(x => x.value === Number(d))?.short).filter(Boolean)
    return `Every ${names.join(', ')} at ${at}`
  }

  return `Day ${dom} of each month at ${at}`
})
</script>

<template>
  <div class="space-y-2">
    <div class="flex flex-wrap gap-2">
      <select v-model="frequency" class="rounded border bg-background px-2 py-1 text-xs">
        <option value="hourly">Every hour</option>
        <option value="daily">Every day</option>
        <option value="weekly">Certain days</option>
        <option value="monthly">Once a month</option>
        <option value="custom">Custom (cron)</option>
      </select>

      <label v-if="frequency === 'hourly'" class="flex items-center gap-1 text-xs text-muted-foreground">
        at
        <input v-model.number="minute" type="number" min="0" max="59" class="w-14 rounded border bg-background px-1.5 py-1 text-xs">
        past
      </label>

      <input
        v-if="frequency === 'daily' || frequency === 'weekly' || frequency === 'monthly'"
        v-model="time"
        type="time"
        class="rounded border bg-background px-2 py-1 text-xs"
      >

      <label v-if="frequency === 'monthly'" class="flex items-center gap-1 text-xs text-muted-foreground">
        on day
        <input v-model.number="monthDay" type="number" min="1" max="28" class="w-14 rounded border bg-background px-1.5 py-1 text-xs">
      </label>
    </div>

    <!-- Day-of-month stops at 28 on purpose: a schedule set for the 31st would skip four
         months of the year, which is never what somebody meant by "monthly". -->
    <p v-if="frequency === 'monthly'" class="text-[11px] text-muted-foreground">
      Up to the 28th, so it happens every month including February.
    </p>

    <div v-if="frequency === 'weekly'" class="flex flex-wrap items-center gap-1">
      <button
        v-for="day in DAYS"
        :key="day.value"
        class="rounded border px-2 py-0.5 text-xs"
        :class="weekdays.includes(day.value) ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground'"
        @click="toggleDay(day.value)"
      >{{ day.short }}</button>
      <button class="ml-1 text-[11px] text-muted-foreground underline" @click="setDays([1, 2, 3, 4, 5])">Weekdays</button>
      <button class="text-[11px] text-muted-foreground underline" @click="setDays([6, 0])">Weekend</button>
    </div>

    <input
      v-if="frequency === 'custom'"
      v-model="model"
      class="w-full rounded border bg-background px-2 py-1 font-mono text-xs"
      placeholder="0 9 * * 1"
    >

    <p class="text-[11px] text-muted-foreground">{{ summary }}</p>
  </div>
</template>
