<script setup lang="ts">
import { AudioLines, Monitor, AppWindow } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import type { ScreenShareRequest } from '~/composables/useScreenSourcePicker'

/**
 * "Which screen?" — the desktop app's answer to a question the browser normally asks.
 *
 * Chromium's own picker isn't available to an Electron app, so the shell hands the choice back
 * to the page and this is where it lands. Without it, pressing "Share screen" on the desktop
 * build silently shared whichever monitor happened to be first, which is what it did until now.
 *
 * Mounted once, in the app layout, because a share can be started from a voice channel, a call
 * in a DM, or a Side Space — and all three go through the same getDisplayMedia.
 */
const { open, request, listen, choose, cancel } = useScreenSourcePicker()

let unlisten: (() => void) | undefined
onMounted(() => { unlisten = listen() })
onBeforeUnmount(() => unlisten?.())

const selected = ref<string | null>(null)
const withAudio = ref(true)

/**
 * The request the dialog is *drawing*, which outlives the one it's waiting on.
 *
 * Answering clears `request`, and that's what closes the dialog — so binding the body to it
 * directly tore the content out of the DOM in the same tick as the click that closed it.
 * Reka's dismissable layer and focus scope are still unwinding at that point, and reaching
 * for a node that no longer exists is the error that came out of pressing the X. Keeping the
 * last request lets the close animation run against real markup and unmount on its own.
 */
const shown = ref<ScreenShareRequest | null>(null)

const screens = computed(() => shown.value?.sources.filter(s => s.kind === 'screen') ?? [])
const windows = computed(() => shown.value?.sources.filter(s => s.kind === 'window') ?? [])

// Each fresh request opens on the first screen, which is the answer most of the time, and
// re-arms the audio tick — it's per-share, not a remembered preference.
watch(request, (next) => {
  if (!next) return // a cleared request is the dialog closing; leave `shown` for the animation
  shown.value = next
  selected.value = next.sources[0]?.id ?? null
  withAudio.value = next.audioRequested && next.audioSupported
})

function share() {
  if (!selected.value) return
  choose(selected.value, withAudio.value)
}

// Dismissing by escape or the backdrop has to reach Electron too, or getDisplayMedia never
// settles and the share button stays stuck mid-press.
function onOpenChange(next: boolean) {
  if (!next) cancel()
}

/** Named, so "not available on Linux" reads as a fact about this machine rather than a shrug. */
const platformName = computed(() => {
  const platform = (globalThis as any).sideChatDesktop?.platform
  return platform === 'darwin' ? 'macOS' : platform === 'linux' ? 'Linux' : 'this platform'
})
</script>

<template>
  <Dialog :open="open" @update:open="onOpenChange">
    <DialogContent v-if="shown" class="max-w-3xl">
      <DialogHeader>
        <DialogTitle>Share your screen</DialogTitle>
        <DialogDescription>
          Pick a whole screen, or a single window if you'd rather not show everything.
        </DialogDescription>
      </DialogHeader>

      <div class="max-h-[55vh] space-y-4 overflow-y-auto pr-1">
        <section v-for="group in [
          { label: 'Screens', icon: Monitor, items: screens },
          { label: 'Windows', icon: AppWindow, items: windows },
        ]" :key="group.label">
          <p v-if="group.items.length" class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            <component :is="group.icon" class="h-3.5 w-3.5" /> {{ group.label }}
          </p>

          <div v-if="group.items.length" class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <button
              v-for="source in group.items"
              :key="source.id"
              type="button"
              class="overflow-hidden rounded-lg border-2 p-1.5 text-left transition-colors"
              :class="selected === source.id ? 'border-primary bg-primary/5' : 'border-transparent hover:bg-muted'"
              @click="selected = source.id"
              @dblclick="selected = source.id; share()"
            >
              <img
                v-if="source.thumbnail"
                :src="source.thumbnail"
                :alt="source.name"
                class="mb-1.5 aspect-video w-full rounded border bg-muted object-contain"
              >
              <div v-else class="mb-1.5 grid aspect-video w-full place-items-center rounded border bg-muted text-muted-foreground">
                <component :is="group.icon" class="h-6 w-6" />
              </div>
              <span class="flex items-center gap-1.5">
                <img v-if="source.icon" :src="source.icon" alt="" class="h-4 w-4 shrink-0 rounded-sm">
                <span class="truncate text-xs">{{ source.name }}</span>
              </span>
            </button>
          </div>
        </section>
      </div>

      <!--
        Sound is a Windows-only capability of the shell, not a choice we can offer everywhere:
        Chromium's loopback capture has no macOS or Linux equivalent. Said plainly, because a
        silent share with a ticked "share audio" box is the more confusing of the two failures.
      -->
      <label
        v-if="shown.audioRequested"
        class="flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-2 text-sm"
        :class="shown.audioSupported ? '' : 'text-muted-foreground'"
      >
        <input
          v-model="withAudio"
          type="checkbox"
          class="h-4 w-4 accent-primary"
          :disabled="!shown.audioSupported"
        >
        <AudioLines class="h-4 w-4 shrink-0" />
        <span v-if="shown.audioSupported">Share this computer's sound too</span>
        <span v-else>Sharing computer sound isn't available on {{ platformName }} yet — the picture will be shared without it.</span>
      </label>

      <DialogFooter>
        <Button variant="outline" @click="cancel">Cancel</Button>
        <Button :disabled="!selected" @click="share">Share</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
