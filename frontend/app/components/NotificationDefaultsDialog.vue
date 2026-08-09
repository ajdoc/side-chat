<script setup lang="ts">
import { AtSign, BellOff, BellRing } from 'lucide-vue-next'
import type { NotifyLevel } from '~/types'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog'

/**
 * Your account's notification defaults — what applies anywhere you haven't said otherwise.
 *
 * The bottom of the resolution chain, which is why there's no "use my default" option in
 * here: there is nothing underneath to inherit from. Changing one of these moves every
 * place you never explicitly set, and only those — a channel you muted or pinned stays as
 * you left it. See useNotifyPolicy for the order, and the server's NotificationPolicy for
 * the copy that decides pushes.
 */
const open = defineModel<boolean>({ required: true })

const { user, updatePreferences } = useAuth()

const LEVELS: { value: NotifyLevel, label: string, icon: any }[] = [
  { value: 'all', label: 'All messages', icon: BellRing },
  { value: 'mentions', label: 'Mentions only', icon: AtSign },
  { value: 'none', label: 'Nothing', icon: BellOff },
]

const error = ref('')

async function set(fields: Record<string, unknown>) {
  error.value = ''
  try {
    await updatePreferences(fields)
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not save that.'
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Notifications</DialogTitle>
        <DialogDescription>
          What to tell you about, anywhere you haven't set something more specific.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-5">
        <!--
          Two defaults rather than one, because the honest answer differs by kind of room:
          a channel of two hundred people is noise unless it names you, and a DM was
          addressed to you by definition. One setting for both would be wrong for one of them.
        -->
        <div>
          <p class="mb-2 text-sm font-medium">Server channels</p>
          <div class="grid grid-cols-3 gap-1.5">
            <button
              v-for="l in LEVELS"
              :key="l.value"
              type="button"
              class="flex flex-col items-center gap-1 rounded-md border py-2 text-[11px] transition"
              :class="(user?.notify_channel_default ?? 'mentions') === l.value
                ? 'border-primary bg-accent text-accent-foreground'
                : 'text-muted-foreground hover:bg-muted/50'"
              @click="set({ notify_channel_default: l.value })"
            >
              <component :is="l.icon" class="h-4 w-4" />
              {{ l.label }}
            </button>
          </div>
        </div>

        <div>
          <p class="mb-2 text-sm font-medium">Direct messages &amp; group chats</p>
          <div class="grid grid-cols-3 gap-1.5">
            <button
              v-for="l in LEVELS"
              :key="l.value"
              type="button"
              class="flex flex-col items-center gap-1 rounded-md border py-2 text-[11px] transition"
              :class="(user?.notify_dm_default ?? 'all') === l.value
                ? 'border-primary bg-accent text-accent-foreground'
                : 'text-muted-foreground hover:bg-muted/50'"
              @click="set({ notify_dm_default: l.value })"
            >
              <component :is="l.icon" class="h-4 w-4" />
              {{ l.label }}
            </button>
          </div>
        </div>

        <!--
          Separate from the levels above on purpose: "stop buzzing my phone" and "stop
          telling me about this channel" are different requests, and folding them together
          would mean turning off push to get a quiet channel, or losing every alert to
          silence a phone.
        -->
        <label class="flex items-start gap-3 rounded-md border p-3">
          <input
            type="checkbox"
            class="mt-0.5"
            :checked="user?.push_enabled !== false"
            @change="set({ push_enabled: ($event.target as HTMLInputElement).checked })"
          >
          <span class="text-sm">
            Push notifications on my phone
            <span class="mt-0.5 block text-xs text-muted-foreground">
              Sent only when a message clears the levels above. Nothing is pushed while the
              app is open in front of you.
            </span>
          </span>
        </label>

        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
      </div>
    </DialogContent>
  </Dialog>
</template>
