<script setup lang="ts">
import { Settings } from 'lucide-vue-next'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * Voice & screen-share settings, behind a gear on the call bar.
 *
 * Everything in here is a *preference* — which mic, which speaker, how a share is encoded —
 * so it lives next to the call controls that are reachable from anywhere, not buried on the
 * call page you may have wandered away from. Each control writes straight through to
 * useVoice, which both remembers the choice and, if a call is live, applies it on the spot.
 */
const {
  inCall,
  isSharing,
  inputDevices,
  outputDevices,
  micId,
  speakerId,
  screenResolution,
  screenMode,
  canPickSpeaker,
  screenResolutions,
  refreshDevices,
  setMicDevice,
  setSpeaker,
  setScreenResolution,
  setScreenMode,
} = useVoice()

const open = ref(false)

// Labels only firm up once the site holds mic permission, and devices come and go — so pull
// a fresh list each time the panel opens rather than trusting whatever was there last.
watch(open, (isOpen) => {
  if (isOpen) void refreshDevices()
})

/** A device with no label yet (permission not granted, or just plugged) still needs a name. */
function deviceLabel(device: MediaDeviceInfo, index: number, kind: string) {
  return device.label || `${kind} ${index + 1}`
}

const modeOptions = [
  { value: 'auto', label: 'Automatic', hint: 'Detects text vs. video and adapts' },
  { value: 'detail', label: 'Text / Detail', hint: 'Sharpest for code and docs' },
  { value: 'motion', label: 'Video / Motion', hint: 'Smoothest for games and video' },
] as const
</script>

<template>
  <Dialog v-model:open="open">
    <button
      type="button"
      class="flex flex-1 items-center justify-center rounded p-1.5 text-muted-foreground transition hover:bg-muted"
      title="Voice & screen settings"
      @click="open = true"
    >
      <Settings class="h-4 w-4" />
    </button>

    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>Voice &amp; screen settings</DialogTitle>
        <DialogDescription>
          Choose your devices and how your screen share is encoded. Changes apply right away.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-4">
        <!-- Microphone -->
        <label class="block space-y-1">
          <span class="text-sm font-medium">Microphone</span>
          <select
            class="h-9 w-full rounded-md border bg-background px-2 text-sm"
            :value="micId ?? ''"
            @change="setMicDevice(($event.target as HTMLSelectElement).value)"
          >
            <option v-if="!inputDevices.length" value="">System default</option>
            <option
              v-for="(d, i) in inputDevices"
              :key="d.deviceId"
              :value="d.deviceId"
            >
              {{ deviceLabel(d, i, 'Microphone') }}
            </option>
          </select>
        </label>

        <!-- Speaker -->
        <label class="block space-y-1">
          <span class="text-sm font-medium">Speaker</span>
          <select
            v-if="canPickSpeaker"
            class="h-9 w-full rounded-md border bg-background px-2 text-sm"
            :value="speakerId ?? ''"
            @change="setSpeaker(($event.target as HTMLSelectElement).value)"
          >
            <option v-if="!outputDevices.length" value="">System default</option>
            <option
              v-for="(d, i) in outputDevices"
              :key="d.deviceId"
              :value="d.deviceId"
            >
              {{ deviceLabel(d, i, 'Speaker') }}
            </option>
          </select>
          <p v-else class="text-xs text-muted-foreground">
            Your browser plays the call through the system default output — it doesn't allow
            choosing a speaker here. (Chrome and Edge do.)
          </p>
        </label>

        <div class="border-t pt-4">
          <p class="mb-1 text-sm font-medium">Screen share</p>
          <p class="mb-3 text-xs text-muted-foreground">
            Lower resolution and the right mode keep your screen share smooth — and keep it
            from lagging the rest of your machine while you share.
          </p>

          <!-- Resolution -->
          <label class="block space-y-1">
            <span class="text-sm">Resolution</span>
            <select
              class="h-9 w-full rounded-md border bg-background px-2 text-sm"
              :value="screenResolution"
              @change="setScreenResolution(Number(($event.target as HTMLSelectElement).value) as typeof screenResolution)"
            >
              <option v-for="r in screenResolutions" :key="r" :value="r">
                {{ r }}p{{ r === 720 ? ' (recommended)' : '' }}
              </option>
            </select>
          </label>

          <!-- Content mode -->
          <label class="mt-3 block space-y-1">
            <span class="text-sm">Quality mode</span>
            <select
              class="h-9 w-full rounded-md border bg-background px-2 text-sm"
              :value="screenMode"
              @change="setScreenMode(($event.target as HTMLSelectElement).value as typeof screenMode)"
            >
              <option v-for="m in modeOptions" :key="m.value" :value="m.value">
                {{ m.label }} — {{ m.hint }}
              </option>
            </select>
          </label>

          <p v-if="isSharing" class="mt-2 text-xs text-muted-foreground">
            You're sharing now — resolution and mode changes take effect on this share.
          </p>
        </div>

        <p v-if="!inCall" class="text-xs text-muted-foreground">
          Not in a call. Your choices are saved and used the next time you join.
        </p>
      </div>
    </DialogContent>
  </Dialog>
</template>
