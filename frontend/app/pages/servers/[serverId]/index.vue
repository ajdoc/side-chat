<script setup lang="ts">
import { Button } from '~/components/ui/button'

definePageMeta({ middleware: 'auth', layout: 'app' })

const route = useRoute()
const { server, channels } = useServer()

const serverId = computed(() => Number(route.params.serverId))
const firstText = computed(() => channels.value.find(c => c.type === 'text'))

// Once the server (loaded by the layout) has a text channel, jump straight into it.
watchEffect(() => {
  if (server.value?.id === serverId.value && firstText.value) {
    navigateTo(`/servers/${serverId.value}/channels/${firstText.value.id}`)
  }
})
</script>

<template>
  <div class="grid flex-1 place-items-center p-6 text-center">
    <div v-if="server?.id === serverId && !channels.length" class="max-w-sm space-y-4">
      <h2 class="text-2xl font-semibold">Create your first channel</h2>
      <p class="text-muted-foreground">
        Channels are where conversations happen. Start with a text channel for your community.
      </p>
      <Button as-child>
        <NuxtLink :to="`/servers/${serverId}/channels/new`">Create a channel</NuxtLink>
      </Button>
    </div>
    <p v-else class="text-muted-foreground">Select a channel to start chatting.</p>
  </div>
</template>
