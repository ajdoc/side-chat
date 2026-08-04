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
  noiseSuppression,
  noiseSuppressionOptions,
  setNoiseSuppression,
  normalizeVolume,
  setNormalizeVolume,
  peers,
  spatialAudio,
  canSpatialise,
  roomPlacesPeople,
  spatialWidth,
  spatialTurnsWithYou,
  setSpatialAudio,
  setSpatialWidth,
  setSpatialTurnsWithYou,
  setPeerPlacement,
  unplacePeer,
  resetPlacements,
  screenResolution,
  screenMode,
  canPickSpeaker,
  pushToTalk,
  setPushToTalk,
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

/**
 * The device pickers go through `v-model`, not `:value` + `@change`, and that is load-bearing.
 *
 * The options arrive *after* the first render — refreshDevices is async — and a plain `:value`
 * is only written to the element when the bound value itself changes. It doesn't, so the
 * remembered device would be set against an empty list, dropped, and the browser would show
 * whichever option happened to land first. `v-model` re-applies the selection on every
 * re-render of the select, so the list filling in re-selects the device you actually chose.
 *
 * Writes go straight through to useVoice (which saves and, mid-call, switches on the spot);
 * an empty value is the "System default" row, which the setters have nothing to do with.
 */
const selectedMic = computed({
  get: () => micId.value ?? '',
  set: (deviceId: string) => { if (deviceId) void setMicDevice(deviceId) },
})

const selectedSpeaker = computed({
  get: () => speakerId.value ?? '',
  set: (deviceId: string) => { if (deviceId) void setSpeaker(deviceId) },
})

const selectedSuppression = computed({
  get: () => noiseSuppression.value,
  set: (value: typeof noiseSuppression.value) => { void setNoiseSuppression(value) },
})

const selectedResolution = computed({
  get: () => screenResolution.value,
  set: (value: typeof screenResolution.value) => setScreenResolution(value),
})

const selectedMode = computed({
  get: () => screenMode.value,
  set: (value: typeof screenMode.value) => setScreenMode(value),
})

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

    <!--
      Capped to the viewport with the *body* doing the scrolling, not the page.

      This panel has grown a long way past the two device pickers it started as, and an
      unconstrained dialog simply runs off the bottom of a short window — taking the close
      button, which is positioned against the panel's own top corner, off the top with it.
      Two grid rows (header, then a scrolling body) keep the title and that button on screen
      however much ends up in here.
    -->
    <DialogContent class="max-h-[85dvh] max-w-md grid-rows-[auto_minmax(0,1fr)]">
      <DialogHeader>
        <DialogTitle>Voice &amp; screen settings</DialogTitle>
        <DialogDescription>
          Choose your devices and how your screen share is encoded. Changes apply right away.
        </DialogDescription>
      </DialogHeader>

      <!-- Negative margin + padding so the scrollbar sits at the panel edge rather than
           inset, and focus rings on the controls aren't clipped by the overflow. -->
      <div class="-mx-6 space-y-4 overflow-y-auto px-6 py-1">
        <!-- Microphone -->
        <label class="block space-y-1">
          <span class="text-sm font-medium">Microphone</span>
          <select
            v-model="selectedMic"
            class="h-9 w-full rounded-md border bg-background px-2 text-sm"
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
            v-model="selectedSpeaker"
            class="h-9 w-full rounded-md border bg-background px-2 text-sm"
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

        <!-- Noise suppression -->
        <label class="block space-y-1">
          <span class="text-sm font-medium">Noise suppression</span>
          <select
            v-model="selectedSuppression"
            class="h-9 w-full rounded-md border bg-background px-2 text-sm"
          >
            <option v-for="o in noiseSuppressionOptions" :key="o.value" :value="o.value">
              {{ o.label }} — {{ o.hint }}
            </option>
          </select>
          <span v-if="noiseSuppression === 'high'" class="block text-xs text-muted-foreground">
            Your mic is held quiet between words, so fans, keyboards and the rest of the room
            don't ride under your voice. Switch to Standard if you're playing or singing —
            this is tuned for speech and will chew on a held note.
          </span>
        </label>

        <!-- Automatic volume -->
        <label
          v-if="noiseSuppression !== 'off'"
          class="flex cursor-pointer items-start gap-2.5"
        >
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-primary"
            :checked="normalizeVolume"
            @change="setNormalizeVolume(($event.target as HTMLInputElement).checked)"
          >
          <span class="space-y-0.5">
            <span class="block text-sm font-medium">Automatic volume</span>
            <span class="block text-xs text-muted-foreground">
              Brings your level up or down to match everyone else's, so sitting back from your
              mic doesn't make you the person nobody can hear. Adjusts slowly and only while
              you're actually talking.
            </span>
          </span>
        </label>

        <!-- Spatial audio -->
        <div v-if="canSpatialise" class="space-y-3">
          <label class="flex cursor-pointer items-start gap-2.5">
            <input
              type="checkbox"
              class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-primary"
              :checked="spatialAudio"
              @change="setSpatialAudio(($event.target as HTMLInputElement).checked)"
            >
            <span class="space-y-0.5">
              <span class="block text-sm font-medium">Spatial audio</span>
              <span class="block text-xs text-muted-foreground">
                Give each person a place in the room instead of stacking every voice in the
                centre. Two people talking at once stop fighting for the same spot — which is
                how you follow one of them. Best with headphones.
              </span>
            </span>
          </label>

          <!-- How strong the effect is. Applies wherever the placements come from. -->
          <label v-if="spatialAudio" class="block space-y-1">
            <span class="flex items-center justify-between text-sm">
              <span>Effect strength</span>
              <span class="text-xs text-muted-foreground">{{ Math.round(spatialWidth * 100) }}%</span>
            </span>
            <input
              type="range"
              min="0"
              max="100"
              step="5"
              class="w-full accent-primary"
              :value="Math.round(spatialWidth * 100)"
              @input="setSpatialWidth(Number(($event.target as HTMLInputElement).value) / 100)"
            >
            <span class="block text-xs text-muted-foreground">
              How far apart voices sit. Turn it down if the full effect is distracting or
              you're on speakers rather than headphones — at 0% everyone is back in the centre.
            </span>
          </label>

          <template v-if="spatialAudio && roomPlacesPeople">
            <p class="text-xs text-muted-foreground">
              You're in a Side Space, so the room places people by default: they sound like
              they're standing where they're standing. Pin anyone you'd rather hear from a
              fixed spot.
            </p>

            <SpatialAudioMap
              v-if="peers.length"
              :peers="peers"
              room-mode
              @place="(id, placement) => setPeerPlacement(id, placement)"
              @unplace="unplacePeer"
              @reset="resetPlacements"
            />

            <label class="flex cursor-pointer items-start gap-2.5">
              <input
                type="checkbox"
                class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-primary"
                :checked="spatialTurnsWithYou"
                @change="setSpatialTurnsWithYou(($event.target as HTMLInputElement).checked)"
              >
              <span class="space-y-0.5">
                <span class="block text-sm font-medium">Turn with my character</span>
                <span class="block text-xs text-muted-foreground">
                  Off, up the screen is always "ahead" — what you hear matches what you're
                  looking at. On, turning to face someone brings them round to the front, which
                  is more like being there and less like reading a map.
                </span>
              </span>
            </label>
          </template>

          <SpatialAudioMap
            v-else-if="spatialAudio && peers.length"
            :peers="peers"
            @place="(id, placement) => setPeerPlacement(id, placement)"
            @unplace="unplacePeer"
            @reset="resetPlacements"
          />

          <p v-else-if="spatialAudio" class="text-xs text-muted-foreground">
            Nobody else is here yet. Once somebody joins you can drag them around the room.
          </p>
        </div>

        <!-- Push to talk -->
        <label class="flex cursor-pointer items-start gap-2.5">
          <input
            type="checkbox"
            class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-primary"
            :checked="pushToTalk"
            @change="setPushToTalk(($event.target as HTMLInputElement).checked)"
          >
          <span class="space-y-0.5">
            <span class="block text-sm font-medium">Push to talk</span>
            <span class="block text-xs text-muted-foreground">
              Your mic stays off until you hold <strong class="font-medium">Space</strong> — except
              while you're typing, where Space is still a space. The mic button mutes you outright,
              key or no key.
            </span>
          </span>
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
              v-model="selectedResolution"
              class="h-9 w-full rounded-md border bg-background px-2 text-sm"
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
              v-model="selectedMode"
              class="h-9 w-full rounded-md border bg-background px-2 text-sm"
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
