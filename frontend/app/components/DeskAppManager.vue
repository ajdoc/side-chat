<script setup lang="ts">
import { Check, Lock, Plus } from 'lucide-vue-next'
import type { SideDeskAppId } from '~/types'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * "Which apps does this desk have?" — the picker behind the tab strip's + button.
 *
 * A single toggling list rather than an add-list and a remove-list, because the question really
 * is one per app: is it on this desk or not. Grouped, because the catalogue is now long enough
 * that a flat list of a dozen rows buries the workspace apps under the games.
 *
 * The changes it makes are shared by everyone on the surface, which is worth saying out loud in
 * the dialog — removing the Kanban tab removes it for the whole channel, and someone doing that
 * mid-conversation should know that's what they're doing.
 */
defineProps<{
  /** The ids currently on the strip. */
  enabled: Set<SideDeskAppId>
}>()

const emit = defineEmits<{ toggle: [SideDeskAppId] }>()

const open = defineModel<boolean>('open', { required: true })

const GROUPS = [
  { key: 'workspace', label: 'Workspace', hint: 'Shared surfaces this place builds on.' },
  { key: 'tool', label: 'Tools', hint: 'Live widgets — the same ones the canvas and chat use.' },
  { key: 'game', label: 'Games', hint: 'Play together without leaving the desk.' },
] as const

const grouped = computed(() =>
  GROUPS.map(g => ({ ...g, apps: DESK_APPS.filter(a => a.group === g.key) })).filter(g => g.apps.length),
)
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Desk apps</DialogTitle>
        <DialogDescription>
          Pick the tabs this desk shows. Everyone here sees the same strip, and nothing is
          deleted when you remove a tab — the board, notes and widgets keep whatever's on them.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-5">
        <section v-for="g in grouped" :key="g.key">
          <h3 class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ g.label }}</h3>
          <p class="mb-2 text-xs text-muted-foreground/80">{{ g.hint }}</p>

          <ul class="space-y-1">
            <li v-for="a in g.apps" :key="a.id">
              <button
                type="button"
                class="flex w-full items-center gap-3 rounded-lg border p-2.5 text-left transition-colors"
                :class="[
                  enabled.has(a.id) ? 'border-primary/40 bg-primary/5' : 'hover:bg-muted/60',
                  a.removable ? '' : 'cursor-default opacity-80',
                ]"
                :disabled="!a.removable"
                @click="emit('toggle', a.id)"
              >
                <span
                  class="grid h-9 w-9 flex-none place-items-center rounded-lg"
                  :class="enabled.has(a.id) ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'"
                >
                  <component :is="a.icon" class="h-[18px] w-[18px]" />
                </span>

                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium">{{ a.label }}</span>
                  <span class="block text-xs text-muted-foreground">
                    <!-- Say where else it can live: that the same thing can be a tab *and* a
                         canvas card is the whole point of the redesign, and it isn't obvious
                         from a list of tab names. -->
                    {{ a.canvasable ? 'Tab, or a card on the canvas' : 'Tab only' }}
                  </span>
                </span>

                <span class="flex-none text-muted-foreground">
                  <Lock v-if="!a.removable" class="h-4 w-4" aria-label="Always on" />
                  <Check v-else-if="enabled.has(a.id)" class="h-4 w-4 text-primary" aria-label="On this desk" />
                  <Plus v-else class="h-4 w-4" aria-label="Add to this desk" />
                </span>
              </button>
            </li>
          </ul>
        </section>
      </div>
    </DialogContent>
  </Dialog>
</template>
