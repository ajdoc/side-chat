<script setup lang="ts">
import { LayoutGrid, Star } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import type { SideDeskAppId } from '~/types'

/**
 * The whole app catalogue, one press from the channel header.
 *
 * The Side Desk still owns *arranging* apps — which ones a place keeps on its strip, shared by
 * everyone on it. This menu is the other need: open one, now, without rearranging anything.
 * Everything here opens floating (see {@link useAppLauncher}), so the app you pick outlives the
 * channel you picked it from, exactly like popping a card out of the timeline.
 *
 * The star on each row sets your favourite, which is the app {@link FavoriteAppButton} puts in
 * the header beside this button. That's a local preference, so it's a star rather than
 * something in the surface's shared settings.
 *
 * Rows are plain buttons inside the menu rather than `DropdownMenuItem`s: each row carries two
 * targets — the app, and its star — and a menu item is a single one.
 */
const props = defineProps<{
  channelId: number
  /** Force the icon-only form — see the same prop on {@link FavoriteAppButton}. */
  iconOnly?: boolean
}>()

const { favorite, launch } = useAppLauncher()
const { narrow } = useNavDrawer()

const compact = computed(() => props.iconOnly || narrow.value)

const open = ref(false)
const busy = ref<SideDeskAppId | null>(null)
const error = ref('')

const GROUPS: { id: 'workspace' | 'tool' | 'game', label: string }[] = [
  { id: 'workspace', label: 'Workspace' },
  { id: 'tool', label: 'Tools' },
  { id: 'game', label: 'Games' },
]

const grouped = computed(() => GROUPS
  .map(g => ({ ...g, apps: DESK_APPS.filter(a => a.group === g.id) }))
  .filter(g => g.apps.length))

async function pick(id: SideDeskAppId) {
  busy.value = id
  error.value = ''
  const message = await launch(id, props.channelId)
  busy.value = null
  if (message) {
    error.value = message
    return
  }
  open.value = false
}

function star(id: SideDeskAppId) {
  // Un-starring would leave the header button with nothing to be, so the star only ever moves.
  favorite.value = id
}
</script>

<template>
  <DropdownMenu v-model:open="open">
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" :class="compact && 'px-2'" title="Apps" aria-label="Apps">
        <LayoutGrid class="h-4 w-4" /> <span v-if="!compact">Apps</span>
      </Button>
    </DropdownMenuTrigger>

    <!-- Sized for the smallest screen it opens on: the catalogue is a dozen rows and two
         group headings, which is taller than a phone, so the menu scrolls inside itself and
         never grows past the viewport's width. -->
    <DropdownMenuContent align="end" class="max-h-[70vh] w-64 max-w-[calc(100vw-1.5rem)] overflow-y-auto">
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">
        Opens in a floating window
      </DropdownMenuLabel>

      <template v-for="(group, i) in grouped" :key="group.id">
        <DropdownMenuSeparator v-if="i" />
        <DropdownMenuLabel class="text-[11px] uppercase tracking-wide text-muted-foreground">
          {{ group.label }}
        </DropdownMenuLabel>

        <div
          v-for="a in group.apps"
          :key="a.id"
          class="flex items-center gap-1 rounded-sm px-1"
        >
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-2 rounded-sm px-1.5 py-2.5 text-sm transition-colors hover:bg-accent hover:text-accent-foreground disabled:opacity-50"
            :disabled="busy === a.id"
            @click="pick(a.id)"
          >
            <component :is="a.icon" class="h-4 w-4 shrink-0 text-muted-foreground" />
            <span class="truncate">{{ a.label }}</span>
          </button>
          <button
            type="button"
            class="flex-none rounded-sm p-2.5 transition-colors hover:bg-accent"
            :class="favorite === a.id ? 'text-primary' : 'text-muted-foreground/50 hover:text-foreground'"
            :title="favorite === a.id ? `${a.label} is your favourite app` : `Make ${a.label} your favourite`"
            :aria-pressed="favorite === a.id"
            @click="star(a.id)"
          >
            <Star class="h-4 w-4" :fill="favorite === a.id ? 'currentColor' : 'none'" />
          </button>
        </div>
      </template>

      <p v-if="error" class="px-2 py-1.5 text-xs text-destructive">
        {{ error }}
      </p>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
