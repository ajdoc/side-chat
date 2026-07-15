<script setup lang="ts">
import { MessageSquarePlus } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'

definePageMeta({ middleware: 'auth', layout: 'app' })

/** The Chats section with nothing open in it yet. */
const { conversations, fetchConversations } = useConversations()

const showNewChat = ref(false)

onMounted(() => fetchConversations())

useHead({ title: 'Chats' })
</script>

<template>
  <div class="grid flex-1 place-items-center p-6">
    <div class="max-w-sm space-y-4 text-center">
      <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground">
        <MessageSquarePlus class="h-6 w-6" />
      </span>

      <div class="space-y-1">
        <h1 class="text-lg font-semibold">
          {{ conversations.length ? 'Pick a chat' : 'No chats yet' }}
        </h1>
        <p class="text-sm text-muted-foreground">
          {{ conversations.length
            ? 'Choose a conversation from the sidebar, or start a new one.'
            : 'Message anyone you share a server with — one on one, or in a group. You can call them from here too.' }}
        </p>
      </div>

      <Button class="gap-2" @click="showNewChat = true">
        <MessageSquarePlus class="h-4 w-4" /> New chat
      </Button>
    </div>

    <NewChatDialog v-model:open="showNewChat" />
  </div>
</template>
