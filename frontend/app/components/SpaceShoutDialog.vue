<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * The line you leave hanging over your head in a Side Space.
 *
 * A shout is not a message: it doesn't arrive, it doesn't scroll away, and nobody is notified
 * of it. It's a label on your sprite — "Worship", "brb", "🎧 focusing" — which is why it lives
 * on your user record beside your avatar rather than in the channel's timeline, and why it
 * stays up until you take it down.
 *
 * ## Turning it off
 *
 * An explicit button rather than only "clear the box and save", because those are the same
 * request to the server but not the same thought in the reader's head: somebody who wants the
 * bubble gone is looking for a way to stop, not for a text field to empty. Both paths end in
 * the same `shout: null`.
 */
const emit = defineEmits<{ close: [], saved: [string | null] }>()

const { shout: savedShout, save } = useSpaceAppearance()

/** The most a bubble can hold — matches the column and the server's rule. */
const MAX = 40

const draft = ref(savedShout.value ?? '')
const saving = ref(false)
const error = ref('')

/** A few that people actually reach for, so the common case is one click and done. */
const SUGGESTIONS = ['🙌 Worship', '💤 AFK', '🎧 Focusing', '👋 Say hi', '🍜 Lunch', '🎮 Down to play']

const clean = computed(() => draft.value.replace(/\s+/g, ' ').trim())

async function commit(value: string | null) {
  if (saving.value) return

  saving.value = true
  error.value = ''

  try {
    await save({ shout: value })
    emit('saved', value)
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not save that.'
  }
  finally {
    saving.value = false
  }
}

/** Save what's in the box — or take the bubble down, if it's been emptied. */
function submit() {
  void commit(clean.value || null)
}
</script>

<template>
  <Dialog :open="true" @update:open="!$event && emit('close')">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Shout something</DialogTitle>
        <DialogDescription>
          A few words and an emoji, in a bubble over your head. Everyone in the room sees it, and
          it stays up until you turn it off.
        </DialogDescription>
      </DialogHeader>

      <form class="space-y-3" @submit.prevent="submit">
        <div class="flex items-center gap-2">
          <Input
            v-model="draft"
            placeholder="Worship 🙌"
            :maxlength="MAX"
            autofocus
            class="flex-1"
          />
          <!-- The picker owns its own trigger, and appends rather than replaces: a shout is
               usually a word *and* an emoji, not one or the other. -->
          <EmojiPicker
            compact
            @select="draft = (draft + $event).slice(0, MAX)"
          />
        </div>

        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="s in SUGGESTIONS"
            :key="s"
            type="button"
            class="rounded-full border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            @click="draft = s"
          >
            {{ s }}
          </button>
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <div class="flex items-center justify-end gap-2">
          <!-- Only worth offering when there's something up there to take down. -->
          <Button
            v-if="savedShout"
            type="button"
            variant="ghost"
            class="mr-auto text-muted-foreground"
            :disabled="saving"
            @click="commit(null)"
          >
            Turn off
          </Button>
          <Button type="button" variant="outline" :disabled="saving" @click="emit('close')">
            Cancel
          </Button>
          <Button type="submit" :disabled="saving">
            {{ saving ? 'Saving…' : clean ? 'Shout it' : 'Turn off' }}
          </Button>
        </div>
      </form>
    </DialogContent>
  </Dialog>
</template>
