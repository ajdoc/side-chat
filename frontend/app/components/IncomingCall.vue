<script setup lang="ts">
import { Phone, PhoneOff, Users } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'

/**
 * The ringing phone.
 *
 * Mounted in the layout, not in a page, and that's the entire point: a call has to reach
 * you wherever you are — in another server, in a different chat, in a channel you've had
 * open for an hour — and it has to reach you in a conversation you may never once have
 * opened. That's what the `user.{id}` stream is for (see useUserStream), and this is what
 * comes out the other end.
 */
const { incoming, joining, accept, decline } = useCall()
const { user } = useAuth()

const title = computed(() =>
  incoming.value ? conversationTitle(incoming.value.conversation, user.value) : '',
)
const isGroup = computed(() => incoming.value?.conversation.type === 'group')
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="translate-y-2 opacity-0"
    leave-active-class="transition duration-150 ease-in"
    leave-to-class="translate-y-2 opacity-0"
  >
    <div
      v-if="incoming"
      class="fixed bottom-6 right-6 z-50 w-80 overflow-hidden rounded-xl border bg-popover shadow-2xl"
      role="alertdialog"
      aria-label="Incoming call"
    >
      <div class="flex items-center gap-3 p-4">
        <span class="relative grid h-12 w-12 shrink-0 place-items-center rounded-full bg-secondary text-sm font-semibold text-secondary-foreground">
          <img
            v-if="incoming.caller.avatar"
            :src="incoming.caller.avatar"
            :alt="incoming.caller.name"
            class="h-full w-full rounded-full object-cover"
          >
          <span v-else>{{ initialsOf(incoming.caller.name) }}</span>
          <!-- The visual half of the ringing, for anyone who has their sound off. -->
          <span class="absolute inset-0 animate-ping rounded-full border-2 border-primary opacity-60" />
        </span>

        <div class="min-w-0">
          <p class="truncate text-sm font-semibold">{{ incoming.caller.name }}</p>
          <p class="flex items-center gap-1 truncate text-xs text-muted-foreground">
            <Users v-if="isGroup" class="h-3 w-3 shrink-0" />
            <!-- In a group, who is calling and where are two different facts. In a DM
                 they're the same one, so saying it twice would just be noise. -->
            {{ isGroup ? `is calling ${title}` : 'is calling you' }}
          </p>
        </div>
      </div>

      <div class="flex gap-2 border-t p-3">
        <Button
          variant="outline"
          class="flex-1 gap-2 text-destructive hover:text-destructive"
          :disabled="joining"
          @click="decline"
        >
          <PhoneOff class="h-4 w-4" /> Decline
        </Button>
        <Button class="flex-1 gap-2" :disabled="joining" @click="accept">
          <Phone class="h-4 w-4" /> {{ joining ? 'Joining…' : 'Answer' }}
        </Button>
      </div>
    </div>
  </Transition>
</template>
