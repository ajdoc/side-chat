<script setup lang="ts">
import { Loader2, Play, Sparkles, X } from 'lucide-vue-next'
import type { Channel, VoiceEffect, VoiceEffects } from '~/types'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * Who this room greets, and how — the owner's to decide.
 *
 * The unit is a *person*: "fireworks when Ana walks in" is the thing worth having, and one
 * room-wide effect can't say it. What the call does for anybody in particular is the same
 * decision at a wider scope, so it's the first row of the same picker rather than a separate
 * concept — choose who, choose what, save.
 *
 * Deliberately not a personal preference. The point of an entrance is that it's *shared*:
 * everyone in the call sees and hears it at the same moment, which is what makes it a
 * greeting rather than a notification.
 *
 * The previews fire the real thing through the real overlay (mounted in the layout, above
 * this dialog), because an effect is not a description of itself — the only useful way to
 * choose one is to watch it happen.
 */
const props = defineProps<{ channel: Channel }>()

const { user } = useAuth()
const { loadChannelEffects, setChannelEffects } = useVoice()
const { fire } = useVoiceEffects()
const { members, load: loadMembers } = useChannelMembers()
const { nameFor } = useNicknames()

const open = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const effects = ref<VoiceEffects>({ default: { join: null, leave: null }, people: [] })

/** Who the two pickers are about: a member's id, or null for everybody else. */
const target = ref<number | null>(null)
// '' is the "Nothing" row; the API takes null. Converted in save(), so these stay ordinary
// string-valued <select>s.
const joinEffect = ref<VoiceEffect | ''>('')
const leaveEffect = ref<VoiceEffect | ''>('')

/**
 * An <option> value is always a string, so "everybody else" needs a token of its own —
 * binding null through would be indistinguishable from "nothing chosen yet".
 */
const targetValue = computed({
  get: () => (target.value === null ? 'default' : String(target.value)),
  set: (value: string) => { target.value = value === 'default' ? null : Number(value) },
})

/** The catalogue, bound in script so the auto-import resolves — a template reads `_ctx`. */
const effectOptions = VOICE_EFFECTS

/** What's attached to whoever is selected, so the pickers open showing the truth. */
function pairFor(userId: number | null) {
  if (userId === null) return effects.value.default

  return effects.value.people.find(p => p.user_id === userId) ?? { join: null, leave: null }
}

function seedPickers() {
  const pair = pairFor(target.value)
  joinEffect.value = pair.join ?? ''
  leaveEffect.value = pair.leave ?? ''
}

watch(target, seedPickers)

watch(open, async (isOpen) => {
  if (!isOpen) return

  error.value = null
  loading.value = true
  target.value = null

  // The roster is cached per channel by useChannelMembers, so reopening costs nothing.
  await Promise.all([
    loadMembers(props.channel.id),
    loadChannelEffects(props.channel.id)
      .then((data) => { effects.value = data })
      .catch(() => { error.value = 'Couldn\'t load this channel\'s effects.' }),
  ])

  seedPickers()
  loading.value = false
})

function nameOfUser(userId: number) {
  const member = members.value.find(m => m.id === userId)

  return member ? nameFor(member) : 'Someone who has left'
}

/** Everyone singled out so far — otherwise "who has an effect?" means clicking through
 *  every member one at a time. */
const assigned = computed(() =>
  effects.value.people.map(p => ({ ...p, name: nameOfUser(p.user_id) })),
)

const dirty = computed(() => {
  const pair = pairFor(target.value)

  return (pair.join ?? '') !== joinEffect.value || (pair.leave ?? '') !== leaveEffect.value
})

function labelOf(effect: VoiceEffect | null) {
  return effectOptions.find(e => e.value === effect)?.label ?? '—'
}

async function save(userId: number | null, join: VoiceEffect | '', leave: VoiceEffect | '') {
  saving.value = true
  error.value = null

  try {
    // The server hands back the whole payload — the same one it broadcasts to everybody —
    // so what we show afterwards is what the room actually has.
    effects.value = await setChannelEffects(props.channel.id, {
      userId,
      join: join || null,
      leave: leave || null,
    })
    if (userId === target.value) seedPickers()
  } catch {
    error.value = 'Couldn\'t save that. Try again.'
  } finally {
    saving.value = false
  }
}

/** Clearing both is how somebody stops being special: the row goes, the default comes back. */
function clearFor(userId: number) {
  return save(userId, '', '')
}

function preview(effect: VoiceEffect | '', phase: 'join' | 'leave') {
  if (!effect) return
  fire(effect, phase, 'Preview')
}
</script>

<template>
  <Dialog v-model:open="open">
    <button
      type="button"
      class="flex items-center gap-1 rounded px-2 py-0.5 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground"
      title="Choose what plays when someone joins or leaves this call"
      @click="open = true"
    >
      <Sparkles class="h-3.5 w-3.5" />
      Effects
    </button>

    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>Entrance &amp; exit effects</DialogTitle>
        <DialogDescription>
          Give someone their own arrival. Everyone in the call sees and hears it — you're
          setting it for the room, not just for yourself, and only you can change it.
        </DialogDescription>
      </DialogHeader>

      <div v-if="loading" class="flex items-center gap-2 py-6 text-sm text-muted-foreground">
        <Loader2 class="h-4 w-4 animate-spin" /> Loading…
      </div>

      <div v-else class="space-y-4">
        <label class="block space-y-1">
          <span class="text-sm font-medium">Who</span>
          <select v-model="targetValue" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
            <option value="default">Everyone else (the room's default)</option>
            <option v-for="m in members" :key="m.id" :value="String(m.id)">
              {{ nameFor(m) }}{{ m.id === user?.id ? ' (you)' : '' }}
            </option>
          </select>
        </label>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1">
            <label class="block space-y-1">
              <span class="text-sm">When they join</span>
              <select v-model="joinEffect" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
                <option value="">Nothing</option>
                <option v-for="e in effectOptions" :key="e.value" :value="e.value">{{ e.label }}</option>
              </select>
            </label>
            <button
              type="button"
              class="flex items-center gap-1 text-xs text-muted-foreground transition hover:text-foreground disabled:opacity-40"
              :disabled="!joinEffect"
              @click="preview(joinEffect, 'join')"
            >
              <Play class="h-3 w-3" /> Preview
            </button>
          </div>

          <div class="space-y-1">
            <label class="block space-y-1">
              <span class="text-sm">When they leave</span>
              <select v-model="leaveEffect" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
                <option value="">Nothing</option>
                <option v-for="e in effectOptions" :key="e.value" :value="e.value">{{ e.label }}</option>
              </select>
            </label>
            <button
              type="button"
              class="flex items-center gap-1 text-xs text-muted-foreground transition hover:text-foreground disabled:opacity-40"
              :disabled="!leaveEffect"
              @click="preview(leaveEffect, 'leave')"
            >
              <Play class="h-3 w-3" /> Preview
            </button>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2">
          <Button size="sm" :disabled="saving || !dirty" @click="save(target, joinEffect, leaveEffect)">
            {{ saving ? 'Saving…' : target === null ? 'Save default' : `Save for ${nameOfUser(target)}` }}
          </Button>
        </div>

        <div v-if="assigned.length" class="space-y-1 border-t pt-3">
          <p class="text-xs font-medium text-muted-foreground">Attached to someone</p>
          <div
            v-for="p in assigned"
            :key="p.user_id"
            class="flex items-center gap-2 rounded px-1 py-1 text-sm"
          >
            <span class="min-w-0 flex-1 truncate">{{ p.name }}</span>
            <span class="shrink-0 text-xs text-muted-foreground" title="On the way in / on the way out">
              {{ labelOf(p.join) }} / {{ labelOf(p.leave) }}
            </span>
            <button
              type="button"
              class="shrink-0 rounded p-1 text-muted-foreground transition hover:bg-muted hover:text-destructive disabled:opacity-40"
              :disabled="saving"
              :title="`Remove ${p.name}'s effect`"
              @click="clearFor(p.user_id)"
            >
              <X class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <p class="text-xs text-muted-foreground">
          The same effect plays a little lower and quieter on the way out, so you can tell an
          arrival from a departure without looking. Anyone who has deafened themselves — or who
          asked their system for less motion — gets the calm version.
        </p>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </div>
    </DialogContent>
  </Dialog>
</template>
