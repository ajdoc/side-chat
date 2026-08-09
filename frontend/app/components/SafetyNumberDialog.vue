<script setup lang="ts">
import { Loader2, ShieldCheck, X } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { safetyNumber } from '~/lib/crypto/identity'
import { fromBase64 } from '~/lib/crypto/primitives'

/**
 * Checking you're talking to who you think you are.
 *
 * Everything else in this feature defends against somebody who takes the database or watches
 * the wire. This defends against the server itself, and it is the only thing that does.
 *
 * The gap it closes: every device signs its prekeys, and clients refuse a bundle whose
 * signature doesn't check out — but a signature only proves a key belongs to whoever holds
 * the *identity* key, and if the server handed you an impostor's identity key the very first
 * time you spoke to somebody, every signature after that verifies perfectly. No amount of
 * cryptography inside the app can catch that, because the app has nothing genuine to compare
 * against.
 *
 * Two people reading the same number aloud — over the phone, or across a desk — compare
 * against something the server doesn't control. That is the whole mechanism, and it is why
 * the copy here tells people to use a different channel rather than paste it into the chat.
 *
 * A note on scope, because this screen should not overstate itself: the number covers one
 * *device* pair. Somebody with a laptop and a phone has two numbers, and a new device
 * legitimately changes one — which is exactly what a restored backup does. "It changed" means
 * "ask them", not "you are being attacked".
 */
const props = defineProps<{
  /** Who this conversation is with. */
  name: string
  /** Their device identity keys, base64, as published to the directory. */
  theirDevices: Array<{ device_id: string, identity_public: string }>
  /** Our own device identity key, base64. */
  ourIdentityPublic: string
}>()

const emit = defineEmits<{ close: [] }>()

const numbers = ref<Array<{ deviceId: string, number: string }>>([])
const loading = ref(true)

onMounted(async () => {
  try {
    numbers.value = await Promise.all(props.theirDevices.map(async device => ({
      deviceId: device.device_id,
      number: await safetyNumber(
        fromBase64(props.ourIdentityPublic),
        fromBase64(device.identity_public),
      ),
    })))
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[85vh] w-full max-w-md flex-col overflow-y-auto rounded-xl border bg-background p-4 shadow-lg">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="flex items-center gap-2 font-semibold">
          <ShieldCheck class="size-4" />
          Verify {{ name }}
        </h2>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>

      <p class="mb-3 text-xs text-muted-foreground">
        Read these numbers to each other out loud — on a call, or in person. If they match,
        nobody is sitting in the middle of this conversation.
        <span class="font-medium text-foreground">Don't send them in this chat</span>: if
        someone were intercepting it, they'd simply send back the number you expect.
      </p>

      <div v-if="loading" class="flex justify-center py-8">
        <Loader2 class="size-5 animate-spin text-muted-foreground" />
      </div>

      <div v-else-if="!numbers.length" class="rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
        {{ name }} hasn't set up an encrypted device yet, so there's nothing to compare.
      </div>

      <div v-else class="space-y-2">
        <!--
          One block per device, because the number is per device pair. Two blocks is not a
          problem to be hidden — it means they use a laptop and a phone, and both need
          checking.
        -->
        <div v-for="entry in numbers" :key="entry.deviceId" class="rounded-lg border p-3">
          <p v-if="numbers.length > 1" class="mb-1.5 text-xs text-muted-foreground">
            Device {{ entry.deviceId.slice(0, 8) }}
          </p>
          <p class="select-all font-mono text-sm leading-relaxed tracking-wide">{{ entry.number }}</p>
        </div>
      </div>

      <p class="mt-3 rounded-lg border bg-muted/40 p-2.5 text-xs text-muted-foreground">
        These change when someone reinstalls the app, adds a device, or restores from a
        backup — all of which are normal. If a number changes unexpectedly, it's worth asking
        them about it before sending anything sensitive.
      </p>

      <div class="mt-4 flex justify-end">
        <Button variant="ghost" @click="emit('close')">Close</Button>
      </div>
    </div>
  </div>
</template>
