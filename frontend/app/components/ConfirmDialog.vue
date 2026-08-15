<script setup lang="ts">
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'
import { Button } from '~/components/ui/button'

/**
 * "Are you sure?", in the app's own clothes.
 *
 * ## Why this exists rather than `window.confirm`
 *
 * A native confirm is not merely unstyled — it is a *different application* interrupting this
 * one. It ignores the theme, it cannot say anything the browser doesn't let it say, its buttons
 * are named by the operating system rather than by the thing being done, and on some platforms
 * it offers to suppress every later dialog from the page, which silently turns the next
 * irreversible action into a click. It also blocks the main thread, so the room behind it stops
 * rendering and everybody's avatar freezes mid-step while somebody reads a sentence.
 *
 * ## Why it wraps the primitives instead of each caller using them
 *
 * The AlertDialog parts are eight components and about twenty lines of markup, and the pattern
 * was already copied by hand six times in `layouts/app.vue`. Every copy is a chance to get the
 * one subtle part wrong: the confirm button must be a plain {@link Button}, **not** an
 * `AlertDialogAction`, because the Action closes the dialog on click before the handler runs —
 * which loses the "Deleting…" state and, if the request fails, closes the dialog over the error
 * it was about to show.
 *
 * So the confirm button is ours, the cancel is theirs, and closing is something the caller does
 * by resolving.
 *
 * ## How a caller uses it
 *
 * `v-model:open`, and `@confirm` doing the work. The dialog stays open while `busy` is true so a
 * slow delete can say so, and `error` renders in place rather than replacing the question.
 */
withDefaults(defineProps<{
  open: boolean
  title: string
  /** The consequence, in a sentence. Say what is lost and whether it comes back. */
  description?: string
  /** The verb on the confirm button — "Delete channel", not "OK". */
  confirmLabel?: string
  /** Shown while the handler runs. */
  busyLabel?: string
  cancelLabel?: string
  /** Destructive by default: nearly everything that needs confirming is. */
  variant?: 'destructive' | 'default'
  busy?: boolean
  error?: string
}>(), {
  confirmLabel: 'Confirm',
  busyLabel: 'Working…',
  cancelLabel: 'Cancel',
  variant: 'destructive',
  busy: false,
})

defineEmits<{ 'update:open': [value: boolean], 'confirm': [] }>()
</script>

<template>
  <AlertDialog :open="open" @update:open="$emit('update:open', $event)">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>{{ title }}</AlertDialogTitle>
        <AlertDialogDescription v-if="description">
          {{ description }}
        </AlertDialogDescription>
      </AlertDialogHeader>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

      <AlertDialogFooter>
        <AlertDialogCancel :disabled="busy">{{ cancelLabel }}</AlertDialogCancel>
        <!-- A plain Button on purpose — see the note above about AlertDialogAction. -->
        <Button :variant="variant" :disabled="busy" @click="$emit('confirm')">
          {{ busy ? busyLabel : confirmLabel }}
        </Button>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
