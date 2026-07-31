<script setup lang="ts">
import { KeyRound, Loader2, X } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'

/**
 * "What's the password?" — asked at the door, in the room.
 *
 * The counterpart to the key-holder list: that one lets in people the room's owner could name in
 * advance, and this one lets in whoever was told the phrase. Most private rooms work the second
 * way, with the words in a pinned message.
 *
 * ## Why it doesn't say whether it worked
 *
 * There is no success state here beyond closing. Getting it right adds the key server-side and
 * broadcasts the map, and the *door opening in front of you* is the confirmation — a dialog
 * saying "correct" would sit on top of the thing it was describing. Only the failure needs
 * words, and the server's are used verbatim so a throttled flood of guesses reads as what it is
 * rather than as a wrong password.
 */
const props = defineProps<{ doorId: string, channelId: number, roomName?: string | null }>()
const emit = defineEmits<{ close: [] }>()

const { enter } = useSpaceLocks(props.channelId)

const password = ref('')
const busy = ref(false)
const error = ref('')

const input = ref<HTMLInputElement | null>(null)
onMounted(() => input.value?.focus())

async function submit() {
  if (busy.value || !password.value) return

  busy.value = true
  error.value = ''
  try {
    await enter(props.doorId, password.value)
    emit('close')
  } catch (e: any) {
    // 422 is a wrong phrase; 429 is too many guesses. Both arrive with a sentence worth showing.
    error.value = e?.data?.errors?.password?.[0] ?? e?.data?.message ?? 'That did not work.'
    password.value = ''
    input.value?.focus()
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" @click.self="emit('close')">
    <form
      class="w-full max-w-sm overflow-hidden rounded-lg border bg-background shadow-xl"
      @submit.prevent="submit"
    >
      <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
        <span class="flex items-center gap-2 font-semibold">
          <KeyRound class="h-4 w-4" /> {{ roomName ? `Password for ${roomName}` : 'Door password' }}
        </span>
        <button type="button" class="rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </header>

      <div class="space-y-3 p-4">
        <p class="text-xs text-muted-foreground">
          This door opens for anyone who knows the password. Get it right once and it stays open
          for you.
        </p>

        <input
          ref="input"
          v-model="password"
          type="password"
          autocomplete="off"
          placeholder="Password"
          class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
        >

        <p v-if="error" class="rounded border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {{ error }}
        </p>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" :disabled="busy" @click="emit('close')">Cancel</Button>
          <Button type="submit" class="gap-1.5" :disabled="busy || !password">
            <Loader2 v-if="busy" class="h-3.5 w-3.5 animate-spin" />
            Open
          </Button>
        </div>
      </div>
    </form>
  </div>
</template>
