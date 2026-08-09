<script setup lang="ts">
import { Bell, BellOff, BellRing, Check, AtSign } from 'lucide-vue-next'
import type { NotifyLevel } from '~/types'
import type { NotifyTarget } from '~/lib/notifyPolicy'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

/**
 * "How loud should this place be" — one menu, used for a channel, a discussion and a chat.
 *
 * One component for all three because the setting is genuinely the same setting: they are
 * all channels underneath, and the row it writes to is the same row. The only difference is
 * which id addresses it, hence the two optional props.
 *
 * The level and the mute are shown together but written separately, because they are
 * different promises: a level is "how this place behaves", a mute is "not for the next
 * hour, then go back to how it was". Collapsing them into four levels would mean a mute
 * that silently destroyed the preference it was suspending.
 */
const props = defineProps<{
  /** Whichever of the two the caller holds. A conversation wins if both are somehow set. */
  channelId?: number | null
  conversationId?: number | null
  /** The current row, so the menu can show what's chosen without a fetch of its own. */
  notifyLevel?: NotifyLevel | null
  mutedUntil?: string | null
  /** Where this sits — a discussion inherits its channel, and the menu says so. */
  inheritsFrom?: string | null
  /**
   * The row itself, when the caller has it.
   *
   * Only used to draw the trigger, and only because a discussion can be silent *without
   * anything being set on it* — its channel is muted. Reading this row's own two fields
   * would draw an open bell over a place that will never make a sound.
   */
  target?: NotifyTarget | null
  /** Sidebar rows want a small icon; a header wants it labelled. */
  compact?: boolean
}>()

const { setLevel, mute } = useNotificationSettings()
const { muted, levelFor } = useNotifyPolicy()

const addressed = computed(() => ({ channelId: props.channelId, conversationId: props.conversationId }))

const isMuted = computed(() => muted({ muted_until: props.mutedUntil }))

/** What this place actually resolves to, inheritance and all — the trigger's whole story. */
const effective = computed(() => levelFor(props.target ?? {
  notify_level: props.notifyLevel,
  muted_until: props.mutedUntil,
}))

const LEVELS: { value: NotifyLevel, label: string, hint: string, icon: any }[] = [
  { value: 'all', label: 'All messages', hint: 'Every message here', icon: BellRing },
  { value: 'mentions', label: 'Mentions only', hint: 'Only when you\'re named', icon: AtSign },
  { value: 'none', label: 'Nothing', hint: 'Never alert me here', icon: BellOff },
]

/** Quiet for a while. The last one is "until I turn it back on" — a level, not a timer. */
const DURATIONS = [
  { minutes: 15, label: 'For 15 minutes' },
  { minutes: 60, label: 'For 1 hour' },
  { minutes: 480, label: 'For 8 hours' },
  { minutes: 1440, label: 'For 24 hours' },
]

/**
 * The bell to draw on the trigger.
 *
 * A muted place and one set to 'none' look the same from outside, and should: the user
 * asked for silence either way, and the difference (whether it comes back on its own) is
 * what the open menu is for.
 */
const icon = computed(() => effective.value === 'none' ? BellOff : Bell)

/**
 * Kept in the menu rather than thrown away, because a setting that silently didn't take is
 * the failure people notice weeks later, when the channel they muted is still pinging.
 */
const error = ref('')

async function apply(run: () => Promise<unknown>) {
  error.value = ''
  try {
    await run()
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not change that.'
  }
}

const choose = (level: NotifyLevel | null) => apply(() => setLevel(addressed.value, level))
const quiet = (minutes: number | null) => apply(() => mute(addressed.value, minutes))
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        class="rounded text-muted-foreground transition hover:text-foreground"
        :class="[compact ? 'p-1' : 'p-1.5', effective === 'none' && 'text-muted-foreground/60']"
        title="Notification settings"
        @click.stop.prevent
      >
        <component :is="icon" class="h-3.5 w-3.5" />
      </button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end" class="w-60" @click.stop>
      <DropdownMenuLabel class="text-xs font-medium text-muted-foreground">
        Notify me about
      </DropdownMenuLabel>

      <DropdownMenuItem v-for="l in LEVELS" :key="l.value" @select="choose(l.value)">
        <component :is="l.icon" class="mr-2 h-4 w-4" />
        <span class="flex-1">{{ l.label }}</span>
        <Check v-if="notifyLevel === l.value" class="h-3.5 w-3.5" />
      </DropdownMenuItem>

      <!--
        Clearing the override, spelled out rather than offered as a fourth level: "use my
        default" has to keep tracking that default as it changes, which is exactly what
        picking one of the three above would stop it doing.
      -->
      <DropdownMenuItem @select="choose(null)">
        <span class="mr-2 h-4 w-4" />
        <span class="flex-1">
          Use my default
          <span v-if="inheritsFrom" class="text-muted-foreground">({{ inheritsFrom }})</span>
        </span>
        <Check v-if="!notifyLevel" class="h-3.5 w-3.5" />
      </DropdownMenuItem>

      <DropdownMenuSeparator />

      <template v-if="isMuted">
        <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">
          Muted until {{ new Date(mutedUntil!).toLocaleString() }}
        </DropdownMenuLabel>
        <DropdownMenuItem @select="quiet(null)">
          <Bell class="mr-2 h-4 w-4" /> Unmute
        </DropdownMenuItem>
      </template>

      <template v-else>
        <DropdownMenuLabel class="text-xs font-medium text-muted-foreground">
          Mute
        </DropdownMenuLabel>
        <DropdownMenuItem v-for="d in DURATIONS" :key="d.minutes" @select="quiet(d.minutes)">
          <BellOff class="mr-2 h-4 w-4" /> {{ d.label }}
        </DropdownMenuItem>
      </template>

      <p v-if="error" class="px-2 py-1.5 text-xs text-destructive">{{ error }}</p>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
