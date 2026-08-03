<script setup lang="ts">
import { GripVertical, Plus, X } from 'lucide-vue-next'
import type { Automation, AutomationCatalogue, AutomationFieldSchema, Badge, Channel, ServerRole } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The editor for one rule: trigger → conditions → actions.
 *
 * Every form in here is generated from the catalogue the backend serves, not from a copy of
 * the field lists kept in TypeScript. That's the whole point of the component: an action
 * added in PHP gets a working form here without a matching frontend change, and the two can
 * never drift into disagreeing about what `post_message` takes.
 *
 * The shape mirrors how people describe a rule out loud — "when someone joins, if their
 * name contains X, post this" — and the three sections are ordered to match. Conditions sit
 * in the middle and are collapsed away when empty, because most rules don't have any and a
 * permanently-visible empty filter box makes a simple rule look complicated.
 */
const props = defineProps<{
  modelValue: Partial<Automation>
  catalogue: AutomationCatalogue
  channels: Channel[]
  badges: Badge[]
}>()

const emit = defineEmits<{ 'update:modelValue': [Partial<Automation>] }>()

const draft = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})

const roles: ServerRole[] = ['member', 'admin']

/**
 * The message textareas, keyed `actionIndex:fieldKey`.
 *
 * Held only so a placeholder chip can insert at the cursor rather than always appending —
 * "Welcome {user}" is usually wanted mid-sentence, and an append-only chip would mean
 * typing the braces by hand every time.
 */
const textareas = ref<Record<string, HTMLTextAreaElement | null>>({})

const trigger = computed(() => props.catalogue.triggers.find(t => t.name === draft.value.trigger))

/** The context keys this trigger supplies — the only sensible things to filter on. */
const conditionFields = computed(() => trigger.value?.fields ?? [])

function patch(changes: Partial<Automation>) {
  draft.value = { ...draft.value, ...changes }
}

function schemaFor(type: string): AutomationFieldSchema[] {
  return props.catalogue.actions.find(a => a.name === type)?.schema ?? []
}

function addAction() {
  const first = props.catalogue.actions[0]
  if (!first) return
  patch({ actions: [...(draft.value.actions ?? []), { type: first.name, config: {} }] })
}

function removeAction(index: number) {
  patch({ actions: (draft.value.actions ?? []).filter((_, i) => i !== index) })
}

/**
 * Changing an action's type clears its config.
 *
 * Keeping the old values would carry a channel id into an action that has no channel, and
 * the stale key would be saved and quietly ignored forever. Better to lose two fields than
 * to store something that means nothing.
 */
function setActionType(index: number, type: string) {
  const actions = [...(draft.value.actions ?? [])]
  actions[index] = { type, config: {} }
  patch({ actions })
}

function setActionConfig(index: number, key: string, value: unknown) {
  const actions = [...(draft.value.actions ?? [])]
  const action = actions[index]
  if (!action) return
  actions[index] = { ...action, config: { ...action.config, [key]: value } }
  patch({ actions })
}

/** Move an action up or down. Order is meaningful — "grant the badge, then announce it". */
function move(index: number, by: number) {
  const actions = [...(draft.value.actions ?? [])]
  const target = index + by
  if (target < 0 || target >= actions.length) return
  const [row] = actions.splice(index, 1)
  if (row) actions.splice(target, 0, row)
  patch({ actions })
}

function addCondition() {
  const field = conditionFields.value[0] ?? ''
  const operator = props.catalogue.operators[0]?.name ?? 'equals'
  patch({ conditions: [...(draft.value.conditions ?? []), { field, operator, value: '' }] })
}

function removeCondition(index: number) {
  patch({ conditions: (draft.value.conditions ?? []).filter((_, i) => i !== index) })
}

function setCondition(index: number, key: 'field' | 'operator' | 'value', value: unknown) {
  const conditions = [...(draft.value.conditions ?? [])]
  const row = conditions[index]
  if (!row) return
  conditions[index] = { ...row, [key]: value }
  patch({ conditions })
}

/** `is_empty` and `is_not_empty` take no value — showing an input would invite one. */
function takesValue(operator: string) {
  return operator !== 'is_empty' && operator !== 'is_not_empty'
}

/**
 * How a placeholder is written, for the chips.
 *
 * A function rather than an inline template expression: the literal braces confuse Vue's
 * template parser, which reads them as the start of an interpolation.
 */
function braced(token: string) {
  return `{${token}}`
}

/** Append a channel to a `channels` field, and reset the picker to its placeholder. */
function addChannel(index: number, key: string, event: Event) {
  const select = event.target as HTMLSelectElement
  const id = Number(select.value)
  const current = (draft.value.actions?.[index]?.config?.[key] as number[]) ?? []

  if (id) setActionConfig(index, key, [...current, id])
  select.value = ''
}

/** Drop a `{placeholder}` into a textarea at the cursor. */
function insertPlaceholder(index: number, key: string, token: string, el: HTMLTextAreaElement | null) {
  const current = String((draft.value.actions?.[index]?.config?.[key] as string) ?? '')
  const at = el?.selectionStart ?? current.length
  setActionConfig(index, key, `${current.slice(0, at)}{${token}}${current.slice(at)}`)
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted-foreground">Name</label>
      <input
        class="w-full rounded border bg-background px-2 py-1.5 text-sm"
        placeholder="What this rule is for"
        :value="draft.name ?? ''"
        @input="patch({ name: ($event.target as HTMLInputElement).value })"
      >
    </div>

    <!-- When -->
    <div>
      <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted-foreground">When</label>
      <select
        class="w-full rounded border bg-background px-2 py-1.5 text-sm"
        :value="draft.trigger ?? ''"
        @change="patch({ trigger: ($event.target as HTMLSelectElement).value, conditions: [] })"
      >
        <option value="" disabled>— pick a trigger —</option>
        <option v-for="t in catalogue.triggers" :key="t.name" :value="t.name">{{ t.label }}</option>
      </select>
      <p v-if="trigger" class="mt-1 text-xs text-muted-foreground">{{ trigger.description }}</p>
    </div>

    <!-- Only when. Hidden until asked for: most rules have no filter, and an empty box
         makes a simple rule look like it needs one. -->
    <div v-if="draft.trigger">
      <div class="mb-1 flex items-center justify-between">
        <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Only when</label>
        <button class="text-xs text-muted-foreground hover:text-foreground" @click="addCondition">
          <Plus class="mr-0.5 inline h-3 w-3" />Add a filter
        </button>
      </div>

      <p v-if="!(draft.conditions ?? []).length" class="text-xs text-muted-foreground">
        No filters — this runs every time.
      </p>

      <div v-for="(condition, index) in draft.conditions ?? []" :key="index" class="mb-1.5 flex items-center gap-1.5">
        <select
          class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-xs"
          :value="condition.field"
          @change="setCondition(index, 'field', ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="field in conditionFields" :key="field" :value="field">{{ field }}</option>
        </select>
        <select
          class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-xs"
          :value="condition.operator"
          @change="setCondition(index, 'operator', ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="op in catalogue.operators" :key="op.name" :value="op.name">{{ op.label }}</option>
        </select>
        <input
          v-if="takesValue(condition.operator)"
          class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-xs"
          :value="condition.value ?? ''"
          @input="setCondition(index, 'value', ($event.target as HTMLInputElement).value)"
        >
        <button class="text-muted-foreground hover:text-destructive" aria-label="Remove filter" @click="removeCondition(index)">
          <X class="h-3.5 w-3.5" />
        </button>
      </div>
      <p v-if="(draft.conditions ?? []).length > 1" class="mt-1 text-xs text-muted-foreground">
        All of these must be true. For “either / or”, make it two rules.
      </p>
    </div>

    <!-- Do -->
    <div>
      <div class="mb-1 flex items-center justify-between">
        <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Do</label>
        <button class="text-xs text-muted-foreground hover:text-foreground" @click="addAction">
          <Plus class="mr-0.5 inline h-3 w-3" />Add a step
        </button>
      </div>

      <p v-if="!(draft.actions ?? []).length" class="rounded border border-dashed px-3 py-4 text-center text-xs text-muted-foreground">
        A rule needs at least one step.
      </p>

      <div v-for="(action, index) in draft.actions ?? []" :key="index" class="mb-2 rounded-lg border p-2.5">
        <div class="flex items-center gap-1.5">
          <!-- Order matters, so it's movable. Buttons rather than drag: the list is
               almost never longer than three, and a keyboard can reach these. -->
          <div class="flex flex-col text-muted-foreground">
            <button class="hover:text-foreground disabled:opacity-30" :disabled="index === 0" aria-label="Move up" @click="move(index, -1)">
              <GripVertical class="h-3 w-3 rotate-90" />
            </button>
          </div>
          <span class="text-xs text-muted-foreground">{{ index + 1 }}.</span>
          <select
            class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-xs"
            :value="action.type"
            @change="setActionType(index, ($event.target as HTMLSelectElement).value)"
          >
            <option v-for="a in catalogue.actions" :key="a.name" :value="a.name">{{ a.label }}</option>
          </select>
          <button class="text-muted-foreground hover:text-destructive" aria-label="Remove step" @click="removeAction(index)">
            <X class="h-3.5 w-3.5" />
          </button>
        </div>

        <!-- The form, generated from the action's own schema. -->
        <div v-for="field in schemaFor(action.type)" :key="field.key" class="mt-2">
          <label class="mb-0.5 block text-[11px] font-medium text-muted-foreground">
            {{ field.label }}<span v-if="field.required" class="text-destructive"> *</span>
          </label>

          <select
            v-if="field.type === 'channel'"
            class="w-full rounded border bg-background px-2 py-1 text-xs"
            :value="(action.config[field.key] as number) ?? ''"
            @change="setActionConfig(index, field.key, ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
          >
            <option value="">— where the trigger happened —</option>
            <option v-for="channel in channels" :key="channel.id" :value="channel.id"># {{ channel.name }}</option>
          </select>

          <select
            v-else-if="field.type === 'badge'"
            class="w-full rounded border bg-background px-2 py-1 text-xs"
            :value="(action.config[field.key] as number) ?? ''"
            @change="setActionConfig(index, field.key, Number(($event.target as HTMLSelectElement).value))"
          >
            <option value="" disabled>— pick a badge —</option>
            <option v-for="badge in badges" :key="badge.id" :value="badge.id">{{ badge.emoji }} {{ badge.name }}</option>
          </select>

          <!-- The two pickers that make the built-ins composable: a rule can post a custom
               command's answer or send a schedule early, rather than duplicating the text. -->
          <!-- A list of channels, for a step that happens in several places at once. Chips
               plus an "add" dropdown rather than a multi-select box: the value is small,
               and removing one shouldn't need a ctrl-click nobody discovers. -->
          <template v-else-if="field.type === 'channels'">
            <div v-if="(action.config[field.key] as number[])?.length" class="mb-1 flex flex-wrap gap-1">
              <span
                v-for="id in (action.config[field.key] as number[])"
                :key="id"
                class="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[11px]"
              >
                # {{ channels.find(c => c.id === id)?.name ?? 'deleted channel' }}
                <button
                  class="text-muted-foreground hover:text-destructive"
                  aria-label="Remove channel"
                  @click="setActionConfig(index, field.key, (action.config[field.key] as number[]).filter(x => x !== id))"
                >
                  <X class="h-3 w-3" />
                </button>
              </span>
            </div>
            <select
              class="w-full rounded border border-dashed bg-background px-2 py-1 text-[11px] text-muted-foreground"
              value=""
              @change="addChannel(index, field.key, $event)"
            >
              <option value="">+ also post in…</option>
              <option
                v-for="channel in channels.filter(c => !((action.config[field.key] as number[]) ?? []).includes(c.id))"
                :key="channel.id"
                :value="channel.id"
              ># {{ channel.name }}</option>
            </select>
          </template>

          <select
            v-else-if="field.type === 'command' || field.type === 'schedule' || field.type === 'giveaway'"
            class="w-full rounded border bg-background px-2 py-1 text-xs"
            :value="(action.config[field.key] as number) ?? ''"
            @change="setActionConfig(index, field.key, Number(($event.target as HTMLSelectElement).value))"
          >
            <option value="" disabled>— pick one —</option>
            <option
              v-for="row in (field.type === 'command' ? catalogue.commands : field.type === 'schedule' ? catalogue.schedules : catalogue.giveaways)"
              :key="row.id"
              :value="row.id"
            >{{ row.name }}</option>
          </select>

          <select
            v-else-if="field.type === 'role'"
            class="w-full rounded border bg-background px-2 py-1 text-xs"
            :value="(action.config[field.key] as string) ?? ''"
            @change="setActionConfig(index, field.key, ($event.target as HTMLSelectElement).value)"
          >
            <option value="" disabled>— pick a role —</option>
            <option v-for="role in field.options ?? roles" :key="role" :value="role">{{ role }}</option>
          </select>

          <template v-else-if="field.type === 'textarea'">
            <textarea
              :ref="el => { textareas[`${index}:${field.key}`] = el as HTMLTextAreaElement }"
              class="w-full rounded border bg-background px-2 py-1 text-xs"
              rows="3"
              :value="(action.config[field.key] as string) ?? ''"
              @input="setActionConfig(index, field.key, ($event.target as HTMLTextAreaElement).value)"
            />
            <div v-if="field.placeholders?.length" class="mt-1 flex flex-wrap gap-1">
              <button
                v-for="token in field.placeholders"
                :key="token"
                class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground hover:text-foreground"
                @click="insertPlaceholder(index, field.key, token, textareas[`${index}:${field.key}`] ?? null)"
              >{{ braced(token) }}</button>
            </div>
          </template>

          <input
            v-else
            class="w-full rounded border bg-background px-2 py-1 text-xs"
            :type="field.type === 'number' ? 'number' : 'text'"
            :value="(action.config[field.key] as string) ?? ''"
            @input="setActionConfig(index, field.key, ($event.target as HTMLInputElement).value)"
          >

          <p v-if="field.help" class="mt-0.5 text-[11px] text-muted-foreground">{{ field.help }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
